<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Operation\ConformanceAuditResult;
use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceReport;
use Rushing\Surgeon\Operation\ConformanceSweep;

/**
 * `surgeon:audit` — the CONFORMANCE SWEEP over the host's registered doctor manifest (ticket 07,
 * decision 8: Stephen inverted the naming so `audit` = the broad sweep, `trace` = the targeted hunt).
 *
 * It is the fix-half read of the doctor manifest that matches the destination sentence. It:
 *  1. reuses doctor's merged manifest ({@see ConformanceManifest} — the host binds the adapter) as the
 *     discovery substrate (decision 5), scoped by the running host context (decision 6 — no tier gate);
 *  2. runs surgeon's OWN built-in audits ({@see BuiltInAudits} — b1 promotion-candidate + b2
 *     stale-downstream-duplicate, ticket 15) ALONGSIDE the host registrations, scoped to the running
 *     host's app root (`base_path()`) — they're surgeon-native, so they ride a parallel channel, not the
 *     host's manifest;
 *  3. dedupes registrations by audit class-string and runs each audit ({@see ConformanceSweep});
 *  4. collects `Finding`s + paired `OperationSuggestion`s and reports the fixable subset.
 *
 * The **agent-nomination footer** (decision 7): one line by default (fixable count + the `surgeon:*`
 * command family); the full `OperationSuggestion` surface behind `-v`; a repeated deterministic shape
 * with no operation renders as an **advisory** line naming the topology-derived owning package — with
 * NO stub generation (deciding a repetition is a generalizable operation is the agent's call).
 *
 * Read-only: the sweep diagnoses and nominates; applying a fix is `surgeon:move` (or a future op).
 */
class AuditCommand extends Command
{
    protected $signature = 'surgeon:audit
        {--json : Emit the machine-readable sweep (findings + suggestions) as JSON instead of a table}
        {--floor=fail : Gate severity floor (pass|warn|fail) — a gate finding AT or ABOVE it turns the exit code red}';

    protected $description = 'Sweep the registered doctor manifest, reporting conformance findings and the deterministic operations that fix them (read-only).';

    public function handle(ConformanceManifest $manifest): int
    {
        $floor = DoctorStatus::tryFrom(strtolower((string) $this->option('floor')));

        if ($floor === null) {
            $this->error('Invalid --floor value ['.$this->option('floor').'] — expected pass, warn, or fail.');

            return self::FAILURE;
        }

        $builtIn = (new BuiltInAudits($this->laravel->basePath()))->audits();
        $report = (new ConformanceSweep(fn (string $audit) => $this->laravel->make($audit)))->run($manifest, $builtIn);

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report->hasGateFailure($floor) ? self::FAILURE : self::SUCCESS;
        }

        return $this->emitTable($report, $floor);
    }

    private function emitTable(ConformanceReport $report, DoctorStatus $floor): int
    {
        $this->line('');
        $this->line('<options=bold>surgeon:audit — conformance sweep</> ('.$report->auditCount().' audit(s))');

        if ($report->auditCount() === 0) {
            $this->line(str_repeat('─', 60));
            $this->line('  <fg=gray>No conformance audits registered in this host context.</>');
            $this->line('  <fg=gray>A host exposes its doctor manifest by binding a ConformanceManifest adapter.</>');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line(str_repeat('─', 60));

        foreach ($report->results as $result) {
            $this->renderAudit($result);
        }

        $this->line('');
        $this->line(str_repeat('─', 60));
        $this->renderAdvisories($report);
        $this->renderFooter($report);
        $this->line('');

        return $report->hasGateFailure($floor) ? self::FAILURE : self::SUCCESS;
    }

    private function renderAudit(ConformanceAuditResult $result): void
    {
        $this->line('');
        $this->line('  <options=bold>'.$this->shortName($result->audit).'</> <fg=gray>('.$result->package
            .($result->gate ? ', gate' : ', advisory').')</> — '.count($result->fixables).' finding(s)');

        foreach ($result->fixables as $fixable) {
            $tag = $this->statusTag($fixable->finding->status);
            $fix = $fixable->isFixable() ? ' <fg=green>[fixable: '.$fixable->suggestion->kind.']</>' : '';
            $this->line("    {$tag} {$fixable->finding->detail}{$fix}");

            if ($fixable->isFixable() && $this->getOutput()->isVerbose()) {
                $this->line('        <fg=cyan>→ '.$fixable->suggestion->summary.'</>');
                if ($fixable->suggestion->payload !== []) {
                    $this->line('        <fg=gray>'.json_encode($fixable->suggestion->payload, JSON_UNESCAPED_SLASHES).'</>');
                }
            }
        }
    }

    private function renderAdvisories(ConformanceReport $report): void
    {
        $advisories = $report->advisory();
        if ($advisories === []) {
            return;
        }

        $this->line('  <fg=yellow>Advisory — deterministic-looking shapes with no operation yet:</>');
        foreach ($advisories as $fixable) {
            $owner = $fixable->suggestion->owningPackage;
            $this->line('    <fg=yellow>◇</> '.$fixable->suggestion->summary
                .($owner !== null ? " <fg=gray>— consider contributing an operation to {$owner}</>" : ''));
        }
        $this->line('');
    }

    private function renderFooter(ConformanceReport $report): void
    {
        $fixable = $report->fixableCount();
        $advisory = $report->advisoryCount();

        if ($fixable === 0 && $advisory === 0) {
            $this->line('  <fg=green>No deterministic operations nominated.</> Run <fg=cyan>surgeon:trace</> / <fg=cyan>surgeon:move</> for targeted relocations.');

            return;
        }

        $this->line('  <options=bold>'.$fixable.'</> fixable via the <fg=cyan>surgeon:*</> family (surgeon:move / surgeon:trace)'
            .($advisory > 0 ? ', <options=bold>'.$advisory.'</> advisory nomination(s)' : '')
            .($this->getOutput()->isVerbose() ? '.' : ' — re-run with <fg=cyan>-v</> for the full operation surface.'));
    }

    private function statusTag(DoctorStatus $status): string
    {
        return match ($status) {
            DoctorStatus::Pass => '<fg=green>PASS</>',
            DoctorStatus::Warn => '<fg=yellow>WARN</>',
            DoctorStatus::Fail => '<fg=red>FAIL</>',
        };
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
