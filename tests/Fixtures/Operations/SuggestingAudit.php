<?php

namespace Rushing\Surgeon\Tests\Fixtures\Operations;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Synthetic stand-in for a real concept-owner's audit (a beam "proper particle?" check is beam's work,
 * out of ticket-13 scope). It implements BOTH channels — `DoctorAudit` (diagnosis-only, for `*:doctor`)
 * and `SuggestsOperations` (the paired fix channel) — proving the sweep prefers the richer channel and
 * exercising all three pair shapes: a real fix, an advisory nomination, and a plain (null) finding.
 */
class SuggestingAudit implements DoctorAudit, SuggestsOperations
{
    /** @return list<Finding> */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f) => $f->finding, $this->suggestOperations());
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        return [
            new FixableFinding(
                Finding::fail('particle-shape', 'App\\Models\\Order should be a proper particle'),
                new OperationSuggestion(
                    'relocation',
                    'Relocate App\\Models\\Order → App\\Particles\\Order',
                    ['old' => 'App\\Models\\Order', 'new' => 'App\\Particles\\Order'],
                ),
            ),
            new FixableFinding(
                Finding::warn('repeated-shape', 'Three controllers repeat the same guard block'),
                OperationSuggestion::advisory('Repeated guard block looks deterministic', 'splicewire/laravel-beam'),
            ),
            new FixableFinding(
                Finding::pass('naming', 'All realms are correctly named'),
            ),
        ];
    }
}
