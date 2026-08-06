<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Surgeon\Operation\Operation;

/**
 * The overlay writing verbs as first-class {@see Operation}s (ticket 10) — the second operation family
 * after relocation, and the first that is BOTH non-AST and non-source-mutating (it edits
 * `composer.local.json`, the fleet's dependency-overlay config). This is what {@see OverlayAudit}
 * nominates when it diagnoses an overlay mal-adaptation, so the Finding→Operation bridge (ticket 07/13)
 * carries a dependency-hygiene fix, not just a code relocation — the ticket-08 widening made concrete.
 *
 * One instance per {@see OverlayVerb}; {@see kind()} is `overlay-<verb>` so a diagnosis names exactly
 * the verb that fixes it. The apply/plan work lives on {@see OverlayEditor}/{@see OverlayMutation} (the
 * thin `Operation` interface deliberately hoists neither — decision 1), so this type is purely the
 * identity + intent the agent enumerates.
 */
class OverlayOperation implements Operation
{
    public function __construct(
        public OverlayVerb $verb,
    ) {}

    public function kind(): string
    {
        return 'overlay-'.$this->verb->value;
    }

    public function describe(): string
    {
        return match ($this->verb) {
            OverlayVerb::Add => 'Add a type:path repo (+ optional require) to the co-dev overlay.',
            OverlayVerb::Remove => 'Remove a type:path repo (+ its require) from the co-dev overlay.',
            OverlayVerb::Materialize => 'Turn the co-dev overlay on (copy the template to composer.local.json).',
            OverlayVerb::TearDown => 'Turn the co-dev overlay off (remove composer.local.json).',
            OverlayVerb::Sync => 'Reconcile the overlay toward the fleet\'s canonical path-repo set.',
        };
    }

    public function isWriter(): bool
    {
        return true; // every overlay verb mutates composer.local.json (and drives composer update)
    }
}
