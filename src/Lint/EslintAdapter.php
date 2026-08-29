<?php

namespace Rushing\Surgeon\Lint;

/**
 * The eslint {@see LintStack} — the JS half of the proof-of-two (ticket 12), proving the engine bakes in
 * **no PHP assumption** (ticket 08 decision 4). eslint is where the deterministic/agentic seam earns its
 * keep: unlike Pint, eslint's `--fix` is only *partially* deterministic — many rules have an auto-fixer,
 * some do not. So a check-mode violation nominates `surgeon:lint --fix` (the automated pass), but the fix
 * run may leave residual, non-auto-fixable errors that stay **advisory** for an agent — exactly the seam
 * the map insists every ticket preserve (a non-deterministic formatter's leftovers stay agent territory).
 *
 * **Sniff:** a `package.json` PLUS an eslint config (`eslint.config.{js,mjs,cjs,ts}` flat config, or a
 * legacy `.eslintrc*`, or an `eslintConfig` key / `eslint` devDep in `package.json`). A `package.json`
 * alone is NOT enough — that is precisely the "a `package.json` with no lint script" mis-fire the
 * sniff-with-override design (ticket 08 decision 4) calls out. The declared `extra.surgeon.lint` override
 * (applied by the registry) is how a repo that deliberately skips eslint suppresses even a positive sniff.
 *
 * **Binary:** prefer the root's `node_modules/.bin/eslint`; else a bare `eslint` on PATH. Check-mode =
 * `eslint .`; fix-mode = `eslint . --fix`. The exit-code→result mapping is pure over the injected
 * {@see StackRunner}, so it unit-tests without Node installed.
 */
class EslintAdapter implements LintStack
{
    public function name(): string
    {
        return 'eslint';
    }

    public function detect(string $root): bool
    {
        $root = rtrim($root, '/');

        if (! is_file($root.'/package.json')) {
            return false;
        }

        foreach (['eslint.config.js', 'eslint.config.mjs', 'eslint.config.cjs', 'eslint.config.ts', '.eslintrc', '.eslintrc.js', '.eslintrc.cjs', '.eslintrc.json', '.eslintrc.yml', '.eslintrc.yaml'] as $config) {
            if (is_file($root.'/'.$config)) {
                return true;
            }
        }

        return $this->packageJsonMentionsEslint($root.'/package.json');
    }

    public function check(string $root, StackRunner $runner): LintResult
    {
        return $this->runEslint($root, $runner, fix: false);
    }

    public function fix(string $root, StackRunner $runner): LintResult
    {
        return $this->runEslint($root, $runner, fix: true);
    }

    private function runEslint(string $root, StackRunner $runner, bool $fix): LintResult
    {
        $binary = $this->binary($root);
        if ($binary === null) {
            return LintResult::skipped($this->name(), $root, 'no eslint binary (node_modules/.bin/eslint or eslint on PATH)');
        }

        $command = $fix ? [$binary, '.', '--fix'] : [$binary, '.'];
        $run = $runner->run($root, $command);

        if (! $run->ran) {
            return LintResult::unrunnable($this->name(), $root, $run->output ?: 'eslint could not run');
        }

        // eslint exits 0 = clean, 1 = lint errors present, 2 = config/crash. Treat 2 as UNRUNNABLE (a
        // broken config is not drift we can fix — and it is not a pass either), 1 as violations.
        if ($run->exitCode === 0) {
            return $fix
                ? LintResult::fixed($this->name(), $root, 0, 'eslint --fix: clean')
                : LintResult::clean($this->name(), $root, 'eslint: no problems');
        }

        if ($run->exitCode >= 2) {
            return LintResult::unrunnable($this->name(), $root, 'eslint could not lint (config/crash): '.$this->tail($run->output));
        }

        // exitCode 1 — problems reported.
        if ($fix) {
            // After --fix, residual problems are the non-auto-fixable ones: advisory, not fixable-again.
            return LintResult::violations($this->name(), $root, $this->countProblems($run->output), fixable: false, detail: 'eslint --fix left non-auto-fixable problems (advisory): '.$this->tail($run->output));
        }

        // Check-mode drift — nominate the --fix pass (eslint has an auto-fixer for the fixable subset).
        return LintResult::violations($this->name(), $root, $this->countProblems($run->output), fixable: true, detail: $this->tail($run->output));
    }

    private function binary(string $root): ?string
    {
        $local = rtrim($root, '/').'/node_modules/.bin/eslint';
        if (is_file($local)) {
            return $local;
        }

        // No local binary — only fall back to a PATH eslint if a config anchors intent (a package.json
        // with an eslint devDep but nothing installed is an honest skip at run time).
        return 'eslint';
    }

    private function packageJsonMentionsEslint(string $packageJson): bool
    {
        $data = json_decode((string) file_get_contents($packageJson), true);
        if (! is_array($data)) {
            return false;
        }

        if (isset($data['eslintConfig'])) {
            return true;
        }

        foreach (['dependencies', 'devDependencies'] as $section) {
            if (is_array($data[$section] ?? null) && array_key_exists('eslint', $data[$section])) {
                return true;
            }
        }

        return false;
    }

    /** Best-effort count from eslint's "N problems" summary line. */
    private function countProblems(string $output): int
    {
        if (preg_match('/(\d+)\s+problem/i', $output, $m) === 1) {
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
