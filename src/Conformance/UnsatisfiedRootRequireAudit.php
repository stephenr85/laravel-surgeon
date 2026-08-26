<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * The fifth supply check, and the only one that catches the estate's loudest failure: **this root
 * requires a package that is not in its own installed set.** The manifest says the dependency is there,
 * `vendor/` does not have it, and nothing until now asked the two to agree.
 *
 * **The signature it exists for: a suite that dies at BOOT with ZERO assertions.** Measured 2026-08-25/26
 * across the estate — `splicewire/laravel-satellite` **12 red**, `splicewire/laravel-satellite-training`
 * **166 red**, both on a clean HEAD, both `Rushing\SchemaConvergence\ConvergentTable not found`, both with
 * `rushing/laravel-schema-convergence` in `require` and absent from `vendor/`. Reverting an unrelated
 * change and re-running confirmed the red was pre-existing. **11 roots** carried a non-dev instance of
 * this at the time it was built.
 *
 * That failure mode is why the check has to be always-on rather than something reached for. A boot-time
 * class-not-found reports **exactly** the way a real regression does — a red suite — so a session that
 * has just made a change reads it as its own, and the two are indistinguishable from the outside. Neither
 * a grep nor a green declaration audit tells them apart, because the manifest is *correct*: the require
 * is declared, and usually supplied too. Only composer's record of what is actually installed disagrees.
 *
 * **Why it is a fifth check and folds into none of the four.** They ask different questions of different
 * substrates and nominate different repairs:
 *  - {@see DanglingPathRepoAudit} — is a declared `type: path` target gone? → fix or drop the entry.
 *  - {@see RequireWithoutSupplyAudit} — can this repo's own require be fetched off this machine? → declare
 *    a fetchable source.
 *  - {@see TransitiveSupplyAudit} — is every private package in the CLOSURE supplied at this root? → seed
 *    the missing `repositories` entries.
 *  - {@see UnsatisfiedNeighbourRequireAudit} — does a path-installed NEIGHBOUR's require exist here? →
 *    `composer update <that-neighbour> -W`.
 *  - **this one** — does this root's OWN require exist here? → install at this root.
 *
 * The first three read manifests and cannot see this at all: the manifest is right. The fourth *sometimes*
 * catches an instance of it, and only by accident — at `splicewire/laravel-satellite` the missing package
 * is also required by two path-installed neighbours, so the neighbour audit fires; at
 * `splicewire/laravel-beam-versioning` (`spatie/typescript-transformer`) no neighbour requires it and the
 * root's own gap is invisible. Coverage that depends on whether some *other* package happens to want the
 * same dependency is not coverage, and it names the wrong package in its repair besides.
 *
 * **`require` fails, `require-dev` warns — and the dev half is not noise.** Composer honours `require-dev`
 * at the root, so a root's own missing dev dependency is a real disagreement; it is a Warn because its
 * blast radius is the dev tooling rather than the application. The population that split found is worth
 * naming: **16 roots declare `wikimedia/composer-merge-plugin` in `require-dev` and do not have it
 * installed** — which means their `composer.local.json` overlay is not being merged at all, and every
 * conclusion drawn from that overlay at those roots is about a file composer never read.
 *
 * **What it does NOT check, stated in its own report.** Version constraints and remotes. This is a
 * presence question read from `installed.json`, so a package installed at a version this root's
 * constraint excludes reads as satisfied. Composer settles constraints; this audit settles absence.
 *
 * **It reads `installed.json`, never `vendor/`.** A co-dev `vendor/` is a symlink farm whose packages nest
 * their own `vendor/` — a `find -L` over one was killed at 120s with no result. Composer's own record is a
 * single file, and it is the authority on what composer thinks it installed. (The gap between what composer
 * *thinks* and what is on disk is a different defect, and not this one.)
 *
 * **Severity is not gating.** This ships on the built-in channel, advisory by contract; a host that wants
 * the Fail to redden its build registers this class in its own doctor manifest with `gate: true`.
 */
class UnsatisfiedRootRequireAudit implements SuggestsOperations
{
    public const CHECK = 'unsatisfied-root-require';

