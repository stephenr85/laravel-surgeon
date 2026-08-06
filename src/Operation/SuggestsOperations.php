<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\DoctorAudit;

/**
 * The surgeon-side interface a concept-owning package implements *alongside* its
 * {@see DoctorAudit} to add the fix half (ticket 07, decision 3). The deterministic
 * fix lives WITH the concept-owner — beam knows both "is this a proper particle?" and how to fix it —
 * never centralized in surgeon (a central map would have to know every package's internals and become
 * the bottleneck; rejected in decision 3).
 *
 * The method is deliberately named {@see suggestOperations()}, NOT `run()`: a class may implement both
 * `DoctorAudit` (`run(): list<Finding>`) and this interface, and PHP forbids two `run()`s with different
 * returns. So the diagnosis-only channel keeps `DoctorAudit::run()`, and this adds the paired channel.
 * An audit that suggests operations may implement *only* this interface — the conformance sweep runs
 * whichever channel an audit exposes.
 *
 * These three contracts ({@see Operation}, {@see OperationSuggestion}, this) live INSIDE laravel-surgeon
 * for now. The first cross-package implementation (beam) is the tripwire that extracts
 * `rushing/surgeon-contracts` (decision 3, ticket-08's deferred `-core` split). Do NOT pre-split.
 */
interface SuggestsOperations
{
    /**
     * Diagnose, and pair each finding with the deterministic operation (if any) that fixes it.
     *
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array;
}
