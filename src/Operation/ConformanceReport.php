<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\DoctorStatus;

/**
 * The aggregate result of a {@see ConformanceSweep}: every audit's {@see FixableFinding}s, plus the roll-up
 * counts `surgeon:audit` reports through. It is the fix-half read of the doctor manifest that matches the
 * destination sentence — "collect Findings + paired OperationSuggestions, report the fixable subset."
 */
class ConformanceReport
{
    /** @param list<ConformanceAuditResult> $results */
    public function __construct(
        public array $results,
    ) {}

    /** @return list<ConformanceAuditResult> */
    public function auditResults(): array
    {
        return $this->results;
    }

    public function auditCount(): int
    {
        return count($this->results);
    }

    /** @return list<FixableFinding> every pair across every audit, flattened */
    public function all(): array
    {
        return array_merge(...array_map(fn (ConformanceAuditResult $r) => $r->fixables, $this->results)) ?: [];
    }

    /** @return list<FixableFinding> the pairs carrying a real, applyable operation (the fixable subset) */
    public function fixable(): array
    {
        return array_values(array_filter($this->all(), fn (FixableFinding $f) => $f->isFixable()));
    }

    /** @return list<FixableFinding> the advisory nominations (deterministic shape, no operation yet) */
    public function advisory(): array
    {
        return array_values(array_filter($this->all(), fn (FixableFinding $f) => $f->isAdvisory()));
    }

    public function fixableCount(): int
    {
        return count($this->fixable());
    }

    public function advisoryCount(): int
    {
        return count($this->advisory());
    }

    public function findingCount(): int
    {
        return count($this->all());
    }

    /**
     * Whether the sweep should turn the exit code red: a Fail finding from a `gate` registration
     * (advisory registrations render Fail but never fail the code — mirrors the doctor gate/advisory split).
     */
    public function hasGateFailure(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->gate) {
                continue;
            }
            foreach ($result->fixables as $fixable) {
                if ($fixable->finding->status === DoctorStatus::Fail) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => [
                'audits' => $this->auditCount(),
                'findings' => $this->findingCount(),
                'fixable' => $this->fixableCount(),
                'advisory' => $this->advisoryCount(),
            ],
            'audits' => array_map(fn (ConformanceAuditResult $r) => $r->toArray(), $this->results),
        ];
    }
}
