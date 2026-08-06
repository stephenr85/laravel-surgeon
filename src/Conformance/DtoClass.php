<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Surgeon\Rewrite\DestinationDepAdvisor;

/**
 * A parsed read of one PHP class file, reduced to exactly the facts the two upstream-DTO conformance
 * audits (ticket 15) turn on — nothing more. It is a value object produced by {@see DtoClassReader};
 * both {@see UpstreamDtoAudit} (b1) and {@see StaleDownstreamDuplicateAudit} (b2) consume it, so the
 * single AST parse serves both audits.
 *
 * The four facts:
 *  - {@see $fqn} — the class's own fully-qualified name (its `namespace` + declared short name);
 *  - {@see $imports} — every `use` import resolved to its FQN (b1 asks: do these all point DOWN into
 *    exactly one downstream package?);
 *  - {@see $shape} — the class's PUBLIC shape (public props + method signatures) for b2's drift compare;
 *  - {@see $attributes} — the class-level attribute short names (e.g. `TypeScript`), so b2 can NAME the
 *    drift ("downstream drops `#[TypeScript]`") — the deterministic/agentic seam's human-facing detail.
 *
 * Deliberately shallow: it never reads method BODIES (b2 compares public *shape*, not behaviour — a
 * body diff is judgment the audit refuses to make), and it resolves imports by the file's own `use`
 * table, not a whole-project type resolution (b1's dependency read is the same shallow read
 * {@see DestinationDepAdvisor} does — enough to catch the common case).
 */
class DtoClass
{
    /**
     * @param  string  $fqn  the class's own fully-qualified name
     * @param  string  $file  absolute path to the file it was read from
     * @param  list<string>  $imports  every `use` import, as a fully-qualified name (no leading `\`)
     * @param  PublicShape  $shape  the public property + method-signature shape (for b2's drift compare)
     * @param  list<string>  $attributes  class-level attribute short names (e.g. `TypeScript`)
     */
    public function __construct(
        public string $fqn,
        public string $file,
        public array $imports,
        public PublicShape $shape,
        public array $attributes,
    ) {}

    /** The class's short name (last namespace segment) — the twin-detection key alongside the tail. */
    public function shortName(): string
    {
        $pos = strrpos($this->fqn, '\\');

        return $pos === false ? $this->fqn : substr($this->fqn, $pos + 1);
    }

    /**
     * The class's namespace TAIL beyond a given root prefix — e.g. `App\Data\BeamUxEntryBodyData` with
     * root `App\` yields `Data\BeamUxEntryBodyData`. This is the twin key: a downstream package ships the
     * same tail under its OWN root (`Splicewire\Beam\Ux\Data\BeamUxEntryBodyData` shares the
     * `…\Data\BeamUxEntryBodyData` tail once you strip each side's package root). We compare on the
     * short name + the immediate parent segment so `Data\X` twins `Data\X`, not merely `X`.
     */
    public function namespaceTail(int $segments = 2): string
    {
        $parts = explode('\\', $this->fqn);
        $tail = array_slice($parts, -$segments);

        return implode('\\', $tail);
    }

    /** Whether the class carries a class-level attribute of the given short name (e.g. `TypeScript`). */
    public function hasAttribute(string $shortName): bool
    {
        return in_array($shortName, $this->attributes, true);
    }
}
