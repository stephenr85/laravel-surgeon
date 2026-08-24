<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **a package installed from a local `path` source
 * requires something this root's installed set has never heard of.** The host is booting against live
 * source whose dependency its own lock does not carry.
 *
 * **How the state arises, which is why no existing check sees it.** Path-linked co-development means the
 * host runs the neighbour's *working tree*, not the artifact its lock resolved. Add a `require` to that
 * neighbour and the host keeps running it immediately — the symlink needs no install — while the new
 * dependency enters the host's `vendor/` only if someone runs composer there. Nothing warns: the require
 * is not this repo's, so it is absent from the manifest every supply check reads, and the path repo it
 * arrived through resolves perfectly.
 *
 * **Why it is a third supply check and folds into neither sibling.** The three ask different questions
 * and nominate different repairs:
 *  - {@see DanglingPathRepoAudit} — is a declared `type: path` target *gone*? → fix or drop the path repo.
 *  - {@see RequireWithoutSupplyAudit} — can this repo's require be fetched off this machine? → declare a
 *    fetchable source.
 *  - **this one** — does a path-linked *neighbour's* require exist in **this** host's installed set? →
 *    `composer update <that-package> -W` here.
 *
 * The first two read manifests, and this defect is invisible in a manifest by construction: the require
 * belongs to a neighbour and the repo declaring it is fine. The substrate has to be composer's record of
 * what is actually installed.
 *
 * **Severity is Fail, and it is the only member of this family that earns Fail.** The rest are Warn because
 * the repair is a judgment call about *which* supply mechanism to adopt. Here there is exactly one repair,
 * it is a single command at this root, and the untreated state is a fatal `Class not found` the first time
 * a class needing the missing package loads.
 *
 * **Severity is not gating, and this audit does not gate on its own (ticket 88).** It ships on the built-in
 * channel, which is advisory by contract — see {@see \Rushing\Surgeon\Operation\ConformanceSweep}. A host
 * that wants a Fail here to redden its build registers this class in its own doctor manifest with
 * `gate: true`. An earlier draft of this line read *"the only member of this family that gates"*, which was
 * never true of any built-in and is worth naming rather than quietly deleting: severity and gating are
 * separate concepts in this codebase on purpose, and prose that conflates them reads as authoritative.
 *
 * **Latency is not mitigation, and severity cannot be derived from whether the root boots.** Every one of
 * the 21 instances this audit was built against sat in a root that booted: an unsatisfied require costs
 * nothing until something imports it. Beam requires `spatie/laravel-sluggable` and no beam class uses
 * `HasSlug`, so six hosts carried that gap indefinitely; taxonomy's `Tag` did use its equivalent, and that
 * root died. The difference between latent and fatal is which class a request happens to touch.
 *
 * **The require is read from the neighbour's composer.json ON DISK, never from `installed.json`'s copy.**
 * Composer's record is the lock-time snapshot — the very state that is stale here. Reading the live file is
 * the whole point: it is what the host is actually running.
 *
 * **Scope: `require` only, never `require-dev`.** A dependency's dev requires are not installed for a
 * dependency, so flagging them would report composer behaving correctly.
 *
 * **It does not walk to neighbouring roots, and that is a decision rather than an omission** (ticket 69's
 * open question). The audit reads only files, so it *could* be pointed at another root's `installed.json`
 * — but a walk of the shape {@see DanglingPathRepoAudit} performs provably cannot reach the roots that
 * need this check. Path urls point *at* packages, and the roots carrying an installed set are hosts, which
 * nobody points at: a host is a leaf. So the walk would visit package checkouts with no `vendor/` and
 * report nothing, while the unbootable host that most needs the check stays exactly as dark. Widening the
 * *invocation* (running surgeon from elsewhere against a named root) is the shape that would work, and it
 * is a command-surface decision rather than an audit one.
 *
 * **What it does not check, stated in its own report.** Remotes and version *constraints*: this is a
 * presence question read from the filesystem, so a package installed at a version the neighbour's
 * constraint excludes reads as satisfied here. Composer settles constraints; this audit settles absence.
 */
class UnsatisfiedNeighbourRequireAudit implements SuggestsOperations
{
    public const CHECK = 'unsatisfied-neighbour-require';

    /** The one line the report always carries about what it did NOT check. */
    public const SCOPE_NOTE = 'Scope: composer\'s installed record and the neighbours\' manifests on disk — version constraints are not evaluated and no remote is contacted, so a clean result means every required package is PRESENT, not that its version satisfies the constraint.';

    /**
     * @param  string  $appRoot  the repo root whose `vendor/composer/installed.json` is read (the running host)
     */
    public function __construct(
        public string $appRoot,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $root = realpath($this->appRoot) ?: rtrim($this->appRoot, '/');
        $installed = InstalledPackages::fromHostRoot($root);

        if ($installed->packages === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No vendor/composer/installed.json in this repo — nothing is installed here to check.',
            ))];
        }

        $linked = $installed->installedFromPath();
        if ($linked === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                sprintf('None of the %d installed package(s) came from a local path source — no live neighbour to check. %s', count($installed->packages), self::SCOPE_NOTE),
            ))];
        }

        $findings = [];
        $checked = 0;

        foreach ($linked as $package) {
            $manifest = $this->readJson($package['path'].'/composer.json');
            $requires = $manifest['require'] ?? [];
            if (! is_array($requires)) {
                continue;
            }

            foreach (array_keys($requires) as $required) {
                $required = (string) $required;
                if ($this->isPlatform($required)) {
                    continue;
                }
                $checked++;

                if (! $installed->satisfies($required)) {
                    $findings[] = $this->unsatisfied($root, $package['name'], $package['path'], $required);
                }
            }
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                sprintf(
                    'All %d require(s) declared by the %d path-installed package(s) here are present in this root\'s installed set. %s',
                    $checked,
                    count($linked),
                    self::SCOPE_NOTE,
                ),
            ));
        }

        return $findings;
    }

    /** Composer's own non-package requires: the runtime itself, extensions, libraries, and composer's APIs. */
    private function isPlatform(string $name): bool
    {
        $name = strtolower($name);

        return $name === 'php'
            || ! str_contains($name, '/')
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || str_starts_with($name, 'composer-');
    }

    private function unsatisfied(string $root, string $package, string $path, string $required): FixableFinding
    {
        $command = sprintf('composer update %s -W', $package);

        $detail = sprintf(
            '%s is installed here from a local path source (%s) and its own composer.json requires %s, which is not in this root\'s installed set — the host is running that package\'s live source against a lock that has never heard of its dependency. It costs nothing until a class needing %s loads, and then it is a fatal class-not-found at runtime, not a composer error. Repair: run `%s` in %s. %s',
            $package,
            $path,
            $required,
            $required,
            $command,
            $root,
            self::SCOPE_NOTE,
        );

        // Advisory in KIND because surgeon runs no composer command — but this is the one finding in the
        // supply family whose payload can name the exact repair rather than nominate a mechanism.
        return new FixableFinding(
            Finding::fail(self::CHECK, $detail),
            new OperationSuggestion(
                OperationSuggestion::ADVISORY,
                sprintf('Run `%s` in %s so %s enters this root\'s lock and vendor tree.', $command, $root, $required),
                ['command' => $command, 'root' => $root, 'package' => $package, 'missing' => $required],
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
