<?php

namespace Rushing\Surgeon\Audit;

/**
 * What the audit hunts for — the operation-agnostic input to {@see AuditEngine}.
 *
 * A target is a *predicate over fully-qualified names* ({@see self::matches()}), plus an OPTIONAL
 * relocation destination. Keeping the destination optional is what keeps the engine target-general:
 *
 *  - With a destination ({@see self::relocatingTo()}), the audit is the pre-pass for a *move* —
 *    cycle-risk is computed (would repointing a referrer at the new home create an upward composer
 *    edge?), and Tier-1 references carry the `new` FQN they will splice to.
 *  - Without one ({@see self::find()}), the audit is a pure *insight* hunt — enumerate and classify
 *    every touch-point so an agent's search collapses into a triaged finding-set, no move implied.
 *
 * `new` is canonical by definition (ticket 02): the audit only ever moves *toward* canonical, so a
 * destination is a single `newFqn`; there is no third authored identifier.
 */
class Target
{
    /**
     * @param  string|null  $newFqn  the canonical destination FQN when this target relocates (else null)
     */
    protected function __construct(
        public TargetKind $kind,
        public string $value,
        public ?string $newFqn = null,
    ) {}

    /** An exact-symbol insight target (no relocation). */
    public static function symbol(string $fqn): self
    {
        return new self(TargetKind::Symbol, self::normalize($fqn));
    }

    /** A namespace-prefix insight target (matches the prefix and everything under it). */
    public static function namespace(string $prefix): self
    {
        return new self(TargetKind::Namespace, self::normalize($prefix));
    }

    /** A regex insight target, matched against the whole FQN. */
    public static function pattern(string $regex): self
    {
        return new self(TargetKind::Pattern, $regex);
    }

    /** A directory-scope target (restricts the scan; carries no FQN match predicate). */
    public static function directory(string $path): self
    {
        return new self(TargetKind::Directory, $path);
    }

    /**
     * A relocation target — an exact symbol `$oldFqn` moving to canonical `$newFqn`. This is the
     * one move-pair atom (ticket 02); a campaign is a batch of these.
     */
    public static function relocatingTo(string $oldFqn, string $newFqn): self
    {
        return new self(TargetKind::Symbol, self::normalize($oldFqn), self::normalize($newFqn));
    }

    public function relocates(): bool
    {
        return $this->newFqn !== null;
    }

    /**
     * Does this target match a referenced fully-qualified name? Names are compared leading-slash
     * insensitively; a namespace target matches its own prefix and any child under it.
     */
    public function matches(string $fqn): bool
    {
        $fqn = self::normalize($fqn);

        return match ($this->kind) {
            TargetKind::Symbol => $fqn === $this->value,
            TargetKind::Namespace => $fqn === $this->value || str_starts_with($fqn, $this->value.'\\'),
            TargetKind::Pattern => (bool) @preg_match($this->value, $fqn),
            TargetKind::Directory => false,
        };
    }

    /**
     * The canonical destination for a matched reference, if relocating. For a symbol target it is
     * the whole `newFqn`; for a namespace target it is a prefix swap (`old\Sub` → `new\Sub`).
     */
    public function destinationFor(string $matchedFqn): ?string
    {
        if ($this->newFqn === null) {
            return null;
        }

        $matchedFqn = self::normalize($matchedFqn);

        if ($this->kind === TargetKind::Namespace && $matchedFqn !== $this->value) {
            return $this->newFqn.substr($matchedFqn, strlen($this->value));
        }

        return $this->newFqn;
    }

    /** Strip a single leading namespace separator so `\Foo\Bar` and `Foo\Bar` compare equal. */
    public static function normalize(string $fqn): string
    {
        return ltrim($fqn, '\\');
    }
}
