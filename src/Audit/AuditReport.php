<?php

namespace Rushing\Surgeon\Audit;

use Rushing\Doctor\Finding;

/**
 * The machine-readable result of one audit — the structured finding-set that collapses an agent's
 * hunt and, for a relocation, feeds the rewriters and the Tier-3 advisory handoff.
 *
 * It carries the full {@see Reference} list plus category / tier / file groupings, and exposes the
 * subset flagged for cycle risk. Two serialisations:
 *
 *  - {@see self::toArray()} — the native, span-annotated JSON shape (the rewriters' input).
 *  - {@see self::toDoctorFindings()} — the same result projected into `rushing/laravel-doctor`'s
 *    `Finding` vocabulary (Pass/Warn/Fail + check + detail), so the audit reports through the SAME
 *    channel every `*:doctor` command already speaks (ticket 07 grounding). The rich span data rides
 *    *beside* that projection in `toArray()`, never inside the moat-free `Finding` primitive.
 */
class AuditReport
{
    /**
     * @param  Target  $target  what was hunted
     * @param  list<string>  $roots  the scan roots
     * @param  list<Reference>  $references  every touch-point found
     */
    public function __construct(
        public Target $target,
        public array $roots,
        public array $references,
    ) {}

    /** @return list<Reference> */
    public function references(): array
    {
        return $this->references;
    }

    public function count(): int
    {
        return count($this->references);
    }

    public function isEmpty(): bool
    {
        return $this->references === [];
    }

    /** @return array<string, list<Reference>> keyed by {@see ReferenceCategory} value */
    public function byCategory(): array
    {
        return $this->groupBy(fn (Reference $r) => $r->category->value);
    }

    /** @return array<string, list<Reference>> keyed by {@see Tier} value */
    public function byTier(): array
    {
        return $this->groupBy(fn (Reference $r) => $r->tier->value);
    }

    /** @return array<string, list<Reference>> keyed by relative file path */
    public function byFile(): array
    {
        return $this->groupBy(fn (Reference $r) => $r->relativePath);
    }

    /** @return list<Reference> only the references that would close an upward composer edge */
    public function cycleRisks(): array
    {
        return array_values(array_filter($this->references, fn (Reference $r) => $r->hasCycleRisk()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $countBy = fn (array $groups) => array_map('count', $groups);

        return [
            'target' => [
                'kind' => $this->target->kind->value,
                'value' => $this->target->value,
                'new' => $this->target->newFqn,
            ],
            'roots' => $this->roots,
            'summary' => [
                'total' => $this->count(),
                'by_tier' => $countBy($this->byTier()),
                'by_category' => $countBy($this->byCategory()),
                'cycle_risks' => count($this->cycleRisks()),
            ],
            'references' => array_map(fn (Reference $r) => $r->toArray(), $this->references),
        ];
    }

    /**
     * Project the result into doctor's `Finding` vocabulary — one summary finding per tier, one
     * Fail per cycle risk, and a Pass when the hunt is clean. This is the audit's *conformance-shaped*
     * face; the rewriters read {@see self::toArray()} instead.
     *
     * @return list<Finding>
     */
    public function toDoctorFindings(): array
    {
        $findings = [];
        $check = 'surgeon:audit '.$this->target->value;

        if ($this->isEmpty()) {
            return [Finding::pass($check, 'No touch-points found across '.count($this->roots).' root(s).')];
        }

        foreach ($this->byTier() as $tierValue => $refs) {
            $tier = Tier::from($tierValue);
            $files = count(array_unique(array_map(fn (Reference $r) => $r->relativePath, $refs)));
            $findings[] = Finding::warn(
                $check.' — '.$tier->label(),
                count($refs).' touch-point(s) across '.$files.' file(s) — '.$this->categorySummary($refs),
            );
        }

        foreach ($this->cycleRisks() as $risk) {
            $findings[] = Finding::fail(
                $check.' — cycle risk',
                $risk->relativePath.':'.$risk->line.' — '.$risk->cycleRisk,
            );
        }

        return $findings;
    }

    /**
     * @param  callable(Reference): string  $key
     * @return array<string, list<Reference>>
     */
    private function groupBy(callable $key): array
    {
        $groups = [];
        foreach ($this->references as $ref) {
            $groups[$key($ref)][] = $ref;
        }

        return $groups;
    }

    /** @param list<Reference> $refs */
    private function categorySummary(array $refs): string
    {
        $counts = [];
        foreach ($refs as $ref) {
            $counts[$ref->category->value] = ($counts[$ref->category->value] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $cat => $n) {
            $parts[] = $n.'× '.$cat;
        }

        return implode(', ', $parts);
    }
}
