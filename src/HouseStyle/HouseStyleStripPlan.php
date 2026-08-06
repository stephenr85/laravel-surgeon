<?php

namespace Rushing\Surgeon\HouseStyle;

use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Operation\Operation;
use Rushing\Surgeon\Rewrite\PhysicalMove;
use Rushing\Surgeon\Rewrite\PlannedEdit;
use Rushing\Surgeon\Rewrite\RewritePlan;

/**
 * The previewable, applyable output of {@see HouseStyleStripOperation::plan()} — every byte-splice that
 * removes a banned modern-PHP construct from ONE file, resolved and ready for {@see SpliceApplier}.
 *
 * It is the house-style analogue of the relocation {@see RewritePlan}, but
 * deliberately its OWN shape rather than that one: a strip carries no {@see Target}
 * (there is no old→new FQN — nothing relocates) and no {@see PhysicalMove} (a file
 * is edited in place, never moved). Forcing it through the relocation plan would demand inventing a bogus
 * target/category; the thin {@see Operation} interface deliberately hoists no
 * plan/apply signature precisely so each operation owns its own typed shape (ticket 07 decision 1).
 *
 * What it REUSES is the load-bearing part — the {@see PlannedEdit} span record and the
 * {@see SpliceApplier::spliceSource()} descending-offset + drift-refusal splicer — so the byte-rewrite
 * correctness property is shared, never reimplemented.
 */
class HouseStyleStripPlan
{
    /**
     * @param  list<PlannedEdit>  $edits  every splice for this file (a deletion each: newText is '')
     * @param  list<string>  $reasons  one human token per stripped construct (for the audit detail line)
     */
    public function __construct(
        public string $file,
        public string $relativePath,
        public array $edits,
        public array $reasons = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->edits === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'file' => $this->relativePath,
            'edits' => count($this->edits),
            'reasons' => $this->reasons,
            'splices' => array_map(fn (PlannedEdit $e) => $e->toArray(), $this->edits),
        ];
    }
}
