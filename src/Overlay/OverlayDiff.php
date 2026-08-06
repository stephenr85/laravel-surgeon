<?php

namespace Rushing\Surgeon\Overlay;

/**
 * A structured comparison of one project's overlay path-repo set against a canonical set (ticket 10 —
 * "diff a repo's overlay vs the fleet's canonical set"). The fleet shares one path-repo set, so a
 * project drifting from it is a mal-adaptation {@see OverlayAudit} can flag and `surgeon:overlay sync`
 * can (additively) resolve.
 *
 * Compared by declared `url` (the stable identity of a `type: path` entry):
 *  - {@see $missing} — urls in the canonical set this project lacks (what `sync` would add).
 *  - {@see $extra}   — urls this project has that the canonical set does not (a removal is a per-repo
 *                      judgment call, never auto-applied by `sync`).
 */
class OverlayDiff
{
    /**
     * @param  list<string>  $missing
     * @param  list<string>  $extra
     */
    public function __construct(
        public array $missing,
        public array $extra,
    ) {}

    public static function between(OverlayManifest $mine, OverlayManifest $canonical): self
    {
        $mineUrls = self::urls($mine);
        $canonicalUrls = self::urls($canonical);

        return new self(
            array_values(array_diff($canonicalUrls, $mineUrls)),
            array_values(array_diff($mineUrls, $canonicalUrls)),
        );
    }

    public function inSync(): bool
    {
        return $this->missing === [] && $this->extra === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'in_sync' => $this->inSync(),
            'missing' => $this->missing,
            'extra' => $this->extra,
        ];
    }

    /** @return list<string> */
    private static function urls(OverlayManifest $manifest): array
    {
        return array_values(array_unique(array_map(fn (OverlayEntry $e) => $e->url, $manifest->entries)));
    }
}
