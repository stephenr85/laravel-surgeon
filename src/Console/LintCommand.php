<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Surgeon\Lint\LintOrchestrator;
use Rushing\Surgeon\Lint\LintResult;
use Rushing\Surgeon\Lint\LintRun;
use Rushing\Surgeon\Overlay\OverlayManifest;

/**
 * `surgeon:lint` — the standalone cross-stack lint operation (ticket 12), one of the two entry points into
 * the shared {@see LintOrchestrator} (the other is stage 4 of ticket 03's deterministic post-op gate). It
 * discovers the stacks present in the owned roots (sniff-with-override) and runs their formatters/linters.
 *
 * **Default = check-mode (writes nothing):** report style drift across the roots, exit non-zero if any is
 * found (so it's usable as a standalone CI gate). This is the same mode the post-op gate runs.
 *
 * **`--fix` = the separate auto-format write-op (ticket 08 decision 4's check-vs-fix split):** run each
 * stack's *deterministic* formatter, reformatting in place, with its **own touch-manifest** sidecar. It is
 * deliberately NOT what the `move`/`canonicalize` gate does — a formatter mutating the tree inside a write
 * would blur what the write-op changed vs what the formatter changed. A non-deterministic formatter's
 * residue (eslint's non-auto-fixable rules) is left **advisory** for an agent — the deterministic/agentic
 * seam the map insists every ticket hold.
 *
 * **Scope (ticket 03 / 08 — overlay = discovery, not authorization):** app-only by default; `--root=<path>`
 * opts into cross-root (repeatable); `--root=all-overlay` expands to every *required* path repo in the
 * co-dev overlay (the self-describing candidate set). A write (`--fix`) still names the roots it touches —
 * knowing a root is ownable ≠ being authorized to reformat it this run.
 */
