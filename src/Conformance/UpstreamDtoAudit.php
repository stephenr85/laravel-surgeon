<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\RelocationOperation;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Surgeon\Rewrite\DestinationDepAdvisor;

/**
 * **b1 — the promotion-candidate audit** (ticket 15). A standing, surgeon-native conformance audit that
 * extracts the deterministic core the `03-beamux-data-home` discovery hunt proved: *does this app-local
 * DTO belong DOWN in a package it types off?* It emits a {@see FixableFinding} through the ticket-07/13
 * bridge, nominating a ticket-14 cross-repo {@see RelocationOperation} to the derived home.
 *
 * **The deterministic core.** For each class in the scanned app namespace ({@see $namespace}, default
 * `App\Data`), it reads the class's `use` imports and asks three questions, all mechanically decidable:
 *  1. **every non-framework import points DOWN into exactly ONE installed package** — framework/ambient
 *     deps (`Spatie\LaravelData`, `Illuminate`, `Symfony`, `Psr`, …) are ambient and ignored, exactly as
 *     {@see DestinationDepAdvisor} ignores them on the way out; what remains must all resolve into a single
 *     downstream package ({@see InstalledPackages::ownerOf()}) and nothing app-specific (an import back
 *     into `App\…` is app-coupling → NOT a promotion candidate);
 *  2. **the class declares nothing app-specific** — captured by (1): a self-`App\` import disqualifies;
 *  3. **0 cycle-risk** — relocating the class into package D must not create `D → … → app` (an upward
 *     composer edge). Reuses {@see PackageGraph::reaches()} (the DB-free indicator, same fidelity the
 *     move-audit uses) — if D already reaches the app's package, the move is refused (no suggestion).
 *
 * When all three hold, the class is a promotion candidate: a **Warn** (it's not broken today — it works
 * app-local; it just *belongs* downstream) paired with a real `relocation` suggestion carrying the derived
 * `old → new` FQN pair and, in the summary, the composer tail {@see DestinationDepAdvisor} would flag
 * (spatie/laravel-data etc. the destination may not require). Detection AND fix are both deterministic —
 * a genuinely fixable operation, not an advisory.
 *
 * **Foundation-clean.** The audit names no `splicewire/*`/`schemastud/*` type; the downstream package is
 * whatever the host has installed, discovered at runtime — the beam-ux home is DATA, not a dependency.
 * Note b1 finds candidates whose home does NOT yet exist (a greenfield promotion); when the home already
 * exists AND has drifted (the real BeamUxEntryBodyData case), {@see StaleDownstreamDuplicateAudit} (b2)
 * catches it first as a stale duplicate — the two audits partition the space.
 */
class UpstreamDtoAudit implements SuggestsOperations
{
    private DestinationDepAdvisor $depAdvisor;

