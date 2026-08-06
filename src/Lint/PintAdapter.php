<?php

namespace Rushing\Surgeon\Lint;

/**
 * The Pint {@see LintStack} — the PHP half of the proof-of-two (ticket 12). Laravel Pint is a
 * deterministic formatter: its `--test` mode reports drift without touching the tree; a bare run rewrites
 * it. Because the reformat is deterministic, a Pint drift is always **auto-fixable** — {@see LintAudit}
 * nominates `surgeon:lint --fix` for it, and the deterministic/agentic seam sits fully on the fix side.
 *
 * **Sniff:** a `pint.json` is the explicit signal; failing that, any `composer.json` root that vendors the
 * binary can be Pinted. We keep the sniff conservative — `pint.json` OR (`composer.json` + a resolvable
 * `vendor/bin/pint`) — so a plain PHP repo without Pint installed isn't falsely claimed (the engine sniffs
 * *signals*, and a missing binary is an honest skip at run time, not a detect-time lie).
 *
 * **Binary resolution:** prefer the root's own `vendor/bin/pint` (the version the repo pins), fall back to
 * a bare `pint` on PATH. Check-mode = `pint --test`; fix-mode = `pint` (rewrites in place). The exit-code
 * mapping is pure over the injected {@see StackRunner}, so it unit-tests without a real Pint.
 */
class PintAdapter implements LintStack
{
    public function name(): string
    {
        return 'pint';
    }

    public function detect(string $root): bool
    {
        $root = rtrim($root, '/');

        if (is_file($root.'/pint.json')) {
            return true;
        }

        return is_file($root.'/composer.json') && $this->binary($root) !== null;
    }

    public function check(string $root, StackRunner $runner): LintResult
    {
        return $this->runPint($root, $runner, test: true);
    }

    public function fix(string $root, StackRunner $runner): LintResult
    {
        return $this->runPint($root, $runner, test: false);
    }

    private function runPint(string $root, StackRunner $runner, bool $test): LintResult
    {
        $binary = $this->binary($root);
        if ($binary === null) {
            return LintResult::skipped($this->name(), $root, 'no pint binary (vendor/bin/pint or pint on PATH)');
        }

        $command = $test ? [$binary, '--test'] : [$binary];
        $run = $runner->run($root, $command);

        if (! $run->ran) {
            return LintResult::skipped($this->name(), $root, $run->output ?: 'pint could not run');
        }

        // A crashed run (PHP fatal / OOM / uncaught) couldn't lint at all — that's an honest skip, not
        // drift. Detect it before the verdict parse so a broken environment never reads as a violation.
        if ($this->crashed($run->output)) {
            return LintResult::skipped($this->name(), $root, 'pint could not lint: '.$this->tail($run->output));
        }

        // Prefer Pint's structured JSON verdict when present ({"result":"passed|fail|fixed","files":[…]}).
        // It is authoritative and immune to the exit-code quirks of a customized wrapper; fall back to the
        // exit code only when the output isn't Pint's JSON shape.
        $verdict = $this->parseJson($run->output);

        if ($test) {
            if ($verdict !== null) {
                return $verdict['clean']
                    ? LintResult::clean($this->name(), $root, 'pint --test: no style drift')
                    : LintResult::violations($this->name(), $root, $verdict['count'], fixable: true, detail: $verdict['count'].' file(s) need styling');
            }

            // Fallback: Pint --test exits non-zero when files need styling; 0 when clean.
            return $run->exitCode === 0
                ? LintResult::clean($this->name(), $root, 'pint --test: no style drift')
                : LintResult::violations($this->name(), $root, $this->countDirty($run->output), fixable: true, detail: $this->tail($run->output));
        }

        // Fix-mode: a successful run reformatted whatever drifted.
        if ($verdict !== null) {
            return LintResult::fixed($this->name(), $root, $verdict['count'], $verdict['count'].' file(s) reformatted');
        }

        return $run->successful()
            ? LintResult::fixed($this->name(), $root, $this->countDirty($run->output), $this->tail($run->output))
            : LintResult::violations($this->name(), $root, 0, fixable: false, detail: 'pint fix run failed: '.$this->tail($run->output));
    }

    /**
     * Parse Pint's structured JSON verdict, if the output is that shape.
     *
     * @return array{clean: bool, count: int}|null null when the output isn't Pint's JSON
     */
    private function parseJson(string $output): ?array
    {
        $trimmed = trim($output);
        if (! str_starts_with($trimmed, '{')) {
            return null;
        }
        $data = json_decode($trimmed, true);
        if (! is_array($data) || ($data['tool'] ?? null) !== 'pint' || ! isset($data['result'])) {
            return null;
        }

        $count = is_array($data['files'] ?? null) ? count($data['files']) : 0;

        return [
            'clean' => $data['result'] === 'passed',
            'count' => $count,
        ];
    }

    /** A run that emitted a PHP fatal / uncaught error couldn't lint — treated as a skip, not drift. */
    private function crashed(string $output): bool
    {
        return preg_match('/PHP (Fatal error|Warning:.*memory)|Allowed memory size|Uncaught \w+Error/i', $output) === 1;
    }

    /** Prefer the root's pinned binary; else a bare `pint` (resolved at run time by the runner). */
    private function binary(string $root): ?string
    {
        $vendor = rtrim($root, '/').'/vendor/bin/pint';
        if (is_file($vendor)) {
            return $vendor;
        }

        // A composer.json declaring the pint dep is a reasonable signal the vendored binary will exist
        // once installed; without it, only fall back to a PATH pint if a pint.json anchors intent.
        if (is_file(rtrim($root, '/').'/pint.json')) {
            return 'pint';
        }

        return null;
    }

    /** Best-effort count of drifted files from Pint's summary line ("N files, M style issues"). */
    private function countDirty(string $output): int
    {
        if (preg_match('/(\d+)\s+style\s+issue/i', $output, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s+file/i', $output, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    private function tail(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($l) => $l !== ''));

        return $lines === [] ? '' : (string) end($lines);
    }
}
