<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Audit\Target;

/**
 * The full, resolved Tier-1 operation set for one relocation — the *previewable* output of
 * {@see RewritePlanner} and the *applyable* input to {@see SpliceApplier}.
 *
 * It is the concrete realisation of ticket 03's "explicit, resolved operation set": the writer
 * applies THIS, verbatim, and never re-audits or infers. It carries three things:
 *
 *  - {@see $edits} — every byte-splice to perform (across all files, all Tier-1 categories);
 *  - {@see $skips} — Tier-1 references it refused to splice, each with a reason (the guided handoff);
 *  - {@see $move} — the tool-owned physical `git mv` of the moved symbol's own file, if resolvable.
 */
class RewritePlan
{
    /**
     * The moved symbol's own physical file move — the FIRST move of the plan. For a single-symbol
     * relocation this is the only move; kept as a plain public property so every existing single-move
     * caller and test (`$plan->move->...`) is byte-identical.
     */
    public ?PhysicalMove $move;

    /**
     * A cluster relocation moves several files at once — one physical move per moving member — so the
     * plan carries the FULL list here. {@see $move} is `moves[0]`. For a single-symbol relocation this
     * holds exactly `[$move]` (or `[]`), so the singular path is unchanged.
     *
     * @var list<PhysicalMove>
     */
    public array $moves;

    /**
     * @param  list<PlannedEdit>  $edits
     * @param  list<SkippedReference>  $skips
     * @param  PhysicalMove|list<PhysicalMove>|null  $move  one move (single relocation) or a list of
     *                                                      moves (an atomic cluster) — see {@see $moves}
     */
    public function __construct(
        public Target $target,
        public array $edits,
        public array $skips,
        PhysicalMove|array|null $move = null,
        public ?string $unsupported = null,
    ) {
        $this->moves = $move === null ? [] : (is_array($move) ? array_values($move) : [$move]);
        $this->move = $this->moves[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->edits === [] && $this->moves === [];
    }

    /** A relocation this Tier-1 pass will not apply (e.g. a basename rename it can't fully honour). */
    public function isUnsupported(): bool
    {
        return $this->unsupported !== null;
    }

    /** @return array<string, list<PlannedEdit>> keyed by absolute file path, ready for the applier */
    public function editsByFile(): array
    {
        $groups = [];
        foreach ($this->edits as $edit) {
            $groups[$edit->file][] = $edit;
        }

        return $groups;
    }

    /**
     * Every file the plan writes to — the splice targets plus the physical-move source (its own
     * namespace-line splice lands before the `git mv`). Absolute paths, de-duplicated.
     *
     * @return list<string>
     */
    public function touchedFiles(): array
    {
        $files = array_map(fn (PlannedEdit $e) => $e->file, $this->edits);
        foreach ($this->moves as $move) {
            $files[] = $move->from;
        }

        return array_values(array_unique($files));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target' => [
                'old' => $this->target->value,
                'new' => $this->target->newFqn,
            ],
            'unsupported' => $this->unsupported,
            'summary' => [
                'edits' => count($this->edits),
                'files' => count($this->editsByFile()),
                'skips' => count($this->skips),
                'physical_move' => $this->move !== null,
                'physical_moves' => count($this->moves),
            ],
            'edits' => array_map(fn (PlannedEdit $e) => $e->toArray(), $this->edits),
            'skips' => array_map(fn (SkippedReference $s) => $s->toArray(), $this->skips),
            'move' => $this->move?->toArray(),
            'moves' => array_map(fn (PhysicalMove $m) => $m->toArray(), $this->moves),
        ];
    }
}