class LintCommand extends Command
{
    protected $signature = 'surgeon:lint
        {--fix : Deterministically reformat (write-op, own touch-manifest); default is check-mode (writes nothing)}
        {--base= : Project root to lint (default: the app base path). Also the overlay source for all-overlay.}
        {--root=* : Additional roots to lint (repeatable); "all-overlay" expands to the overlay\'s required path repos}
        {--manifest= : Where to write the --fix touch-manifest sidecar (default: <base>/.surgeon/lint-manifest.json)}
        {--json : Emit machine-readable output}';

    protected $description = 'Cross-stack lint orchestration across the owned roots (Pint / eslint / …): check by default, --fix to reformat.';

    public function handle(): int
    {
        $base = $this->baseDir();
        $roots = $this->resolveRoots($base);
        $orchestrator = new LintOrchestrator;

        if ($roots === []) {
            $this->error('No roots to lint (base did not resolve).');

            return self::INVALID;
        }

        return $this->option('fix')
            ? $this->fix($base, $roots, $orchestrator)
            : $this->check($roots, $orchestrator);
    }

    // --- check -------------------------------------------------------------------------------------

    /** @param  list<string>  $roots */
    private function check(array $roots, LintOrchestrator $orchestrator): int
    {
        $run = $orchestrator->check($roots);

        if ($this->option('json')) {
            $this->line((string) json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $run->clean() ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:lint — CHECK</> <fg=gray>('.count($roots).' root(s))</>');
        $this->renderDiscovery($roots, $orchestrator);
        $this->renderResults($run);

        // Rendered unconditionally, clean or not: "0 of 1 applicable stack(s) ran" and "1 of 1" must be
        // distinguishable at a glance, which was the whole of beam-facade 163. The deterministic gate has
        // computed this since it was written (DeterministicGate::lint()); this surface never did.
        $this->line('  <fg=gray>'.$run->ranSummary().'.</>');

        if ($run->clean()) {
            $this->line('  <fg=green>All applicable stacks clean.</>');
            $this->line('');

            return self::SUCCESS;
        }

        if (($unrunnable = $run->unrunnable()) !== []) {
            // A stack that APPLIED and did not execute is a fact about the run, not about the host — the
            // same answer at every root, and not a thing the check's author could get wrong by not knowing
            // where it would run. So `AGENTS.md`'s advisory-not-fatal rule does not cover it, and this
            // declared gate fails. A root where no stack APPLIES still passes; that is a different
            // question and stacksFor() already answers it.
            $this->line('  <fg=red>'.count($unrunnable).' applicable stack(s) could not execute — nothing was checked there.</>');
        }

        if (($drift = $run->violations()) !== []) {
            $this->line('  <fg=red>'.count($drift).' stack-root(s) with drift.</> <fg=green>[fix: surgeon:lint --fix]</>');
        }

        $this->line('');

        return self::FAILURE;
    }

    // --- fix ---------------------------------------------------------------------------------------

    /** @param  list<string>  $roots */
    private function fix(string $base, array $roots, LintOrchestrator $orchestrator): int
    {
        $run = $orchestrator->fix($roots);
        $manifestPath = $this->writeSidecar($base, $run, $roots);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'run' => $run->toArray(),
                'manifest' => $manifestPath,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:lint --fix</> <fg=gray>('.count($roots).' root(s))</>');
        $this->renderResults($run);
        $this->line('  reformatted: <options=bold>'.$run->fixedCount().'</> file(s) across the applicable stacks.');

        $advisory = array_values(array_filter($run->violations(), fn (LintResult $r) => ! $r->fixable));
        if ($advisory !== []) {
            $this->line('  <fg=yellow>'.count($advisory).' stack-root(s) left non-auto-fixable problems — advisory (agent handles).</>');
        }

        $this->line('  touch-manifest → '.$manifestPath);
        $this->line('  <fg=yellow>Not committed.</> Review the reformat diff; the manifest is the scoped-undo surface.');
        $this->line('');

        return self::SUCCESS;
    }

    // --- rendering ---------------------------------------------------------------------------------

    /** @param  list<string>  $roots */
    private function renderDiscovery(array $roots, LintOrchestrator $orchestrator): void
    {
        foreach ($orchestrator->discover($roots) as $root => $stacks) {
            $label = $stacks === [] ? '<fg=gray>no stack applies</>' : implode(', ', $stacks);
            $this->line('  <fg=gray>'.basename($root).':</> '.$label);
        }
    }

    private function renderResults(LintRun $run): void
    {
        $this->line('');
        foreach ($run->results as $result) {
            $where = $result->stack.' @ '.basename($result->root);
            [$tag, $line] = match (true) {
                $result->couldNotRun() => ['<fg=red>DEAD</>', $result->detail],
                $result->isSkipped() => ['<fg=gray>SKIP</>', $result->detail],
                $result->hasViolations() => ['<fg=red>FAIL</>', $result->violations.' violation(s)'.($result->fixable ? '' : ' <fg=yellow>(advisory)</>')],
                $result->fixed => ['<fg=green>FIX </>', $result->violations.' reformatted'],
                default => ['<fg=green>PASS</>', 'clean'],
            };
            $this->line("  {$tag} {$where} <fg=gray>{$line}</>");
        }
        $this->line('');
    }

    // --- scope resolution --------------------------------------------------------------------------

    /**
     * The roots to lint: the base, plus any `--root` (with `all-overlay` expanding to the overlay's
     * required path repos — the self-describing candidate set, ticket 08).
     *
     * @return list<string>
     */
    private function resolveRoots(string $base): array
    {
        $roots = [$base];

        foreach ((array) $this->option('root') as $root) {
            $root = (string) $root;
            if ($root === 'all-overlay') {
                foreach (OverlayManifest::fromDirectory($base)->requiredEntries() as $entry) {
                    $roots[] = $entry->path;
                }

                continue;
            }
            $roots[] = $this->absolutePath($root);
        }

        return array_values(array_unique(array_filter($roots)));
    }

    /** @param  list<string>  $roots */
    private function writeSidecar(string $base, LintRun $run, array $roots): string
    {
        $path = $this->option('manifest');
        $path = ($path !== null && $path !== '')
            ? $this->absolutePath((string) $path)
            : $base.'/.surgeon/lint-manifest.json';

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'operation' => 'lint',
            'roots' => $roots,
            'run' => $run->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function baseDir(): string
    {
        $base = $this->option('base');
        if ($base !== null && $base !== '') {
            return rtrim($this->absolutePath((string) $base), '/');
        }

        return rtrim($this->laravel->basePath(), '/');
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        $real = realpath($path);

        return $real !== false ? rtrim($real, '/') : rtrim((string) getcwd(), '/').'/'.$path;
    }
}
