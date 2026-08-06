<?php

namespace Rushing\Surgeon\Overlay;

/**
 * One resolved `type: path` repository entry in a project's co-dev overlay (ticket 10) — the discovery
 * unit `surgeon:overlay list` reports.
 *
 * A composer `type: path` repo names a *directory*, not a package: composer reads that directory's own
 * `composer.json` to learn the package name. So resolution is a two-step read — the overlay declares the
 * url; the target's manifest declares the name — and either step can be broken (a url pointing at a
 * checkout that no longer exists). This value carries the whole resolved picture so the audit
 * ({@see OverlayAudit}) can diagnose a mal-adaptation without re-reading disk:
 *
 *  - {@see $name}       — the package name read from the target checkout, or null if unresolved.
 *  - {@see $url}        — the path url exactly as declared in the overlay (relative or absolute).
 *  - {@see $path}       — the url resolved to an absolute path (best-effort; may not exist on disk).
 *  - {@see $pathExists} — whether that absolute path is a real directory right now.
 *  - {@see $symlink}    — the entry's `options.symlink` (composer's path default is true).
 *  - {@see $required}   — whether the resolved package is actually required by the project (base
 *                         require/require-dev ∪ overlay require). A path repo the merge-plugin never
 *                         materializes is a *harmless extra*, not an error — this flag is how the audit
 *                         tells the two apart.
 */
class OverlayEntry
{
    public function __construct(
        public ?string $name,
        public string $url,
        public string $path,
        public bool $pathExists,
        public bool $symlink,
        public bool $required,
    ) {}

    /** A declared path repo whose target checkout is missing on disk — an unresolvable, stale entry. */
    public function isBroken(): bool
    {
        return ! $this->pathExists;
    }

    /** A resolvable path repo that no `require` engages — present but never materialized (harmless). */
    public function isExtra(): bool
    {
        return $this->pathExists && ! $this->required;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'path' => $this->path,
            'path_exists' => $this->pathExists,
            'symlink' => $this->symlink,
            'required' => $this->required,
            'broken' => $this->isBroken(),
            'extra' => $this->isExtra(),
        ];
    }
}
