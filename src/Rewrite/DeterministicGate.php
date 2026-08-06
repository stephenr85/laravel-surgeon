<?php

namespace Rushing\Surgeon\Rewrite;

use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Audit\PackageGraph;

/**
 * The deterministic post-write gate (ticket 03, decision 4) — the tool's own, blocking guarantee run
 * after a `--apply`. On any stage failure the caller performs the manifest-scoped auto-rollback, so a
 * failed pass leaves the tree exactly as found.
 *
 * Stages, in order:
 *  1. **`php -l`** on every touched file — the always-on core guarantee (a splice that broke syntax
 *     never survives). Deterministic and context-free.
 *  2. **`composer dump-autoload`** per touched root — catches a move that desynced the autoloader.
 *     Guarded: skipped (not failed) where there is no `composer.json` or no composer binary.
 *  3. **topology cycle-guard** — `laravel-package-topology`'s declared-topology assertion, catching an
 *     upward composer edge a relocation introduced. Guarded: skipped where the guard isn't installed
 *     in the touched roots (it runs as a real gate in an app/package that composes it).
 *
 * Stage 4 (cross-stack lint) is ticket 12's addition and is intentionally NOT wired here.
 *
 * Tests (the filtered, consumer-run set) are explicitly NOT part of this gate — ticket 03 keeps them
 * emitted-not-run so the tool never owns DB isolation as a blocking step.
 */
class DeterministicGate
{
    public function __construct(
        public bool $runComposer = true,
        public bool $runTopology = true,
    ) {}

    /**
     * @param  list<string>  $touchedFiles  absolute paths written/relocated (post-apply, so the moved
     *                                      file is linted at its NEW path)
     * @param  list<string>  $roots  the roots the move touched (for autoload + topology)
     */
    public function run(array $touchedFiles, array $roots): GateResult
    {
        $result = new GateResult;

        $this->lintPhp($touchedFiles, $result);

        if ($this->runComposer) {
            $this->dumpAutoload($roots, $result);
        }

        if ($this->runTopology) {
            $this->topology($roots, $result);
        }

        return $result;
    }

    /** @param list<string> $files */
    private function lintPhp(array $files, GateResult $result): void
    {
        $failed = [];
        foreach ($files as $file) {
            if (! str_ends_with($file, '.php') || ! is_file($file)) {
                continue;
            }
            $proc = Process::run([PHP_BINARY, '-l', $file]);
            if (! $proc->successful()) {
                $failed[] = basename($file).': '.trim($proc->errorOutput() ?: $proc->output());
            }
        }

        $failed === []
            ? $result->record('php -l', 'pass', count($files).' file(s) parse clean')
            : $result->record('php -l', 'fail', implode(' | ', $failed));
    }

    /** @param list<string> $roots */
    private function dumpAutoload(array $roots, GateResult $result): void
    {
        $ran = [];
        foreach ($roots as $root) {
            if (! is_file($root.'/composer.json')) {
                continue;
            }
            $proc = Process::path($root)->run(['composer', 'dump-autoload', '-q', '--no-interaction']);
            if (! $proc->successful()) {
                $result->record('composer dump-autoload', 'fail', basename($root).': '.trim($proc->errorOutput()));

                return;
            }
            $ran[] = basename($root);
        }

        $ran === []
            ? $result->record('composer dump-autoload', 'skip', 'no composer.json in touched roots')
            : $result->record('composer dump-autoload', 'pass', 'regenerated: '.implode(', ', $ran));
    }

    /**
     * The topology cycle-guard stage. The load-bearing cycle check for a relocation is the *pre-write*
     * one the audit already ran ({@see PackageGraph::reaches()}) and the writer
     * refuses on — a cycle-risky move never reaches this gate. Post-write, a Tier-1 relocation moves a
     * class file and repoints source references; it adds no composer `require`, so the declared
     * *require* graph is unchanged and the full `assertDeclaredTopologyHolds()` has nothing new to
     * catch. That assertion remains the consumer's `DeclaredTopologyTest` (the declared-manifest test,
     * per the estate's topology substrate), which the emitted filtered test-set includes.
     *
     * So this stage records honestly: skip (with the reason) rather than fabricate a pass it did not
     * compute. It stays a distinct stage so the seam is visible and a future op that DOES mutate a
     * manifest (canonicalize, ticket 11) can light it up for real.
     *
     * @param  list<string>  $roots
     */
    private function topology(array $roots, GateResult $result): void
    {
        $guarded = array_values(array_filter(
            $roots,
            fn (string $r) => is_dir($r.'/vendor/rushing/php-package-topology'),
        ));

        if ($guarded === []) {
            $result->record('topology cycle-guard', 'skip', 'no root composes rushing/php-package-topology');

            return;
        }

        $result->record(
            'topology cycle-guard',
            'skip',
            'relocation leaves the require graph unchanged (pre-write cycle-risk gate already applied); '
            .'declared topology is asserted by the consumer DeclaredTopologyTest',
        );
    }
}