    /**
     * @param  string  $appRoot  the host app root (holds vendor/composer/installed.json + the scanned dir)
     * @param  string  $namespace  the app namespace to scan (default `App\Data`)
     * @param  string  $dir  the directory that namespace maps to, relative to $appRoot (default `app/Data`)
     * @param  list<string>  $ambient  vendor namespace roots that are framework/ambient (ignored imports)
     */
    public function __construct(
        public string $appRoot,
        public string $namespace = 'App\\Data',
        public string $dir = 'app/Data',
        public array $ambient = ['Illuminate', 'Symfony', 'Psr', 'Carbon', 'Ramsey', 'Spatie'],
    ) {
        $this->depAdvisor = new DestinationDepAdvisor;
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $scanDir = rtrim($this->appRoot, '/').'/'.trim($this->dir, '/');
        if (! is_dir($scanDir)) {
            return [new FixableFinding(Finding::pass(
                'upstream-dto.no-scope',
                "No {$this->dir} directory in this host — no upstream-DTO promotion candidates to check.",
            ))];
        }

        $installed = InstalledPackages::fromHostRoot($this->appRoot);
        $graph = PackageGraph::fromRoots([$this->appRoot]);
        $appPackage = $graph->packageForRoot($this->appRoot);
        $reader = new DtoClassReader;

        $findings = [];
        foreach ($this->phpFilesIn($scanDir) as $file) {
            $class = $reader->read($file);
            if ($class === null || ! str_starts_with($class->fqn, rtrim($this->namespace, '\\').'\\')) {
                continue;
            }

            $home = $this->derivedHome($class, $installed);
            if ($home === null) {
                continue; // imports don't converge on exactly one downstream package (or are app-coupled)
            }

            // 0-cycle-risk: relocating into $home must not close an upward edge back to the app package.
            if ($appPackage !== null && $graph->reaches($home, $appPackage)) {
                $findings[] = new FixableFinding(Finding::warn(
                    'upstream-dto.cycle-risk',
                    "{$class->fqn} types cleanly off {$home}, but relocating into it would create an upward composer edge ({$home} already reaches {$appPackage}) — not a safe promotion.",
                ));

                continue;
            }

            $newFqn = $this->deriveNewFqn($class, $installed, $home);
            $depTail = $this->composerTail($class);

            $findings[] = new FixableFinding(
                Finding::warn(
                    'upstream-dto.promotion-candidate',
                    "{$class->fqn} types cleanly DOWN into {$home} and nothing app-specific — belongs in that package (0 cycle-risk).",
                ),
                new OperationSuggestion(
                    (new RelocationOperation([]))->kind(),
                    "Promote {$class->fqn} → {$newFqn} (cross-repo relocation into {$home})."
                        .($depTail !== '' ? " Composer tail: {$depTail}" : ''),
                    [
                        'old' => $class->fqn,
                        'new' => $newFqn,
                        'destination_package' => $home,
                        'cross_repo' => true,
                    ],
                ),
            );
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                'upstream-dto.none',
                "No app-local DTOs under {$this->namespace} converge cleanly on a single downstream package.",
            ));
        }

        return $findings;
    }

    /**
     * The single downstream package every non-ambient import of $class points into, or null when the
     * imports don't converge on exactly one package OR any import is app-specific (back into the app's
     * own namespace root). This IS the promotion signal — "types off ONE package, nothing app-local."
     */
    private function derivedHome(DtoClass $class, InstalledPackages $installed): ?string
    {
        $appRootPrefix = $this->appNamespaceRoot();

        $owners = [];
        foreach ($class->imports as $import) {
            $top = $this->topSegment($import);
            if ($top === '' || in_array($top, $this->ambient, true)) {
                continue; // framework/ambient — ignored, exactly like DestinationDepAdvisor
            }
            if ($appRootPrefix !== '' && $top === $appRootPrefix) {
                return null; // app-specific coupling → never a clean promotion candidate
            }

            $owner = $installed->ownerOf($import);
            if ($owner === null) {
                return null; // a non-ambient import owned by no installed package — can't derive a home
            }
            $owners[$owner] = true;
        }

        // Exactly one downstream package, and at least one real (non-ambient) downstream import.
        return count($owners) === 1 ? array_key_first($owners) : null;
    }

    /**
     * The class's new FQN at its derived home: strip the app namespace root, prepend the owning package's
     * PSR-4 root. Falls back to keeping the tail under the package's first root when a precise map isn't
     * derivable — the payload is a *nomination*, ratified before any move runs.
     */
    private function deriveNewFqn(DtoClass $class, InstalledPackages $installed, string $home): string
    {
        foreach ($installed->packages as $package) {
            if ($package['name'] !== $home) {
                continue;
            }
            $root = array_key_first($package['roots']); // the package's declared PSR-4 root prefix
            if (is_string($root)) {
                return $root.$this->tailBelowAppRoot($class->fqn);
            }
        }

        return $class->fqn;
    }

    /** The class's FQN below the app's namespace root (e.g. `App\Data\Foo` → `Data\Foo`). */
    private function tailBelowAppRoot(string $fqn): string
    {
        $root = $this->appNamespaceRoot();
        $prefix = $root.'\\';

        return str_starts_with($fqn, $prefix) ? substr($fqn, strlen($prefix)) : $fqn;
    }

    /** The app's top-level namespace root — the segment before the scanned namespace (default `App`). */
    private function appNamespaceRoot(): string
    {
        return $this->topSegment(rtrim($this->namespace, '\\'));
    }

    /**
     * The composer-tail advisory a cross-repo move of this class WOULD raise: the ambient/framework deps
     * (spatie/laravel-data etc.) the destination package may not itself require. We surface it in the
     * suggestion summary so the human sees the tail up front — exactly {@see DestinationDepAdvisor}'s
     * flag-never-edit output, computed against the SOURCE file (the file carries the same imports either
     * home). Empty when the file leans on nothing the destination is unlikely to have.
     */
    private function composerTail(DtoClass $class): string
    {
        // The framework roots a spatie-data DTO carries that a package might not require.
        $tail = [];
        foreach ($class->imports as $import) {
            $top = $this->topSegment($import);
            if (in_array($top, $this->ambient, true) && $top !== 'Illuminate') {
                $tail[$top] = true;
            }
        }

        return $tail === [] ? '' : 'destination may need to require '.implode(', ', array_keys($tail)).'\\… vendors.';
    }

    private function topSegment(string $fqn): string
    {
        $pos = strpos(ltrim($fqn, '\\'), '\\');

        return $pos === false ? ltrim($fqn, '\\') : substr(ltrim($fqn, '\\'), 0, $pos);
    }

    /**
     * List `.php` files directly and recursively under a directory (skipping nothing — the scanned dir is
     * already scoped to the app namespace's own tree).
     *
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
