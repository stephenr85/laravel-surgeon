<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * The outcome of the deterministic post-write gate — one line per stage (pass / fail / skipped),
 * plus the overall verdict. A single failed stage fails the gate and triggers the manifest-scoped
 * auto-rollback; a *skipped* stage (a guarded stage not applicable in this context, e.g. no composer
 * binary, topology guard not installed) is not a failure — the tool reports honestly what it could
 * and couldn't verify rather than pretending a guarantee it didn't run.
 */
class GateResult
{
    /** @var list<array{stage: string, status: 'pass'|'fail'|'skip', detail: string}> */
    public array $stages = [];

    public function record(string $stage, string $status, string $detail = ''): void
    {
        $this->stages[] = compact('stage', 'status', 'detail');
    }

    public function passed(): bool
    {
        foreach ($this->stages as $stage) {
            if ($stage['status'] === 'fail') {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{stage: string, status: string, detail: string}> */
    public function failures(): array
    {
        return array_values(array_filter($this->stages, fn (array $s) => $s['status'] === 'fail'));
    }
}
