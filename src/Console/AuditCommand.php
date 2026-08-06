<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\Tier;

/**
 * `surgeon:audit` — the read-only insight command (ticket 03's read path; NO git-clean precondition,
 * because it never writes). It runs the {@see AuditEngine} over one or more roots and reports every
 * touch-point of a target, grouped by tier, with cycle risk flagged for a relocation.
 *
 * Targets are operation-agnostic — pick exactly one of `--symbol`, `--namespace`, `--pattern`,
 * `--dir`. Add `--to` (with `--symbol`/`--namespace`) to make it a *relocation* pre-pass: the
 * canonical destination each Tier-1 reference would splice to, and the cycle-risk indicator, light up.
 * `--json` emits the machine-readable finding-set the rewriters and the Tier-3 handoff consume.
 */
class AuditCommand extends Command
{
    protected $signature = 'surgeon:audit
        {--symbol= : Audit references to one exact fully-qualified name}
        {--namespace= : Audit references to a namespace prefix and everything under it}
        {--pattern= : Audit references whose FQN matches this regex}
        {--dir= : Audit inbound references to every symbol declared under this directory}
        {--to= : Canonical destination FQN — turns the audit into a relocation pre-pass (cycle risk)}
        {--root=* : Root directories to scan (absolute or cwd-relative); defaults to the app base path}
        {--json : Emit the machine-readable finding-set as JSON instead of a table}
        {--out= : Write the JSON finding-set to this file (implies --json)}';

    protected $description = 'Enumerate and classify every touch-point of a symbol / namespace / directory across the configured roots (read-only).';

    public function handle(): int
    {
        try {
            $target = $this->resolveTarget();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $roots = $this->resolveRoots();
        if ($roots === []) {
            $this->error('No valid --root directories resolved.');

            return self::INVALID;
        }

        $graph = PackageGraph::fromRoots($roots, $this->vendorPath($roots));
        $report = (new AuditEngine)->audit($roots, $target, $graph);

        if ($this->option('json') || $this->option('out')) {
            return $this->emitJson($report);
        }

        return $this->emitTable($report);
    }

    private function resolveTarget(): Target
    {
        $chosen = array_filter([
            'symbol' => $this->option('symbol'),
            'namespace' => $this->option('namespace'),
            'pattern' => $this->option('pattern'),
            'dir' => $this->option('dir'),
        ], fn ($v) => $v !== null && $v !== '');

        if (count($chosen) !== 1) {
            throw new \InvalidArgumentException('Pass exactly one of --symbol, --namespace, --pattern, --dir.');
        }

        $to = $this->option('to');
        if ($to !== null && $to !== '' && ! isset($chosen['symbol']) && ! isset($chosen['namespace'])) {
            throw new \InvalidArgumentException('--to (relocation) requires --symbol or --namespace.');
        }

        return match (array_key_first($chosen)) {
            'symbol' => $to ? Target::relocatingTo($chosen['symbol'], $to) : Target::symbol($chosen['symbol']),
            'namespace' => $to ? $this->namespaceMove($chosen['namespace'], $to) : Target::namespace($chosen['namespace']),
            'pattern' => Target::pattern($chosen['pattern']),
            'dir' => Target::directory($this->absolutePath($chosen['dir'])),
        };
    }

    private function namespaceMove(string $old, string $new): Target
    {
        $target = Target::namespace($old);
        $target->newFqn = Target::normalize($new);

        return $target;
    }

    /** @return list<string> */
    private function resolveRoots(): array
    {
        $roots = (array) $this->option('root');
        if ($roots === []) {
            $roots = [$this->laravel->basePath()];
        }

        $resolved = [];
        foreach ($roots as $root) {
            $abs = $this->absolutePath((string) $root);
            if (is_dir($abs)) {
                $resolved[] = rtrim($abs, '/');
            } else {
                $this->warn("skipping non-directory root: {$root}");
            }
        }

        return array_values(array_unique($resolved));
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $real = realpath($path);

        return $real !== false ? $real : rtrim(getcwd(), '/').'/'.$path;
    }

    /** @param list<string> $roots */
    private function vendorPath(array $roots): ?string
    {
        foreach ($roots as $root) {
            if (is_dir($root.'/vendor')) {
                return $root.'/vendor';
            }
        }

        $appVendor = $this->laravel->basePath('vendor');

        return is_dir($appVendor) ? $appVendor : null;
    }

    private function emitJson(AuditReport $report): int
    {
        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $out = $this->option('out');
        if ($out !== null && $out !== '') {
            file_put_contents($this->absolutePath($out), $json.PHP_EOL);
            $this->info("wrote finding-set → {$out}");
        } else {
            $this->line($json);
        }

        return $report->cycleRisks() === [] ? self::SUCCESS : self::FAILURE;
    }

    private function emitTable(AuditReport $report): int
    {
        if ($report->isEmpty()) {
            $this->info('surgeon:audit — no touch-points found for '.$report->target->value.'.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:audit — '.$report->target->value.'</>'
            .($report->target->relocates() ? ' → '.$report->target->newFqn : ''));
        $this->line(str_repeat('─', 60));

        foreach ($report->byTier() as $tierValue => $refs) {
            $tier = Tier::from($tierValue);
            $this->line('');
            $this->line('  <options=bold>'.$tier->label().'</> ('.count($refs).')');
            foreach ($refs as $ref) {
                $flag = $ref->hasCycleRisk() ? ' <fg=red>⟳ CYCLE</>' : '';
                $this->line(sprintf(
                    '    <fg=cyan>%s</> %s:%d  <fg=gray>%s</>%s',
                    $ref->category->value,
                    $ref->relativePath,
                    $ref->line,
                    trim($ref->snippet),
                    $flag,
                ));
                if ($ref->hasCycleRisk()) {
                    $this->line('        <fg=red>'.$ref->cycleRisk.'</>');
                }
            }
        }

        $this->line('');
        $this->line(str_repeat('─', 60));
        foreach ($report->toDoctorFindings() as $finding) {
            $tag = match ($finding->status) {
                DoctorStatus::Pass => '<fg=green>PASS</>',
                DoctorStatus::Warn => '<fg=yellow>WARN</>',
                DoctorStatus::Fail => '<fg=red>FAIL</>',
            };
            $this->line("  {$tag} {$finding->detail}");
        }
        $this->line('');

        return $report->cycleRisks() === [] ? self::SUCCESS : self::FAILURE;
    }
}
