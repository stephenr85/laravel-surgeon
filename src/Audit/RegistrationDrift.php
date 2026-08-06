<?php

namespace Rushing\Surgeon\Audit;

use Rushing\Doctor\Finding;
use Rushing\Popcorn\Discovery\AttributedClassScanner;

/**
 * The result of diffing a registry's manually-registered class-strings against the classes an
 * {@see AttributedClassScanner} finds carrying the registry's attribute.
 *
 * Two drift shapes, not one — they resolve to opposite fixes:
 *  - {@see $needsAttribute}: registered manually, attribute missing — the class should gain the
 *    attribute so discovery picks it up (the conversion candidate).
 *  - {@see $redundantManualEntry}: attribute present AND still manually registered — the manual
 *    entry is now dead weight and should be deleted.
 *
 * Carries no knowledge of *how* to fix either shape (source text, file paths) — that's per-registry
 * (a manual registration's call shape differs by registry), left to the concrete audit.
 */
class RegistrationDrift
{
    /**
     * @param  list<class-string>  $needsAttribute
     * @param  list<class-string>  $redundantManualEntry
     */
    public function __construct(
        public array $needsAttribute,
        public array $redundantManualEntry,
    ) {}

    public function isClean(): bool
    {
        return $this->needsAttribute === [] && $this->redundantManualEntry === [];
    }

    /**
     * Render as plain {@see Finding}s under one check name — a pass when clean, one warn per
     * drifted class otherwise. The doctor-facing half every concrete audit's `run()` needs identically.
     *
     * @return list<Finding>
     */
    public function toFindings(string $check): array
    {
        if ($this->isClean()) {
            return [Finding::pass($check, 'All manually-registered classes carry the attribute; no drift.')];
        }

        $findings = [];

        foreach ($this->needsAttribute as $class) {
            $findings[] = Finding::warn($check, "{$class} is registered manually but has no attribute.");
        }

        foreach ($this->redundantManualEntry as $class) {
            $findings[] = Finding::warn($check, "{$class} carries the attribute but still has a redundant manual registration.");
        }

        return $findings;
    }
}
