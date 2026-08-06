<?php

namespace Rushing\Surgeon\Overlay;

/**
 * The previewable derivation of what `surgeon:canonicalize` would do (ticket 11) — the inverse of the
 * co-dev overlay: the overlay goes co-dev (path-resolve), canonicalize goes shippable (git-resolve). Pure
 * data derived from the overlay's *required* path repos, rendered in the dry-run before anything is
 * touched, so "what would canonicalize break?" is answerable without side effects.
 *
 *  - {@see $requiredPackages} — the path-repo package names the shippable lock must git-resolve.
 *  - {@see $vendorGlobs}      — those names collapsed to vendor globs (`rushing/foo` → `rushing/*`), the
 *                              `composer update` selectors that move the pins together.
 *  - {@see $overlayFile}      — the live `composer.local.json` taken offline (backed up to {@see $backupPath}).
 *  - {@see $backupPath}       — under `.git/` deliberately: everything there is ignored, so the backed-up
 *                              overlay can never be committed by accident (a `.off` sibling would not be).
 *  - {@see $lockPath}         — the lock verified path-free after the canonical update.
 */
class CanonicalizationPlan
{
    /**
     * @param  list<string>  $requiredPackages
     * @param  list<string>  $vendorGlobs
     */
    public function __construct(
        public array $requiredPackages,
        public array $vendorGlobs,
        public string $overlayFile,
        public string $backupPath,
        public string $lockPath,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'required_packages' => $this->requiredPackages,
            'vendor_globs' => $this->vendorGlobs,
            'overlay_file' => $this->overlayFile,
            'backup_path' => $this->backupPath,
            'lock_path' => $this->lockPath,
        ];
    }
}
