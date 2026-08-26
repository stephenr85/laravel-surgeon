<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **a `vendor/` entry that resolves nowhere.** Two
 * halves of one defect class, split by whether composer knows the package exists (beam-facade tickets
 * 57 / 74 / 92).
 *
 *  - **Present in `vendor/composer/installed.json`, install path unresolvable → Fail.** Composer
 *    believes the package is installed. `package:discover` walks the installed set and loads each
 *    package's manifest, so this is the wall ticket 14 hit: the root cannot run an artisan command,
 *    and the error names a class or a file rather than the missing checkout.
 *  - **A dangling `vendor/<vendor>/<name>` symlink ABSENT from `installed.json` → Warn.** Inert rot.
 *    Composer will never remove it — a full `composer update` leaves it exactly where it is (measured
 *    at ticket 38) — because composer only touches what its own record names.
 *
 * **`InstalledPackages::fromHostRoot()` has computed the install path per package since it was
 * written, and no audit had ever asserted the path exists.** That is the whole of the Fail half: one
 * predicate over substrate that was already built.
 *
 * **Why the Warn half needs a filesystem walk, which the rest of this family refuses.** No manifest
 * reader can reach an orphan: it is absent from `installed.json` by definition, and `vendor/` is
 * gitignored at every root, so there is no manifest, no diff, no `git status` and no review surface on
 * which it could ever appear. 57 measured 93 of them estate-wide and the regime saw none. The walk is
 * a deliberate widening of the family's offline-and-manifest-only posture rather than a lapse: it is
 * the same predicate {@see DanglingPathRepoAudit} already applies to *declared* targets, pointed one
 * directory over, and it costs ~1256 `lstat`s across the whole estate — one root's share is noise.
 *
 * **Population: the running root only, and this is the one place it differs from
 * {@see DanglingPathRepoAudit}.** That audit walks its neighbours on the principle that *the overlay
 * is the roster* — a `type: path` url names a root, so the configs a repo declares already enumerate
 * the repos it co-develops against. The same walk provably cannot reach the roots that need *this*
 * check, and the asymmetry is structural rather than an oversight: a path url points **at a package**,
 * while the roots carrying an installed set are **hosts**, and ticket 43 measured that nobody points
 * at a host. A host is a leaf. Following path urls from here would visit package checkouts with no
 * `vendor/` at all and report nothing, while the unbootable host that most needs the check stays
 * exactly as dark. Widening the *invocation* — running surgeon against a named root — is the shape
 * that would work, and that is a command-surface decision, not this audit's. An estate-wide number
 * stays a census's output; a root reports on itself. The scope note ships that asymmetry as a finding
 * ({@see self::CHECK}`.scope`) rather than leaving it in this docblock, because the person who needs
 * it is reading a sweep.
 *
 * **Nothing is nominated, both halves advisory, and the deletion criterion ships IN the finding.**
 * 57's criterion is mechanical enough to nominate — still a symlink, still dangling, still absent from
 * *this* root's `installed.json`, re-verified at delete time, never by pattern — and it is still
 * refused (74's explicit ruling). Every surgeon verb today is a manifest edit (`OverlayVerb`:
 * add/remove/materialize/teardown/sync); a filesystem-deleting verb would be surgeon's first, minted
 * for a **Warn** whose own diagnosis is *inert*, against a live population of zero. A verb is earned
 * by a population. So the criterion is stated in the finding text instead, which is what a reader
 * actually needs: enough to act in one command, having re-verified rather than trusted the report.
 *
 * **The Fail half's repair is regeneration, never deletion.** 57 ruled its seven in-lock danglers
 * undeletable and left them as evidence; ticket 73 then supplied those roots, `composer update` became
 * runnable, and the class evaporated with nobody deleting anything. That is why the Fail finding names
 * `composer update` and not a removal.
 *
 * **It cannot gate, and it was built knowing (ticket 88).** This ships on the built-in channel, which
 * is advisory by contract — a Fail here renders red and the sweep still exits 0 unless the host has
 * registered this class in its own doctor manifest with `gate: true`
 * ({@see \Rushing\Surgeon\Operation\ConformanceSweep}). 88 argued from two specimens that predate the
 * question; this is its third and the first built deliberately inside the gap, asserted by test on
 * ticket 72's precedent that asserting an absence is a real deliverable.
 *
 * **26's three-causes warning does not bite here.** It governs inferring *provenance* from `is_link` —
 * a symlinked `vendor/` entry has at least three distinct causes and `is_link` tells none apart. This
 * check asks nothing about provenance, only whether the target resolves, and a dangling symlink is
 * dead under all three.
 */
