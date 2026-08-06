<?php

namespace Rushing\Surgeon\Audit;

/**
 * The deterministic/agentic seam, expressed as three tiers — the whole thesis of the tool.
 *
 * Every {@see Reference} the audit surfaces lands in exactly one tier, and the tier decides who
 * acts on it, not the audit:
 *
 *  - {@see Tier::One} — fully mechanical. A byte-splice repoint the rewriter applies unattended
 *    (`use` imports, inline `::class`, typehints, the moved file's own `namespace` line,
 *    test/fixture/seeder references).
 *  - {@see Tier::Two} — guided. Deterministic to *apply* but needs a human/agent judgment to
 *    *decide* first (keep a `app(\FQN::class)` runtime to dodge a composer cycle, stage a
 *    `class_alias` cutover, re-key a morph map, rewrite a migration `foreignIdFor()`).
 *  - {@see Tier::Three} — advisory only. The audit reports it and stops; a cleanup agent owns the
 *    change because it is genuine business-logic judgment (provider `boot()` registrations, route
 *    names, config keys, tier placement).
 *
 * The audit NEVER promotes across this seam on its own — it classifies; the tier is the handoff.
 */
enum Tier: string
{
    case One = 'tier-1';
    case Two = 'tier-2';
    case Three = 'tier-3';

    public function label(): string
    {
        return match ($this) {
            self::One => 'Tier 1 — automated',
            self::Two => 'Tier 2 — guided',
            self::Three => 'Tier 3 — advisory',
        };
    }
}
