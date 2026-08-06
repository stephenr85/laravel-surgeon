<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\Finding;

/**
 * The paired-return unit of the {@see SuggestsOperations} bridge (ticket 07, decision 4): a doctor
 * {@see Finding} (the diagnosis) beside an optional {@see OperationSuggestion} (the deterministic fix).
 *
 * **Pairing is a paired return — no keying.** `Finding` has no identity field, so string-keying a
 * suggestion to a finding by `check` was rejected as brittle (decision 4). Diagnosis + fix are authored
 * as one atomic thing and travel together. A null {@see $suggestion} is a diagnosis with no deterministic
 * fix (an ordinary `DoctorAudit` finding); an advisory suggestion is a repeated shape no operation yet
 * handles.
 *
 * The `*:doctor` command projects {@see $finding}; surgeon consumes the whole pair.
 */
class FixableFinding
{
    public function __construct(
        public Finding $finding,
        public ?OperationSuggestion $suggestion = null,
    ) {}

    /** A diagnosis carrying a real, applyable deterministic fix (not null, not advisory). */
    public function isFixable(): bool
    {
        return $this->suggestion !== null && ! $this->suggestion->isAdvisory();
    }

    /** A diagnosis carrying an advisory nomination (a deterministic-looking shape with no operation yet). */
    public function isAdvisory(): bool
    {
        return $this->suggestion !== null && $this->suggestion->isAdvisory();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'finding' => [
                'status' => $this->finding->status->value,
                'check' => $this->finding->check,
                'detail' => $this->finding->detail,
            ],
            'suggestion' => $this->suggestion?->toArray(),
        ];
    }
}
