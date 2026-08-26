<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * The fourth supply check: **a private package in this root's TRANSITIVE closure that the root itself
 * declares no fetchable source for.**
 *
 * **Why the third one could not answer this and cannot be made to.** {@see RequireWithoutSupplyAudit}
 * reads the root's OWN `require` block. Composer does not: it resolves the whole graph from the ROOT's
 * `repositories` list, and a dependency's own repositories are ignored entirely. So a root requiring one
 * private package, correctly supplied, is unresolvable the moment that package requires a second private
 * package the root has never named — and the declaration audit is **green by construction** at every root
 * in that class. Measured twice on the beam-facade map (tickets 73 and 91, two days apart): the audit read
 * green at all 5 of 73's roots and all 21 of 91's while **every one of them failed `composer update`**.
 * That is not a tuning problem, it is the wrong question, which is why this ships as a second instrument
 * rather than as a widening of the first.
 *
 * ## Three things this audit is deliberately not
 *
 * **It is not a resolve, and a green here is not "this root resolves."** It reads local working copies,
 * so it is wrong in BOTH directions and says so in its own report ({@see self::APPROXIMATION_NOTE}):
 *  - *over-reports* — a local checkout whose `require` is ahead of its pushed tip makes this demand supply
 *    the git-resolved closure does not need (observed at `rushing/laravel-data-nav`);
 *  - *under-reports*, the dangerous direction — a neighbour's **pushed tip** can require more than its
 *    local checkout does, and no walk over local checkouts can see that. At `splicewire/laravel-tower-market`
 *    this reported the closure complete, 0 missing, and the resolve then found **4 more**.
 *
 * A finding here is a **nomination**; only `composer update --dry-run` confirms. That is the exact
 * misreading the declaration audit invited, and shipping a second instrument that invited it again would
 * be worse than shipping nothing.
 *
 * **It is not a gate, and it runs no composer command.** The resolve is the oracle and stays deliberate
 * and opt-in — ~30s per root per mode, network-bound, and dated the moment it runs. This is the cheap
 * offline check that runs everywhere. Severity is Warn throughout, on the built-in (advisory) channel.
 *
 * **It has a MODE, and answering in the wrong one fails SILENTLY** — the only silent way this misleads,
 * and the reason {@see self::mode()} is stamped on every finding including the clean one. The co-dev
 * overlay path-supplies most of the family, so asked with the overlay in place this answers the *co-dev*
 * question, not the shippable one. Measured at `splicewire/tower`: **0 missing with the overlay, 24
 * without.** A bare `0 missing` is unreadable, and one session already read a mode-confused zero as
 * "this root needs nothing" and lost the run to it.
 *
 * ## How the closure is walked
 *
 * **`require-dev` is honoured at the ROOT only** — composer never resolves a *dependency's* dev requires.
 * Walking them transitively is the single difference between a usable check and an unusable one: measured
 * 2026-08-25 the same walk reported **40 roots** with dev requires followed and **21** without, against a
 * true resolve-failing population of **21**. So the root's own `require-dev` seeds the queue and nothing
 * else's ever does.
 *
 * **The local package index is built from the roster the root already carries** — its own `type: path`
 * repositories (globs expanded, via {@see DanglingPathRepoAudit::pathRepositories()}) plus everything
 * composer recorded as installed from a path source. Nothing external lists the estate, and crucially
 * **`vendor/` is never walked**: a co-dev `vendor/` is a symlink farm whose packages nest their own
 * `vendor/`, and a `find -L` over one was killed at 120s with no result. Provenance comes from
 * `installed.json`, which is a file.
 *
 * **What counts as "private" is the local checkout, not a vendor list.** A package this estate carries a
 * checkout of is a package composer will need told where to fetch — and hardcoding `rushing/`,
 * `schemastud/`, `splicewire/` would teach a foundation-tier package a family's vocabulary. The known
 * false positive is a genuinely-Packagist package that is also path-linked for local co-dev; it is the
 * same false positive {@see RequireWithoutSupplyAudit} already documents and accepts, and the finding is
 * worded so a reader dismisses it in one step rather than re-deriving why it fired.
 *
 * **The repair is seeded, not nominated.** The iterated repair loop composer forces is quadratic — it
 * reports ONE miss per run and re-fetches branch metadata for every entry already declared, so one root
 * burned ~45 rounds and another needed 27 entries discovered one at a time. The whole statically-visible
 * closure can be written in a single offline pass instead (measured: 24 entries in about a second), and
 * the resolve then iterates only on the residual. So each finding carries the concrete
 * `composer config repositories.<stem> vcs <url>` line, with the url read from the local checkout's
 * **real `origin` remote** (parsed out of its `.git/config` — never guessed, and never by shelling out).
 */
