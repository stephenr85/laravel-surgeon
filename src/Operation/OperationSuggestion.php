<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\Finding;

/**
 * The bridge value the fix-half carries beside a diagnosis (ticket 07, decision 2). A concept-owning
 * package's audit emits, next to each fixable {@see Finding}, an `OperationSuggestion`
 * naming the deterministic {@see Operation} that would resolve it and the payload that operation needs.
 *
 * It is a PARALLEL structure, never a `Finding` field: doctor's `Finding` stays byte-for-byte moat-free
 * (ADR-0095). Surgeon owns this type; doctor never learns of it.
 *
 * **Named "operation," not "refactor"** (decision 2): canonicalize / lint / overlay-sync are operations
 * that aren't refactors (the ticket-08 widening), so the general noun is *operation*.
 *
 * Two shapes:
 *  - a real fix — {@see $kind} names an applicable operation, {@see $payload} carries its inputs;
 *  - an **advisory nomination** ({@see advisory()}) — the audit saw a repeated deterministic shape but no
 *    operation handles it yet. It names the topology-derived owning package (decision 7) and generates
 *    NO stub: deciding a repetition is a generalizable operation is the agent's judgment call, preserving
 *    the deterministic/agentic seam.
 */
class OperationSuggestion
{
    public const ADVISORY = 'advisory';

    /**
     * @param  string  $kind  the {@see Operation::kind()} this suggestion nominates, or {@see self::ADVISORY}
     * @param  string  $summary  a one-line human description of the suggested fix
     * @param  array<string, mixed>  $payload  operation-specific inputs (e.g. a relocation's old→new FQN pair)
     * @param  string|null  $owningPackage  for an advisory nomination, the topology-derived package that would own the audit/operation
     */
    public function __construct(
        public string $kind,
        public string $summary,
        public array $payload = [],
        public ?string $owningPackage = null,
    ) {}

    /**
     * A repeated deterministic shape with no operation yet — advisory only, no fix applied, no stub
     * generated. Names the package that would own such an operation (a dependency fact, not authorization).
     */
    public static function advisory(string $summary, string $owningPackage): self
    {
        return new self(self::ADVISORY, $summary, [], $owningPackage);
    }

    /** Whether this is an advisory nomination (no applicable operation) rather than a real, applyable fix. */
    public function isAdvisory(): bool
    {
        return $this->kind === self::ADVISORY;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'owning_package' => $this->owningPackage,
            'advisory' => $this->isAdvisory(),
        ];
    }
}
