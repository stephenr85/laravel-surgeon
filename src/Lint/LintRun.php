<?php

namespace Rushing\Surgeon\Lint;

/**
 * The aggregate outcome of a {@see LintOrchestrator} pass across a set of roots × stacks (ticket 12) — the
 * batch of {@see LintResult}s plus the roll-up verdict both entry points read: `surgeon:lint` renders it,
 * and the gate stage-4 asks {@see clean()} to decide pass/fail.
 *
 * Mode-aware: a **check** run is `clean()` only when nothing has violations (the gate mode — any drift
 * fails). A **fix** run reports what it reformatted ({@see fixedCount()}) and what non-deterministic
 * residue it left ({@see violations()} with `fixable === false` — the advisory tail an agent handles).
 *
 * ⚠️ **`clean()` also refuses an UNRUNNABLE result** (beam-facade 163). It used to key on
 * `hasViolations()` alone, so a batch whose every stack crashed satisfied it and `surgeon:lint` printed
 * `All applicable stacks clean.` and exited 0. A stack that could not execute has not found nothing — it
 * has found nothing OUT. A genuine skip still passes, because nothing was asked of it.
 *
 * The `--root=all-overlay` mode is why this matters past one root: {@see LintOrchestrator::run()}
 * flattens every root × every stack into ONE `LintRun`, and this single boolean is the whole verdict for
 * the batch — so one crashed stack among N roots used to disappear under an aggregate clean line while
 * its SKIP scrolled past above.
 */
class LintRun
{
    /**
     * @param  string  $mode  `check` or `fix`
     * @param  list<LintResult>  $results
     */
    public function __construct(
        public string $mode,
        public array $results = [],
    ) {}

    /**
     * The gate-4 pass condition: no stack reported violations, AND every stack that applied actually
     * executed. A genuine skip does not fail (nothing was asked of it); an unrunnable does.
     */
    public function clean(): bool
    {
        foreach ($this->results as $result) {
            if ($result->hasViolations() || $result->couldNotRun()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<LintResult> the results that reported drift. */
    public function violations(): array
    {
        return array_values(array_filter($this->results, fn (LintResult $r) => $r->hasViolations()));
    }

    /** @return list<LintResult> the skipped (not-applicable / binary-absent) stacks — reported honestly. */
    public function skipped(): array
    {
        return array_values(array_filter($this->results, fn (LintResult $r) => $r->isSkipped()));
    }

    /** @return list<LintResult> the stacks that applied and could not execute — nothing was checked. */
    public function unrunnable(): array
    {
        return array_values(array_filter($this->results, fn (LintResult $r) => $r->couldNotRun()));
    }

    /** @return list<LintResult> the stacks that actually produced a verdict. */
    public function ran(): array
    {
        return array_values(array_filter($this->results, fn (LintResult $r) => $r->ran()));
    }

    /**
     * `N of M stacks ran` — rendered unconditionally by every surface, so the honest case is legible
     * rather than only the broken one. M counts every stack that APPLIED; a not-applicable stack never
     * produced a result and is in neither number.
     */
    public function ranSummary(): string
    {
        $applied = count($this->results) - count($this->skipped());

        return count($this->ran()).' of '.$applied.' applicable stack(s) ran';
    }

    /** Files reformatted across a fix run (the retained count on each fixed result). */
    public function fixedCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->fixed) {
                $count += max($result->violations, 0);
            }
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'clean' => $this->clean(),
            'counts' => [
                'results' => count($this->results),
                'violations' => count($this->violations()),
                'skipped' => count($this->skipped()),
                'unrunnable' => count($this->unrunnable()),
                'ran' => count($this->ran()),
            ],
            'results' => array_map(fn (LintResult $r) => $r->toArray(), $this->results),
        ];
    }
}
