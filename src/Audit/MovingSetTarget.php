<?php

namespace Rushing\Surgeon\Audit;

/**
 * A relocation {@see Target} that matches ANY member of an atomic {@see MovingSet} and dispatches each
 * matched FQN's destination to the member that owns it. It is what {@see MovingSet::toTarget()} hands
 * the {@see AuditEngine} when a cluster has more than one member, so the whole set produces ONE
 * finding-set in a single audit pass (instead of one isolated pass per member).
 *
 * It behaves as a {@see TargetKind::Symbol} relocation for serialization (`value`/`newFqn` name the
 * whole set legibly), but its match/destination predicate is the *union* of its members: this is the
 * one place the "which member does this reference belong to" routing lives, keeping the collector and
 * rewriter member-agnostic.
 */
class MovingSetTarget extends Target
{
    /** @var list<Target> */
    private array $members;

    /**
     * @param  list<Target>  $members  each a relocation target (symbol or namespace) in the set
     */
    public static function over(array $members): self
    {
        // `value`/`newFqn` describe the whole set for reporting; the real routing is per-member below.
        $values = array_map(fn (Target $m) => $m->value, $members);
        $destinations = array_values(array_unique(array_map(fn (Target $m) => (string) $m->newFqn, $members)));

        $target = new self(
            TargetKind::Symbol,
            '{'.implode(', ', $values).'}',
            count($destinations) === 1 ? $destinations[0] : '{'.implode(', ', $destinations).'}',
        );
        $target->members = array_values($members);

        return $target;
    }

    public function matches(string $fqn): bool
    {
        foreach ($this->members as $member) {
            if ($member->matches($fqn)) {
                return true;
            }
        }

        return false;
    }

    public function destinationFor(string $matchedFqn): ?string
    {
        foreach ($this->members as $member) {
            if ($member->matches($matchedFqn)) {
                return $member->destinationFor($matchedFqn);
            }
        }

        return null;
    }
}