    /** Read in this order; the first overlay found supplies the overlay half. */
    public const OVERLAY_FILES = [
        'composer.local.json',
        'composer.local.json.dist',
        'composer.local.json.off',
    ];

    /** The one line the report always carries about what it did NOT check. */
    public const SCOPE_NOTE = 'Scope: this root\'s manifest against composer\'s own installed record — version constraints are not evaluated and no remote is contacted, so a clean result means every required package is PRESENT, not that its version satisfies the constraint.';

    /**
     * @param  string  $appRoot  the repo root whose manifest and installed record are compared
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

        $installed = InstalledPackages::fromHostRoot($root);
        if ($installed->packages === []) {
            // Nothing installed at all is a different state (never installed, or a package that ships no
            // vendor tree) and reporting every require as missing there would bury the real instances.
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No vendor/composer/installed.json in this repo — nothing has been installed here to compare the manifest against.',
            ))];
        }

        $overlay = [];
        foreach (self::OVERLAY_FILES as $candidate) {
            if (is_file($root.'/'.$candidate)) {
                $overlay = $this->readJson($root.'/'.$candidate);
                break;
            }
        }

        $findings = [];
        $checked = 0;

        foreach ($this->requires($base, $overlay) as $name => $block) {
            $checked++;
            if ($installed->satisfies($name)) {
                continue;
            }
            $findings[] = $this->unsatisfied($root, $name, $block);
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                sprintf(
                    'All %d package(s) this root requires are present in its own installed set. %s',
                    $checked,
                    self::SCOPE_NOTE,
                ),
            ));
        }

        return $findings;
    }

    /**
     * This root's own non-platform requires, name => the block that declared it. The overlay's `require`
     * counts as `require` — co-dev intent is still a require, and composer merges it.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, string>
     */
    private function requires(array $base, array $overlay): array
    {
        $names = [];

        foreach ([['require', $base], ['require-dev', $base], ['require', $overlay]] as [$block, $config]) {
            $requires = $config[$block] ?? [];
            if (! is_array($requires)) {
                continue;
            }
            foreach (array_keys($requires) as $name) {
                $name = (string) $name;
                if ($this->isPlatform($name)) {
                    continue;
                }
                // `require` wins over `require-dev` when a name somehow appears in both: the harsher
                // severity is the true one, and it is read from whichever block was seen first.
                $names[$name] ??= $block;
            }
        }

        return $names;
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

    private function unsatisfied(string $root, string $name, string $block): FixableFinding
    {
        $command = sprintf('composer update %s -W', $name);
        $isDev = $block === 'require-dev';

        $detail = sprintf(
            'This root declares %s in `%s` and it is absent from its own installed set — the manifest and `vendor/` disagree, and composer\'s record is the one that is running. %s %s Repair: run `%s` in %s. %s',
            $name,
            $block,
            $isDev
                ? 'Blast radius is this root\'s dev tooling rather than its application code, which is the only reason this is a Warn.'
                : 'It costs nothing until a class from that package loads, and then it is a fatal class-not-found at BOOT — a suite that dies with zero assertions, which reports exactly like a real regression and is why this needs an always-on check rather than a look when something seems wrong.',
            $name === 'wikimedia/composer-merge-plugin'
                ? 'This one has a second consequence worth stating: without the merge plugin installed, this root\'s composer.local.json overlay is NOT being merged, so anything concluded from that file here is about a file composer never read.'
                : '',
            $command,
            $root,
            self::SCOPE_NOTE,
        );

        $finding = $isDev
            ? Finding::warn(self::CHECK, $detail)
            : Finding::fail(self::CHECK, $detail);

        // Advisory in KIND — surgeon runs no composer command — but the repair is a single exact command
        // at this root rather than a choice of mechanism, so the payload carries it.
        return new FixableFinding(
            $finding,
            new OperationSuggestion(
                OperationSuggestion::ADVISORY,
                sprintf('Run `%s` in %s so %s enters this root\'s lock and vendor tree.', $command, $root, $name),
                ['command' => $command, 'root' => $root, 'missing' => $name, 'block' => $block],
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
