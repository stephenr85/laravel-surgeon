<?php

namespace Rushing\Surgeon\Audit;

/**
 * An ATOMIC CLUSTER-MOVE declaration — a *set* of symbols (and/or namespace prefixes) that relocate
 * TOGETHER to one shared destination, evaluated as a single post-move graph.
 *
 * The gap this closes: `surgeon:trace` evaluates ONE {@see Target} at a time, so when a cohesive
 * cluster moves together — each member referencing its siblings — tracing each member in isolation
 * flags every sibling reference as cycle-risk / upward edge (the source package still owns the sibling
 * *at audit time*, so the destination looks "upward"). But because ALL siblings land in the
 * destination together, the post-move graph is destination-internal and legal.
 *
 * A `MovingSet` is the missing context: it knows every FQN that is *itself moving*, so the audit can
 * reframe cycle-risk against the POST-MOVE package of BOTH endpoints. A reference from one moving
 * member to another moving member is an ordinary intra-destination repoint, not a cross-tier edge;
 * only a reference between a moving member and a symbol OUTSIDE the set is tier-checked.
 *
 * It composes a plain relocation {@see Target} ({@see toTarget()}) for the matching/rewrite machinery —
 * a single member degrades to exactly the existing single-symbol {@see Target::relocatingTo()} /
 * namespace move, so every single-symbol behaviour is preserved by construction.
 */
class MovingSet
{
    /**
     * @param  list<Target>  $members  each a relocation target (symbol or namespace) sharing $destination
     * @param  string  $destination  the shared destination namespace/FQN root the whole cluster lands in
     */
    protected function __construct(
        public array $members,
        public string $destination,
    ) {}

    /**
     * Build a moving set from repeated `--symbol` values and/or `--namespace` prefixes, all sharing one
     * `--to` destination.
     *
     *  - a symbol `Old\Foo` with destination `Dest` maps to `Dest\Foo` (basename appended);
     *  - a symbol `Old\Foo` with a fully-qualified destination `Dest\Foo` maps as-is;
     *  - a namespace prefix `Old\Sub` maps `Old\Sub\X` → `Dest\Sub\X` (prefix swap, existing semantics).
     *
     * @param  list<string>  $symbols
     * @param  list<string>  $namespaces
     */
    public static function of(array $symbols, array $namespaces, string $destination): self
    {
        $destination = Target::normalize($destination);
        $members = [];

        foreach ($symbols as $symbol) {
            $symbol = Target::normalize($symbol);
            // A bare destination (a namespace root the members land under) gets the member's basename
            // appended; a destination that already spells the member's basename is used verbatim.
            $to = self::destinationForSymbol($symbol, $destination);
            $members[] = Target::relocatingTo($symbol, $to);
        }

        foreach ($namespaces as $namespace) {
            $namespace = Target::normalize($namespace);
            $target = Target::namespace($namespace);
            $target->newFqn = $destination;
            $members[] = $target;
        }

        return new self($members, $destination);
    }

    /** A single-member set — the degenerate case that must behave exactly like a lone relocation. */
    public static function single(Target $member): self
    {
        return new self([$member], (string) $member->newFqn);
    }

    public function isSingle(): bool
    {
        return count($this->members) === 1;
    }

    /**
     * Compose the whole set into ONE relocation {@see Target} the audit matches against. A single
     * member returns itself verbatim (so single-symbol matching/rewrite is byte-identical); multiple
     * members fold into a pattern that matches any member (and its sub-namespace), while a
     * {@see MovingSetTarget} carries the per-member destination each matched FQN rewrites to.
     */
    public function toTarget(): Target
    {
        if ($this->isSingle()) {
            return $this->members[0];
        }

        return MovingSetTarget::over($this->members);
    }

    /**
     * Would a reference to $fqn be to a symbol that is ITSELF moving in this set? (Either an exact
     * member, or under a member's namespace prefix.) A reference to such a symbol lands in the
     * destination together with the referrer — the intra-cluster case the cycle-guard must forgive.
     */
    public function contains(string $fqn): bool
    {
        foreach ($this->members as $member) {
            if ($member->matches($fqn)) {
                return true;
            }
        }

        return false;
    }

    private static function destinationForSymbol(string $symbol, string $destination): string
    {
        $basename = self::basenameOf($symbol);

        return self::basenameOf($destination) === $basename
            ? $destination
            : $destination.'\\'.$basename;
    }

    private static function basenameOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
