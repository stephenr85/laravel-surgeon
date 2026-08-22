<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Throwable;

/**
 * The conformance-driven audit path (ticket 07, decision 5) — the engine behind `surgeon:audit`. It
 * reuses the host's doctor manifest ({@see ConformanceManifest}) as its discovery substrate, runs each
 * registered audit, and collects the {@see FixableFinding} pairs: diagnosis + the deterministic operation
 * (if any) that fixes it.
 *
 * Two invariants from ticket 07:
 *  - **Dedupe by audit class-string** (decision 6): many packages require the same foundation, so the
 *    same audit surfaces N times in the merged manifest — it runs exactly once.
 *  - **Two channels, one vocabulary** (decisions 3–5): an audit implementing {@see SuggestsOperations}
 *    exposes the paired fix channel; a plain {@see DoctorAudit} exposes only findings, wrapped with a
 *    null suggestion. An audit implementing both prefers the richer paired channel (which already carries
 *    its findings) — never double-counted.
 *
 * **Two inputs, one report (ticket 15).** Beyond the host-manifest registrations, the sweep also runs
 * surgeon's OWN built-in audits ({@see BuiltInAudits} — b1/b2), passed as a
 * plain list of {@see SuggestsOperations} instances. They are surgeon-native (generic package-graph
 * hygiene, not a host concept), so they don't belong in the host's doctor manifest; they ride this
 * parallel channel, always present, scoped to the running host's app root. Built-ins are reported first
 * (their class-string joins the dedupe set, so a host that also registered one wouldn't double-run it).
 *
 * The engine resolves audit class-strings through an injected resolver (the container `make`), so it stays
 * container-agnostic and unit-testable.
 *
 * **A throwing audit is a finding, never the end of the sweep (ticket 56).** An audit is a diagnostic
 * that runs against whatever state a root happens to be in, so *an audit meeting an unmet precondition
 * is a normal event* — a query against a column a lagging database has not got, a file the root never
 * created. Before this, one such audit killed `surgeon:audit` outright and took every other audit's
 * already-collected report down with it. The roots whose state is worst are the roots most likely to
 * carry a throwing audit, and they are exactly the roots whose report is worth reading. So the sweep
 * catches around both phases — {@see self::resolve()} and {@see self::collect()} — turns the throw into
 * a finding naming the audit and the exception, and carries on.
 *
 * Two rulings shape that finding:
 *  - **Never silently.** A suppressed exception with no finding is strictly worse than the crash, because
 *    the report would then be green *because* an audit failed.
 *  - **Warn advisory, Fail on a gate.** An audit that could not run has not found anything, so it must
 *    not redden a gate on the strength of not knowing — but a GATE registration's contract is that its
 *    subject was *verified*, and a gate that degrades to "no findings" is how a gate stops gating. So a
 *    gate audit that cannot run reports Fail: unverified is not passed. The severity is the sweep's, not
 *    the audit's, which is why it is decided here from {@see ConformanceAuditResult::$gate} rather than
 *    by whatever threw.
 */
class ConformanceSweep
{
    /** The check-string a throwing audit reports under; `.resolve` distinguishes the resolution phase. */
    public const CHECK_ERRORED = 'audit-errored';

    /** How much of an exception message survives into a finding detail — a QueryException carries whole SQL. */
    private const MESSAGE_LIMIT = 300;

    /** @param callable(class-string): object $resolver resolves an audit class-string to an instance */
    public function __construct(
        private $resolver,
    ) {}

    /**
     * @param  list<SuggestsOperations>  $builtIn  surgeon's own built-in audits (ticket 15), run alongside
     *                                             the host-manifest registrations and reported first
     */
    public function run(ConformanceManifest $manifest, array $builtIn = []): ConformanceReport
    {
        $seen = [];
        $results = [];

        // Surgeon's OWN audits first (ticket 15) — a parallel, always-present channel scoped to the host.
        // Their class-string joins the dedupe set so a host that also registered one won't double-run it.
        foreach ($builtIn as $audit) {
            $class = $audit::class;
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;

            $results[] = new ConformanceAuditResult(
                'rushing/laravel-surgeon',
                $class,
                false, // advisory, not a gate — a promotion/duplicate nomination never reddens the exit code
                $this->collect($audit, $class, gate: false),
            );
        }

        foreach ($manifest->registrations() as $registration) {
            if (isset($seen[$registration->audit])) {
                continue; // dedupe by audit class-string — the same class runs once (decision 6)
            }
            $seen[$registration->audit] = true;

            try {
                $audit = ($this->resolver)($registration->audit);
            } catch (Throwable $e) {
                // A registration naming a class that no longer exists (or whose constructor throws) fails
                // before the audit runs at all — the same event one phase earlier, reported the same way.
                $results[] = new ConformanceAuditResult(
                    $registration->package,
                    $registration->audit,
                    $registration->gate,
                    [$this->errored($registration->audit, $registration->gate, $e, resolving: true)],
                );

                continue;
            }

            $results[] = new ConformanceAuditResult(
                $registration->package,
                $registration->audit,
                $registration->gate,
                $this->collect($audit, $registration->audit, $registration->gate),
            );
        }

        return new ConformanceReport($results);
    }

    /**
     * @param  class-string  $class  the registered class-string, named in the finding if the audit throws
     * @return list<FixableFinding>
     */
    private function collect(object $audit, string $class, bool $gate): array
    {
        try {
            if ($audit instanceof SuggestsOperations) {
                return $audit->suggestOperations();
            }

            if ($audit instanceof DoctorAudit) {
                return array_map(fn ($finding) => new FixableFinding($finding, null), $audit->run());
            }
        } catch (Throwable $e) {
            return [$this->errored($class, $gate, $e, resolving: false)];
        }

        return []; // an unknown shape registered in the manifest contributes nothing to the sweep
    }

    /**
     * The finding an audit that could not report becomes. Carries the audit, the exception class, its
     * first message line and origin — enough to route the repair to whoever owns the audit without the
     * operator re-running anything.
     */
    private function errored(string $class, bool $gate, Throwable $e, bool $resolving): FixableFinding
    {
        $check = self::CHECK_ERRORED.($resolving ? '.resolve' : '');

        $detail = $class.($resolving ? ' could not be resolved' : ' threw while running')
            .' — '.$e::class.': '.$this->firstLine($e->getMessage())
            .' ('.basename($e->getFile()).':'.$e->getLine().'). '
            .($gate
                ? 'A GATE audit that could not run has not verified its subject, so the sweep reports Fail: unverified is not passed.'
                : 'The rest of the sweep completed; this audit contributed no findings, which is not the same as finding nothing.');

        return new FixableFinding(
            $gate ? Finding::fail($check, $detail) : Finding::warn($check, $detail),
            null,
        );
    }

    /** One line, bounded — a QueryException's message carries whole SQL and would swamp the report. */
    private function firstLine(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return '(no message)'; // an Error thrown bare still has to name something readable
        }

        $line = trim((string) strtok($message, "\r\n"));

        return strlen($line) > self::MESSAGE_LIMIT
            ? substr($line, 0, self::MESSAGE_LIMIT).'…'
            : $line;
    }
}
