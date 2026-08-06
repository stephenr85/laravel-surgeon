<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Surgeon\Console\CanonicalizeCommand;

/**
 * The perform half of canonicalization (ticket 11) — the deterministic, subprocess-free steps that turn
 * a co-dev overlay into a shippable, git-resolved lock. It derives the {@see CanonicalizationPlan}, takes
 * the overlay offline (and restores it — {@see relink()} is the manifest-scoped rollback of ticket 03:
 * relink = resume co-dev), and verifies the resulting lock is path-free. The `composer update` and
 * `git push` subprocesses stay in {@see CanonicalizeCommand} (the writer, gated),
 * so this class holds only pure file/derivation logic — the same JSON-edit-vs-composer-effect split
 * {@see OverlayEditor} keeps, which is what lets the review story stay honest.
 *
 * The footgun it encodes: a package required as `dev-main` resolves its *pushed tip*, so canonicalizing
 * over a dirty/ahead checkout ships an older package than you tested. Judging readiness is
 * {@see CanonicalizationAudit}'s job; this class refuses nothing itself — the command gates on the audit
 * before it calls {@see takeOverlayOffline()}.
 */
class Canonicalizer
{
    public function __construct(
        public string $baseDir,
    ) {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public function plan(): CanonicalizationPlan
    {
        $manifest = OverlayManifest::fromDirectory($this->baseDir);
        $names = array_values(array_filter(array_map(
            fn (OverlayEntry $e) => $e->name,
            $manifest->requiredEntries(),
        )));

        return new CanonicalizationPlan(
            $names,
            self::vendorGlobs($names),
            $manifest->activeFilePath ?? $this->baseDir.'/composer.local.json',
            $this->baseDir.'/.git/composer.local.json.bak',
            $this->baseDir.'/composer.lock',
        );
    }

    /**
     * Move the live overlay to its `.git/` backup — the shippable update must not see the path repos.
     * A no-op if the overlay is already offline. Returns whether a file was moved.
     */
    public function takeOverlayOffline(CanonicalizationPlan $plan): bool
    {
        if (! is_file($plan->overlayFile)) {
            return false; // already offline
        }
        $dir = dirname($plan->backupPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return rename($plan->overlayFile, $plan->backupPath);
    }

    /** Restore the overlay from its backup — resume co-dev (ticket 03: relink = the scoped rollback). */
    public function relink(CanonicalizationPlan $plan): bool
    {
        if (! is_file($plan->backupPath)) {
            return false;
        }

        return rename($plan->backupPath, $plan->overlayFile);
    }

    /** Count surviving `type: path` sources in the lock — must be 0 for a shippable lock. */
    public function pathSourceCount(?string $lockPath = null): int
    {
        $lockPath ??= $this->baseDir.'/composer.lock';
        if (! is_file($lockPath)) {
            return 0;
        }

        return (int) preg_match_all('/"type"\s*:\s*"path"/', (string) file_get_contents($lockPath));
    }

    /**
     * Collapse package names to unique vendor globs (`rushing/foo`, `rushing/bar` → `rushing/*`) — the
     * `composer update` selectors that git-resolve the overlay's packages together.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function vendorGlobs(array $names): array
    {
        $vendors = [];
        foreach ($names as $name) {
            $vendor = str_contains($name, '/') ? substr($name, 0, (int) strpos($name, '/')) : $name;
            $vendors[$vendor.'/*'] = true;
        }

        return array_keys($vendors);
    }
}
