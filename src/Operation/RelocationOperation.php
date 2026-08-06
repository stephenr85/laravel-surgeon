<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Rewrite\Psr4Resolver;
use Rushing\Surgeon\Rewrite\RewritePlan;
use Rushing\Surgeon\Rewrite\RewritePlanner;
use Rushing\Surgeon\Rewrite\SpliceApplier;
use Rushing\Surgeon\Rewrite\TouchManifest;

/**
 * FQN-relocation as the first concrete {@see Operation} (ticket 07, decision 1: "relocation is
 * retrofitted as the first Operation implementation so the surface is real, not aspirational").
 *
 * It is a thin seam over the ticket-05 audit + ticket-09 rewriter that already exist: {@see plan()}
 * turns a relocation {@see AuditReport} into a previewable {@see RewritePlan} (via {@see RewritePlanner}),
 * {@see apply()} performs the byte-splice + physical move (via {@see SpliceApplier}) returning the
 * {@see TouchManifest} — the manifest-scoped undo surface. `surgeon:move` orchestrates the surrounding
 * ticket-03 safety machinery (clean-tree gate, deterministic post-write gate, staging) around these two
 * calls, so the command's proven behaviour is unchanged — the operation just gives it a typed identity.
 *
 * `plan()`/`apply()` stay relocation-typed (not on the {@see Operation} interface): forcing a generic
 * signature before a second writer op exists would be the framework decision 1 rejected.
 */
class RelocationOperation implements Operation
{
    /** @param list<string> $roots the write blast radius, for PSR-4 resolution of the moved file */
    public function __construct(
        private array $roots,
    ) {}

    public function kind(): string
    {
        return 'relocation';
    }

    public function describe(): string
    {
        return 'Relocate a fully-qualified name and repoint every Tier-1 reference across the named roots.';
    }

    public function isWriter(): bool
    {
        return true;
    }

    /** Build the previewable, resolved splice plan from a relocation audit finding-set. */
    public function plan(AuditReport $report): RewritePlan
    {
        return (new RewritePlanner(new Psr4Resolver($this->roots)))->plan($report);
    }

    /** Apply the plan: descending-offset byte-splices + tool-owned physical move. Returns the undo manifest. */
    public function apply(RewritePlan $plan, SpliceApplier $applier): TouchManifest
    {
        return $applier->apply($plan);
    }
}
