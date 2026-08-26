<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **a `require` whose only declared source in this repo
 * is a `type: path` repository.** A path repo is a local convenience — it points at a checkout on this
 * machine. It travels with nothing: not with a clone, not with CI, not with a deploy box. So a repo
 * that requires a package and only path-supplies it is green here and unresolvable everywhere else, and
 * the failure is invisible until someone installs from the manifest rather than from the overlay.
 *
 * **Why it is silent until then, which is the whole reason it needs an always-on check.** A require
 * that nothing imports never fails. The defect this audit exists for stood for four days across 29
 * repos — a package required into manifest after manifest with no repo anywhere saying where to fetch
 * it — because every one of those repos resolved it through a co-dev path overlay and nothing ever
 * asked the question the manifest was supposed to answer. Detection had to be always-on precisely
 * because an instrument you reach for once you already suspect something is the wrong instrument for a
 * silent defect.
 *
 * **The rule it mechanizes:** a require for a package is not finished until that repo can tell composer
 * where to fetch it. The *mechanism* is deliberately not this audit's business — a `vcs` entry per repo,
 * a private registry listed once, or publishing to Packagist are infrastructure decisions with cost and
 * credential implications. The audit rules only that the step exists, which is the part that is
 * mechanically decidable.
 *
 * **What counts as declared supply.** Any non-`path` repository entry: a `package` repo is matched on
 * the name it inlines (exact), and a `vcs`/`git`/`github`-style entry on its url carrying the package's
 * stem — the only offline reading available, since a vcs entry names a remote and not a package.
 * A `composer`-type repository (a private registry) is **opaque offline**: its presence is reported as a
 * caveat on the finding rather than silently suppressing it, because a registry may or may not carry the
 * package and this audit does not go and look.
 *
 * **It reads this repo's OWN require block, and that is NOT the question composer asks.** Composer
 * resolves the whole graph from the ROOT's `repositories` list — a dependency's own repositories are
 * ignored — so a private package reached TRANSITIVELY needs an entry here even though nothing in this
 * manifest names it. This audit is therefore **green by construction** at every root in that class, and
 * it was: measured on the beam-facade map twice, two days apart, it read green at all 5 of ticket 73's
 * roots and all 21 of ticket 91's while every one of them failed `composer update`. Ticket 91 ruled the
 * audit **stays** — it answers a true and cheap question — and that the resolvability question moves to a
 * second instrument, {@see TransitiveSupplyAudit}. Nothing here should be read as "this root resolves";
 * {@see self::notChecked()} now says so on every clean run rather than leaving a reader to discover it.
 *
 * **The two things this audit does NOT know, and says so.** Packagist membership and remote
 * reachability. Both need the network; this is a manifest-and-filesystem question by construction
 * ({@see DanglingPathRepoAudit} makes the same declaration for the same reason). A package that IS on
 * Packagist and is also path-linked for local co-dev is therefore a legitimate false positive, and the
 * finding is worded so a reader can dismiss it in one step rather than re-deriving why it fired.
 *
 * **Why this and {@see \Rushing\Surgeon\Overlay\OverlayAudit}'s `require-without-path-repo` are not the
 * same check, read backwards.** That one asks whether an overlay's own co-dev `require` has a local
 * checkout to satisfy it — an *overlay consistency* question, scoped to the overlay's require block,
 * reported inside `surgeon:overlay`'s whole-overlay diagnosis. This one asks whether the repo's requires
 * have a source that survives leaving this machine. The two nominate opposite repairs (add a path repo
 * vs. add a fetchable one), which is why neither folds into the other.
 *
 * The fix is **advisory** throughout: which supply mechanism a repo adopts is a judgment call, not a
 * splice, and the same finding is often repaired once for an entire estate rather than repo by repo.
 */
class RequireWithoutSupplyAudit implements SuggestsOperations
{
    public const CHECK = 'require-without-supply';

    /** Read in this order; the first overlay found supplies the overlay half (live wins over template). */
    public const OVERLAY_FILES = [
        'composer.local.json',
        'composer.local.json.dist',
        'composer.local.json.off',
    ];

