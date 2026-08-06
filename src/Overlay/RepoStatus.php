<?php

namespace Rushing\Surgeon\Overlay;

/**
 * The git readiness of one path-repo checkout, as canonicalization sees it (ticket 11). The four facts
 * that decide whether a canonical `composer update` would resolve the code you actually tested — because
 * a package required as `dev-main` resolves its *pushed tip*, not your working copy:
 *
 *  - {@see $isRepo}      — the checkout is a git repository at all (a path package that isn't can't be
 *                          git-resolved into a shippable lock).
 *  - {@see $isDirty}     — uncommitted changes in the tree/index (they would silently vanish from the lock).
 *  - {@see $hasUpstream} — the current branch tracks a remote (the lock can't resolve a tip that isn't on one).
 *  - {@see $ahead}       — committed-but-unpushed commits (they too vanish until pushed — but the fix is
 *                          deterministic, so this is the one readiness gap that is auto-fixable).
 *
 * A pure value read by {@see GitInspector}; {@see CanonicalizationAudit} maps it to doctor Findings.
 */
class RepoStatus
{
    public function __construct(
        public bool $isRepo,
        public bool $isDirty,
        public bool $hasUpstream,
        public int $ahead,
    ) {}

    public static function notARepo(): self
    {
        return new self(false, false, false, 0);
    }

    /** Clean, tracking a remote, and pushed — the only state that canonicalizes without dropping code. */
    public function isReady(): bool
    {
        return $this->isRepo && ! $this->isDirty && $this->hasUpstream && $this->ahead === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_repo' => $this->isRepo,
            'is_dirty' => $this->isDirty,
            'has_upstream' => $this->hasUpstream,
            'ahead' => $this->ahead,
            'ready' => $this->isReady(),
        ];
    }
}
