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

    /** No stack reported violations — the gate-4 pass condition (skips do not fail; they are honest). */
    public function clean(): bool
    {
        foreach ($this->results as $result) {
            if ($result->hasViolations()) {
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
            ],
            'results' => array_map(fn (LintResult $r) => $r->toArray(), $this->results),
        ];
    }
}