class TransitiveSupplyAudit implements SuggestsOperations
{
    public const CHECK = 'transitive-supply';

    /** Read in this order; the first overlay found supplies the overlay half (live wins over template). */
    public const OVERLAY_FILES = [
        'composer.local.json',
        'composer.local.json.dist',
        'composer.local.json.off',
    ];

    /** The standing caveat. It ships on every finding, clean ones included — see the class docblock. */
    public const APPROXIMATION_NOTE = 'This is an APPROXIMATION and it is wrong in both directions: it reads local working copies, so it over-reports where a checkout runs ahead of its pushed tip, and under-reports where a pushed tip requires more than its checkout does. A finding here nominates; only `composer update --dry-run` confirms, and a clean result does NOT mean this root resolves.';

    /**
     * @param  string  $appRoot  the repo root whose closure is walked (the running host)
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
                'No composer.json in this repo — no closure to walk.',
            ))];
        }

        $overlayFile = $this->liveOverlay($root);
        $overlay = $overlayFile !== null ? $this->readJson($root.'/'.$overlayFile) : [];
        $mode = $this->mode($overlayFile);

        $index = $this->localIndex($root, $overlayFile);
        if ($index === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                sprintf(
                    'No local package checkout is reachable from this root (no type:path repository and nothing installed from a path source), so no package can be shown to be private and there is no closure to walk. %s %s',
                    $mode,
                    self::APPROXIMATION_NOTE,
                ),
            ))];
        }

        $declared = DeclaredSupply::read($base, $overlay);
        $selfName = is_string($base['name'] ?? null) ? $base['name'] : null;

        // Root-level `require-dev` IS honoured by composer and seeds the queue; nothing else's ever does.
        $queue = array_merge(
            $this->requires($base, dev: true),
            $this->requires($overlay, dev: true),
        );

        $seen = [];
        $missing = [];
        $walked = 0;

        while ($queue !== []) {
            $package = array_shift($queue);
            if ($package === $selfName || isset($seen[$package])) {
                continue;
            }
            $seen[$package] = true;

            $dir = $index[strtolower($package)] ?? null;
            if ($dir === null) {
                continue; // no local checkout — not demonstrably private, so not this audit's question
            }
            $walked++;

            if (! $declared->declares($package)) {
                $missing[$package] = $dir;
            }

            // A DEPENDENCY's require only. Following its require-dev is the false-positive machine.
            foreach ($this->requires($this->readJson($dir.'/composer.json'), dev: false) as $next) {
                if (! isset($seen[$next])) {
                    $queue[] = $next;
                }
            }
        }

        if ($missing === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                sprintf(
                    'All %d private package(s) reachable in this root\'s transitive closure have a fetchable source declared here. %s %s%s',
                    $walked,
                    $mode,
                    $declared->opaque !== []
                        ? 'A composer registry is also declared ('.implode(', ', $declared->opaque).') and is not read here. '
                        : '',
                    self::APPROXIMATION_NOTE,
                ),
            ))];
        }

        ksort($missing);

        return [$this->unsupplied($root, $missing, $mode, $declared->opaque, $walked)];
    }

    /**
     * The mode sentence, stamped on every finding. Absent this a `0 missing` is unreadable: the same
     * root answers a different question with and without its overlay (0 vs 24 at `splicewire/tower`).
     */
    public function mode(?string $overlayFile): string
    {
        return $overlayFile === null
            ? 'MODE: overlay-absent — this is the SHIPPABLE closure, the one a clone or a deploy box sees.'
            : sprintf('MODE: overlay-present (%s) — this is the CO-DEV closure; the overlay path-supplies packages a clone would still have to fetch, so re-run with the overlay moved aside for the shippable answer.', $overlayFile);
    }