    /**
     * @param  string  $appRoot  the repo root whose manifest and overlay are read (the running host)
     */
    public function __construct(
        public string $appRoot,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $root = realpath($this->appRoot) ?: rtrim($this->appRoot, '/');
        $base = $this->readJson($root.'/composer.json');

        if ($base === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No composer.json in this repo — no requires to check.',
            ))];
        }

        $overlayFile = null;
        foreach (self::OVERLAY_FILES as $candidate) {
            if (is_file($root.'/'.$candidate)) {
                $overlayFile = $candidate;
                break;
            }
        }
        $overlay = $overlayFile !== null ? $this->readJson($root.'/'.$overlayFile) : [];

        $requires = $this->requires($base, $overlay);
        if ($requires === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No non-platform requires declared — nothing to source.',
            ))];
        }

        $pathSupply = $this->pathSupply($root, $overlayFile);
        $declared = DeclaredSupply::read($base, $overlay);

        $findings = [];
        foreach ($requires as $name) {
            if (! isset($pathSupply[$name])) {
                continue; // resolves from a declared repository or Packagist — not this audit's question
            }
            if ($declared->declares($name)) {
                continue;
            }

            $findings[] = $this->unsupplied($name, $pathSupply[$name], $declared->opaque);
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                sprintf(
                    'All %d path-supplied require(s) of %d also have a fetchable source declared (or none is path-supplied). %s',
                    count($pathSupply),
                    count($requires),
                    self::notChecked(),
                ),
            ));
        }

        return $findings;
    }

    /** The standing caveat, in one place: both gaps are named rather than left implicit. */
    public static function notChecked(): string
    {
        return 'Not checked: Packagist membership, remote reachability, and — the one most often misread —'
            .' the TRANSITIVE closure. This reads only this repo\'s own require block, and composer resolves'
            .' the whole graph from this root\'s repositories list, so a clean result here does NOT mean this'
            .' root resolves ('.TransitiveSupplyAudit::CHECK.' asks that question). This is a'
            .' manifest-and-filesystem question.';
    }

    /**
     * Every non-platform package the repo requires: base `require` + `require-dev`, plus the overlay's
     * own `require` (co-dev intent is still a require).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return list<string>
     */
    private function requires(array $base, array $overlay): array
    {
        $names = [];
        foreach ([$base['require'] ?? [], $base['require-dev'] ?? [], $overlay['require'] ?? []] as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (array_keys($block) as $name) {
                $name = (string) $name;
                if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || ! str_contains($name, '/')) {
                    continue;
                }
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Package name => the `file: "url"` citations that path-supply it. Path repos are read from the
     * live manifest and the overlay in force, and glob urls are expanded — `../*` is a normal spelling
     * and the packages it reaches are path-supplied exactly like a named entry.
     *
     * @return array<string, list<string>>
     */
    private function pathSupply(string $root, ?string $overlayFile): array
    {
        $files = array_values(array_filter(['composer.json', $overlayFile]));
        $reader = new DanglingPathRepoAudit($root, walkNeighbours: false);

        $supply = [];
        foreach ($files as $file) {
            $path = $root.'/'.$file;
            if (! is_file($path)) {
                continue;
            }

            foreach ($reader->pathRepositories($path) as $entry) {
                foreach ($entry['matches'] as $target) {
                    $name = $this->readJson($target.'/composer.json')['name'] ?? null;
                    if (is_string($name)) {
                        $supply[$name][] = sprintf('%s: "%s"', $file, $entry['url']);
                    }
                }
            }
        }

        return $supply;
    }

    /**
     * @param  list<string>  $citations
     * @param  list<string>  $opaque
     */
    private function unsupplied(string $name, array $citations, array $opaque): FixableFinding
    {
        $detail = sprintf(
            '%s is required, but its only declared source in this repo is a type:path repository (%s) — a path repo points at a checkout on this machine and travels with nothing, so a clone, a CI job or a deploy box has nowhere to fetch it from. %s%s',
            $name,
            implode('; ', array_unique($citations)),
            $opaque !== []
                ? 'This repo also declares a composer registry ('.implode(', ', array_unique($opaque)).') which is not read here, so it may already supply this package. '
                : '',
            self::notChecked(),
        );

        return new FixableFinding(
            Finding::warn(self::CHECK, $detail),
            OperationSuggestion::advisory(
                "Declare a fetchable source for {$name} (a vcs repositories entry, a registry, or publishing it) — which mechanism is an infrastructure decision, not a splice, and it is usually taken once for a whole estate rather than repo by repo.",
                'rushing/laravel-surgeon',
            ),
        );
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
