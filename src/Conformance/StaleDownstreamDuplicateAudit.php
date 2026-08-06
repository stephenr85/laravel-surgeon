<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\RelocationOperation;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * **b2 — the stale-downstream-duplicate audit** (ticket 15) — *the killer detector*. A standing,
 * surgeon-native conformance audit that catches the trap the `03-beamux-data-home` hunt found by hand:
 * an app-local class that TWINS a class a required downstream package already ships. Where b1
 * ({@see UpstreamDtoAudit}) asks "should this move down?", b2 asks "did the move already happen downstream,
 * leaving a stale app copy behind?" — the two partition the promotion space.
 *
 * **Detection is 100% deterministic.** For each class in the scanned app namespace (default `App\Data`),
 * it asks whether a required downstream package ships a class with the same short name + namespace TAIL
 * ({@see InstalledPackages::findTwin()} — a name/namespace-tail match against the installed estate, in
 * HOST context per ticket-13 decision 6; surgeon reads what the host installed, it does not reach up).
 * No twin → the class is not flagged (a genuinely app-owned DTO is silent).
 *
 * **The fix depends on DRIFT — the deterministic/agentic seam.** When a twin exists, the audit compares
 * the two classes' PUBLIC shapes ({@see PublicShape} — public props + promoted ctor params + method
 * signatures, never bodies) AND their class-level attributes:
 *  - **identical shape** → *fixable* (Warn + a real suggestion): the app copy is pure duplication, safe to
 *    retire and repoint every reference to the downstream FQN — a deterministic relocation-style operation.
 *  - **drifted** (the downstream is ahead/behind — the real case: `Splicewire\Beam\Ux\Data\BeamUxEntryBodyData`
 *    ADDS `$composable` and DROPS `#[TypeScript]`) → **advisory only** ({@see OperationSuggestion::advisory()}):
 *    reconciling a divergence is judgment, and a naive retire would CLOBBER the ahead downstream copy. The
 *    finding NAMES the drift (what the twin adds/drops, incl. the attribute delta) so the human reconciles.
 *
 * This is the surgeon-native extraction of the hunt's headline: "the app copy is a stale, drifted duplicate
 * — the live human call is retire-or-reconcile, never a blind promote." b2 makes that a standing rule.
 */
class StaleDownstreamDuplicateAudit implements SuggestsOperations
{
    /** Class-level attributes worth naming in a drift report (a host-vs-package codegen-seam signal). */
    private const NOTABLE_ATTRIBUTES = ['TypeScript'];

    /**
     * @param  string  $appRoot  the host app root (holds vendor/composer/installed.json + the scanned dir)
     * @param  string  $namespace  the app namespace to scan (default `App\Data`)
     * @param  string  $dir  the directory that namespace maps to, relative to $appRoot (default `app/Data`)
     */
    public function __construct(
        public string $appRoot,
        public string $namespace = 'App\\Data',
        public string $dir = 'app/Data',
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $scanDir = rtrim($this->appRoot, '/').'/'.trim($this->dir, '/');
        if (! is_dir($scanDir)) {
            return [new FixableFinding(Finding::pass(
                'stale-duplicate.no-scope',
                "No {$this->dir} directory in this host — no stale downstream duplicates to check.",
            ))];
        }

        $installed = InstalledPackages::fromHostRoot($this->appRoot);
        $reader = new DtoClassReader;

        $findings = [];
        foreach ($this->phpFilesIn($scanDir) as $file) {
            $class = $reader->read($file);
            if ($class === null || ! str_starts_with($class->fqn, rtrim($this->namespace, '\\').'\\')) {
                continue;
            }

            $twin = $installed->findTwin($class->namespaceTail());
            if ($twin === null) {
                continue; // no downstream twin — a genuinely app-owned DTO, silent
            }

            $twinClass = $reader->read($twin['file']);
            if ($twinClass === null) {
                continue;
            }

            $drift = $this->describeDrift($class, $twinClass);

            if ($drift === '') {
                // Identical public shape → pure duplication, deterministically fixable: retire + repoint.
                $findings[] = new FixableFinding(
                    Finding::warn(
                        'stale-duplicate.identical',
                        "{$class->fqn} duplicates {$twin['fqn']} (shipped by {$twin['package']}) with an IDENTICAL public shape — the app copy is redundant.",
                    ),
                    new OperationSuggestion(
                        (new RelocationOperation([]))->kind(),
                        "Retire the app copy {$class->fqn} and repoint every reference to {$twin['fqn']} ({$twin['package']}).",
                        [
                            'old' => $class->fqn,
                            'new' => $twin['fqn'],
                            'retire_duplicate' => true,
                            'destination_package' => $twin['package'],
                        ],
                    ),
                );

                continue;
            }

            // Drifted → advisory only. Reconciliation is judgment; a blind retire would clobber the twin.
            $findings[] = new FixableFinding(
                Finding::warn(
                    'stale-duplicate.drifted',
                    "{$class->fqn} twins {$twin['fqn']} ({$twin['package']}) but they have DRIFTED — {$drift}. Reconcile by hand (a blind retire would clobber the downstream copy).",
                ),
                OperationSuggestion::advisory(
                    "Reconcile {$class->fqn} against the drifted downstream {$twin['fqn']} — {$drift}.",
                    $twin['package'],
                ),
            );
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                'stale-duplicate.none',
                "No app-local DTOs under {$this->namespace} twin a class shipped by a required downstream package.",
            ));
        }

        return $findings;
    }

    /**
     * Name the drift between an app class and its downstream twin — the property/method-shape delta AND
     * the class-attribute delta — from the DOWNSTREAM'S perspective (what the twin adds/drops relative to
     * the app copy), because the downstream is the promotion target and the human reconciles the app copy
     * TOWARD it. Empty string when the two are identical in both shape and notable attributes (→ fixable).
     */
    private function describeDrift(DtoClass $app, DtoClass $twin): string
    {
        $parts = [];

        $shapeDiff = $app->shape->describeDiff($twin->shape, 'app', 'downstream');
        if ($shapeDiff !== '') {
            $parts[] = $shapeDiff;
        }

        foreach (self::NOTABLE_ATTRIBUTES as $attribute) {
            $appHas = $app->hasAttribute($attribute);
            $twinHas = $twin->hasAttribute($attribute);
            if ($appHas && ! $twinHas) {
                $parts[] = "downstream drops #[{$attribute}]";
            } elseif (! $appHas && $twinHas) {
                $parts[] = "downstream adds #[{$attribute}]";
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