    /** The first overlay composer would actually read, or null when the root carries none. */
    private function liveOverlay(string $root): ?string
    {
        foreach (self::OVERLAY_FILES as $candidate) {
            if (is_file($root.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every private package this estate carries a checkout of, as far as this root can see it:
     * lowercased composer name => absolute checkout directory.
     *
     * Two sources, both files — `vendor/` itself is never walked (see the class docblock):
     *  - the `type: path` repositories the root's manifest and live overlay declare, globs expanded;
     *  - everything composer's own `installed.json` records as installed from a `path` source, which
     *    catches the roots whose links exist while their overlay file does not. Measured 2026-08-26,
     *    the overlay file and the links on disk disagree at 72 of 102 roots, in BOTH directions.
     *
     * @return array<string, string>
     */
    private function localIndex(string $root, ?string $overlayFile): array
    {
        $index = [];

        $reader = new DanglingPathRepoAudit($root, walkNeighbours: false);
        foreach (array_values(array_filter(['composer.json', $overlayFile])) as $file) {
            $path = $root.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            foreach ($reader->pathRepositories($path) as $entry) {
                foreach ($entry['matches'] as $target) {
                    $name = $this->readJson($target.'/composer.json')['name'] ?? null;
                    if (is_string($name)) {
                        $index[strtolower($name)] = $target;
                    }
                }
            }
        }

        foreach (InstalledPackages::fromHostRoot($root)->installedFromPath() as $package) {
            // installed.json's copy of a require is the lock-time snapshot; the checkout is live source.
            $index[strtolower($package['name'])] = $package['path'];
        }

        return $index;
    }

    /**
     * A config's non-platform requires. `$dev` is true only for the ROOT's own manifest and overlay —
     * composer never resolves a dependency's dev requires, and following them is the trap that doubled
     * this check's reported population (40 roots vs a true 21).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function requires(array $config, bool $dev): array
    {
        $blocks = $dev ? ['require', 'require-dev'] : ['require'];
        $names = [];

        foreach ($blocks as $block) {
            $requires = $config[$block] ?? [];
            if (! is_array($requires)) {
                continue;
            }
            foreach (array_keys($requires) as $name) {
                $name = (string) $name;
                if ($this->isPlatform($name)) {
                    continue;
                }
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** Composer's own non-package requires: the runtime, extensions, libraries, and composer's APIs. */
    private function isPlatform(string $name): bool
    {
        $name = strtolower($name);

        return $name === 'php'
            || ! str_contains($name, '/')
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || str_starts_with($name, 'composer-');
    }

    /**
     * The checkout's real `origin` url, read out of its `.git/config`. Never guessed from the package
     * name — `rushing/*` supplies from the `stephenr85` org, so a name-derived url is wrong for a whole
     * vendor — and never obtained by shelling out, because an audit that runs processes stops being safe
     * to run everywhere.
     */
    private function origin(string $dir): ?string
    {
        $config = $dir.'/.git/config';
        if (! is_file($config)) {
            return null;
        }

        $contents = (string) @file_get_contents($config);
        if (! preg_match('/\[remote "origin"\](.*?)(\n\[|$)/s', $contents, $section)) {
            return null;
        }
        if (! preg_match('/^\s*url\s*=\s*(\S+)/m', $section[1], $url)) {
            return null;
        }

        return $url[1];
    }

    /**
     * @param  array<string, string>  $missing  package name => local checkout dir
     * @param  list<string>  $opaque
     */
    private function unsupplied(string $root, array $missing, string $mode, array $opaque, int $walked): FixableFinding
    {
        $seed = [];
        $unknown = [];
        foreach ($missing as $package => $dir) {
            $url = $this->origin($dir);
            if ($url === null) {
                $unknown[] = $package;

                continue;
            }
            $seed[$package] = sprintf(
                'composer config repositories.%s vcs %s',
                (new DeclaredSupply)->stemOf($package),
                $url,
            );
        }

        $detail = sprintf(
            '%d private package(s) in this root\'s transitive closure have no fetchable source declared here: %s. Composer resolves the WHOLE graph from this root\'s `repositories` list — a dependency\'s own repositories are ignored — so a transitively-reached private package needs an entry HERE even though nothing in this repo\'s own require block names it. That is why `%s` reads green on a root that cannot resolve. Walked %d private package(s) in the closure. %s%s%s',
            count($missing),
            implode(', ', array_keys($missing)),
            RequireWithoutSupplyAudit::CHECK,
            $walked,
            $unknown !== []
                ? 'No origin remote found for '.implode(', ', $unknown).', so no seed line is offered for those. '
                : '',
            $opaque !== []
                ? 'This repo also declares a composer registry ('.implode(', ', $opaque).') which is not read here, so it may already supply some of these. '
                : '',
            $mode.' '.self::APPROXIMATION_NOTE,
        );

        $suggestion = $seed === []
            ? 'Declare a fetchable source for each package listed — no local origin remote was readable, so the urls have to be supplied by hand.'
            : sprintf(
                "Seed the whole closure in one offline pass, then let the resolve iterate on the residual (the iterated loop is quadratic — one miss reported per run):\n  %s",
                implode("\n  ", $seed),
            );

        return new FixableFinding(
            Finding::warn(self::CHECK, $detail),
            new OperationSuggestion(
                OperationSuggestion::ADVISORY,
                $suggestion,
                ['root' => $root, 'missing' => array_keys($missing), 'seed' => array_values($seed), 'mode' => $mode],
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
