<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\DoctorAudit;

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
 * The engine resolves audit class-strings through an injected resolver (the container `make`), so it stays
 * container-agnostic and unit-testable.
 */
class ConformanceSweep
{
    /** @param callable(class-string): object $resolver resolves an audit class-string to an instance */
    public function __construct(
        private $resolver,
    ) {}

    public function run(ConformanceManifest $manifest): ConformanceReport
    {
        $seen = [];
        $results = [];

        foreach ($manifest->registrations() as $registration) {
            if (isset($seen[$registration->audit])) {
                continue; // dedupe by audit class-string — the same class runs once (decision 6)
            }
            $seen[$registration->audit] = true;

            $audit = ($this->resolver)($registration->audit);

            $results[] = new ConformanceAuditResult(
                $registration->package,
                $registration->audit,
                $registration->gate,
                $this->collect($audit),
            );
        }

        return new ConformanceReport($results);
    }

    /** @return list<FixableFinding> */
    private function collect(object $audit): array
    {
        if ($audit instanceof SuggestsOperations) {
            return $audit->suggestOperations();
        }

        if ($audit instanceof DoctorAudit) {
            return array_map(fn ($finding) => new FixableFinding($finding, null), $audit->run());
        }

        return []; // an unknown shape registered in the manifest contributes nothing to the sweep
    }
}
