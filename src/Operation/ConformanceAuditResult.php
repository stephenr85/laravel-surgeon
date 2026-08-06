<?php

namespace Rushing\Surgeon\Operation;

/**
 * One audit's contribution to a conformance sweep: the registering package, the audit class-string, its
 * gate/advisory flag, and the {@see FixableFinding} pairs it produced. The unit {@see ConformanceReport}
 * aggregates.
 */
class ConformanceAuditResult
{
    /** @param list<FixableFinding> $fixables */
    public function __construct(
        public string $package,
        public string $audit,
        public bool $gate,
        public array $fixables,
    ) {}

    /** @return list<FixableFinding> the diagnoses carrying a real, applyable operation */
    public function fixable(): array
    {
        return array_values(array_filter($this->fixables, fn (FixableFinding $f) => $f->isFixable()));
    }

    /** @return list<FixableFinding> the advisory nominations (deterministic shape, no operation yet) */
    public function advisory(): array
    {
        return array_values(array_filter($this->fixables, fn (FixableFinding $f) => $f->isAdvisory()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'audit' => $this->audit,
            'gate' => $this->gate,
            'findings' => array_map(fn (FixableFinding $f) => $f->toArray(), $this->fixables),
        ];
    }
}
