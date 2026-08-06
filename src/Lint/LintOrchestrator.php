<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Surgeon\Rewrite\DeterministicGate;

/**
 * The shared engine both lint entry points run through (ticket 12, ticket 08 decision 4): given a set of
 * roots, discover which {@see LintStack}s apply (sniff-with-override via the {@see LintStackRegistry}) and
 * run them in **check** or **fix** mode, aggregating into a {@see LintRun}.
 *
 * **One orchestrator, two surfaces:**
 *  - **`surgeon:lint` (+ `--fix`)** — the standalone operation, scoped to `--root`/overlay, no preceding write.
 *  - **stage 4 of ticket 03's deterministic post-op gate** — {@see DeterministicGate}
 *    calls {@see check()} scoped to the *touched* roots after a `move`/`canonicalize`, so lint stays fast
 *    and never silently reformats inside a write-op.
 *
 * **Check-vs-fix split (ticket 08 decision 4)** is the whole reason `check()` and `fix()` are distinct
 * entry methods, not a boolean flag callers can fumble: the gate can only ever reach `check()`. A formatter
 * mutating the tree inside a `move` would blur what the *write-op* changed vs what the formatter changed
 * (poison for the touch-manifest review story) — so the gate checks, and only the explicit
 * `surgeon:lint --fix` write-op reformats (with its own touch-manifest).
 *
 * The engine is stack-agnostic: it iterates roots and asks the registry which stacks apply, never naming
 * PHP or JS. The subprocess boundary is the injected {@see StackRunner} ({@see ProcessStackRunner} by
 * default), so orchestration is unit-testable with a fake runner — pure logic here, real process at the seam.
 */
class LintOrchestrator
{
    private LintStackRegistry $registry;

    private StackRunner $runner;

    public function __construct(?LintStackRegistry $registry = null, ?StackRunner $runner = null)
    {
        $this->registry = $registry ?? LintStackRegistry::withDefaults();
        $this->runner = $runner ?? new ProcessStackRunner;
    }

    /**
     * Check every applicable stack across the roots (mutating nothing) — the gate mode.
     *
     * @param  list<string>  $roots
     */
    public function check(array $roots): LintRun
    {
        return $this->run($roots, fix: false);
    }

    /**
     * Deterministically reformat across the roots — the `surgeon:lint --fix` write-op mode.
     *
     * @param  list<string>  $roots
     */
    public function fix(array $roots): LintRun
    {
        return $this->run($roots, fix: true);
    }

    /**
     * Which stacks the orchestrator would run per root (discovery preview, no subprocess) — powers the
     * dry-run/preview and the `surgeon:lint` "here's what applies" report.
     *
     * @param  list<string>  $roots
     * @return array<string, list<string>> root => stack names
     */
    public function discover(array $roots): array
    {
        $map = [];
        foreach ($this->uniqueRoots($roots) as $root) {
            $map[$root] = array_map(fn (LintStack $s) => $s->name(), $this->registry->stacksFor($root));
        }

        return $map;
    }

    /** @param  list<string>  $roots */
    private function run(array $roots, bool $fix): LintRun
    {
        $results = [];
        foreach ($this->uniqueRoots($roots) as $root) {
            foreach ($this->registry->stacksFor($root) as $stack) {
                $results[] = $fix
                    ? $stack->fix($root, $this->runner)
                    : $stack->check($root, $this->runner);
            }
        }

        return new LintRun($fix ? 'fix' : 'check', $results);
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function uniqueRoots(array $roots): array
    {
        $seen = [];
        foreach ($roots as $root) {
            $normalized = rtrim($root, '/');
            if ($normalized !== '' && ! in_array($normalized, $seen, true)) {
                $seen[] = $normalized;
            }
        }

        return $seen;
    }
}