class DanglingInstallPathAudit implements SuggestsOperations
{
    public const CHECK = 'dangling-install-path';

    /**
     * `vendor/` entries that are not package slots. `composer/` is composer's own bookkeeping and
     * `bin/` holds executable shims, which are symlinks by design and name no package — a finding
     * saying "absent from installed.json" would be meaningless about either.
     */
    public const NOT_PACKAGE_SLOTS = ['composer', 'bin'];

    /** The one line the report always carries about what it did NOT check. */
    public const SCOPE_NOTE = 'Scope: THIS root only — unlike dangling-path-repo, which walks the roots its path repositories declare, because a path url points at a PACKAGE while the roots carrying an installed set are HOSTS, and nothing points at a host. An estate-wide count is a census\'s output, not a root\'s report.';

    /**
     * @param  string  $appRoot  the repo root whose installed set and `vendor/` tree are read
     */
    public function __construct(
        public string $appRoot,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $root = realpath($this->appRoot) ?: rtrim($this->appRoot, '/');
        $vendor = $root.'/vendor';

        if (! is_dir($vendor)) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No vendor/ directory in this repo — nothing is installed here to resolve.',
            ))];
        }

        $installed = InstalledPackages::fromHostRoot($root);

        $findings = [];
        $known = [];

        foreach ($installed->packages as $package) {
            $known[strtolower($package['name'])] = true;

            if (! is_dir($package['path'])) {
                $findings[] = $this->unresolvable($root, $package['name'], $package['path']);
            }
        }

        $walk = $this->walkVendor($vendor, $known);
        foreach ($walk['orphans'] as $orphan) {
            $findings[] = $this->orphan($root, $orphan['name'], $orphan['link'], $orphan['target']);
        }

        $walked = $walk['walked'];

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                sprintf(
                    'All %d installed package(s) resolve to a directory that exists, and none of the %d vendor/*/* symlink(s) here is both dangling and absent from this root\'s installed set. %s',
                    count($installed->packages),
                    $walked,
                    self::SCOPE_NOTE,
                ),
            ));
        }

        $findings[] = new FixableFinding(Finding::pass(
            self::CHECK.'.scope',
            sprintf(
                'Read %d installed package record(s) and walked %d vendor/*/* symlink(s) at %s. %s',
                count($installed->packages),
                $walked,
                $root,
                self::SCOPE_NOTE,
            ),
        ));

        return $findings;
    }

    /**
     * Every `vendor/<vendor>/<name>` symlink whose target does not resolve AND whose package name is
     * absent from the installed set, plus the total number of such symlinks walked (the healthy ones
     * are the summary's denominator — a report that only ever counts defects cannot be read as a
     * measurement). A dangling symlink composer DOES know about is the Fail half's
     * business, reported there and not duplicated here.
     *
     * `is_link()` before `file_exists()` is load-bearing in that order: `file_exists()` follows the
     * link, so it is false for a broken symlink and for a path with nothing at it at all, and only
     * `is_link()` separates rot from absence.
     *
     * @param  array<string, true>  $known  lowercased composer names present in `installed.json`
     * @return array{orphans: list<array{name: string, link: string, target: string}>, walked: int}
     */
    private function walkVendor(string $vendor, array $known): array
    {
        $walked = 0;
        $orphans = [];

        foreach ($this->childDirs($vendor) as $vendorName) {
            if (in_array($vendorName, self::NOT_PACKAGE_SLOTS, true)) {
                continue;
            }

            foreach ($this->children($vendor.'/'.$vendorName) as $packageName) {
                $link = $vendor.'/'.$vendorName.'/'.$packageName;

                if (! is_link($link)) {
                    continue;
                }

                $walked++;

                if (file_exists($link)) {
                    continue;
                }

                $name = $vendorName.'/'.$packageName;
                if (isset($known[strtolower($name)])) {
                    continue; // composer knows it — the Fail half already reported it.
                }

                $orphans[] = ['name' => $name, 'link' => $link, 'target' => (string) @readlink($link)];
            }
        }

        return ['orphans' => $orphans, 'walked' => $walked];
    }

    /** @return list<string> */
    private function childDirs(string $dir): array
    {
        return array_values(array_filter(
            $this->children($dir),
            fn (string $child) => is_dir($dir.'/'.$child),
        ));
    }

    /** @return list<string> */
    private function children(string $dir): array
    {
        $entries = @scandir($dir);

        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, fn (string $e) => $e !== '.' && $e !== '..'));
    }

    /**
     * The Fail half: composer's own record names this package, and the path it records is not there.
     * The repair is regeneration rather than removal — 57's in-lock danglers were all cleared by a
     * `composer update` that became runnable, with nobody deleting anything.
     */
    private function unresolvable(string $root, string $package, string $path): FixableFinding
    {
        $command = sprintf('composer update %s -W', $package);

        $detail = sprintf(
            '%s is recorded in vendor/composer/installed.json and its install path %s does not exist — composer believes this package is installed and it resolves nowhere. `package:discover` walks the installed set, so this root cannot run an artisan command, and the error it prints names a class or a file rather than the missing checkout. The repair is REGENERATION, not deletion: run `%s` in %s so installed state is rebuilt against what is actually on disk. If the package was renamed or relocated, fix the declaration first — a rename is not done until every root that had it installed has regenerated. %s',
            $package,
            $path,
            $command,
            $root,
            self::SCOPE_NOTE,
        );

        return new FixableFinding(
            Finding::fail(self::CHECK, $detail),
            new OperationSuggestion(
                OperationSuggestion::ADVISORY,
                sprintf('Run `%s` in %s so installed state is regenerated against what is on disk.', $command, $root),
                ['command' => $command, 'root' => $root, 'package' => $package, 'path' => $path],
                'rushing/laravel-surgeon',
            ),
        );
    }

    /**
     * The Warn half: inert rot composer will never touch. The finding carries 57's deletion criterion
     * verbatim so a reader can act in one command — and carries the re-verify clause with it, because
     * a report is a snapshot and the whole class was minted by a layout migration that moved many
     * roots at once.
     */
    private function orphan(string $root, string $name, string $link, string $target): FixableFinding
    {
        $detail = sprintf(
            'vendor/%s is a symlink to %s, which does not exist, and %s is absent from this root\'s vendor/composer/installed.json — so composer will never remove it (a full `composer update` leaves it exactly in place, measured at ticket 38) and nothing in version control can show it, because vendor/ is gitignored. It is inert: nothing loads through it. Deletion criterion, ALL of which must hold and must be re-verified at delete time rather than trusted from this report: still a symlink, still dangling, and still absent from THIS root\'s installed set. Never delete by pattern. %s',
            $name,
            $target === '' ? '(unreadable target)' : $target,
            $name,
            self::SCOPE_NOTE,
        );

        return new FixableFinding(
            Finding::warn(self::CHECK.'.orphan', $detail),
            OperationSuggestion::advisory(
                sprintf('Re-verify the three conditions at %s, then `rm %s`. Surgeon nominates nothing here: every verb it owns is a manifest edit, and this is a filesystem deletion for an inert Warn.', $root, $link),
                'rushing/laravel-surgeon',
            ),
        );
    }
}
