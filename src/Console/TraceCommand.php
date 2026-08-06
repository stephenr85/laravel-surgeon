<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Audit\MovingSet;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\Tier;

/**
 * `surgeon:trace` — the read-only, TARGET-DRIVEN insight command (ticket 03's read path; NO git-clean
 * precondition, because it never writes). It runs the {@see AuditEngine} over one or more roots and
 * reports every touch-point of a target, grouped by tier, with cycle risk flagged for a relocation.
 *
 * This was `surgeon:audit` (ticket 05); ticket 07 (decision 8) inverted the surface — the broad
 * *conformance sweep* over the doctor manifest took the `surgeon:audit` name, so the targeted hunt is
 * `surgeon:trace`. The two are distinct paths (decision 5): trace is target-driven (you point it at an
 * FQN, nothing to discover); audit is discovery-driven (it sweeps the registered manifest).
 *
 * Targets are operation-agnostic — pick exactly one of `--symbol`, `--namespace`, `--pattern`,
 * `--dir`. Add `--to` (with `--symbol`/`--namespace`) to make it a *relocation* pre-pass: the
 * canonical destination each Tier-1 reference would splice to, and the cycle-risk indicator, light up.
 * `--json` emits the machine-readable finding-set the rewriters and the Tier-3 handoff consume.
 */
class TraceCommand extends Command
{
    protected $signature = 'surgeon:trace
        {--symbol=* : Audit references to an exact fully-qualified name (repeatable — a set of symbols moving together)}
        {--namespace=* : Audit references to a namespace prefix and everything under it (repeatable)}
        {--pattern= : Audit references whose FQN matches this regex}
        {--dir= : Audit inbound references to every symbol declared under this directory}
        {--to= : Canonical destination FQN — turns the audit into a relocation pre-pass (cycle risk). With multiple --symbol/--namespace it is the ONE destination the whole ATOMIC CLUSTER lands in.}
        {--root=* : Root directories to scan (absolute or cwd-relative); defaults to the app base path}
        {--json : Emit the machine-readable finding-set as JSON instead of a table}
        {--out= : Write the JSON finding-set to this file (implies --json)}';

    protected $description = 'Enumerate and classify every touch-point of a symbol / namespace / directory across the configured roots (read-only).';

    public function handle(): int
    {
        try {
            [$target, $movingSet] = $this->resolveTarget();
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
        $report = (new AuditEngine)->audit($roots, $target, $graph, $movingSet);

        if ($this->option('json') || $this->option('out')) {
            return $this->emitJson($report);
        }

        return $this->emitTable($report);
    }

    /**
     * Resolve the audit target and, for a relocation, its atomic {@see MovingSet} context.
     *
     * `--symbol` / `--namespace` are repeatable: with a single member the target is the plain lone
     * relocation (byte-identical to before, `MovingSet` null); with several members sharing one `--to`
     * they form an ATOMIC CLUSTER — one finding-set for the whole set, cycle-risk evaluated against the
     * post-move graph so intra-cluster references are ordinary repoints, not upward edges.
     *
     * @return array{0: Target, 1: MovingSet|null}
     */
    private function resolveTarget(): array
    {
        $symbols = $this->stringList('symbol');
        $namespaces = $this->stringList('namespace');
        $pattern = (string) ($this->option('pattern') ?? '');
        $dir = (string) ($this->option('dir') ?? '');

        $kindsUsed = ($symbols !== [] ? 1 : 0) + ($namespaces !== [] ? 1 : 0)
            + ($pattern !== '' ? 1 : 0) + ($dir !== '' ? 1 : 0);

        $to = (string) ($this->option('to') ?? '');

        // Pattern / dir are single-kind insight targets, never relocations and never combinable.
        if ($pattern !== '' || $dir !== '') {
            if ($kindsUsed !== 1) {
                throw new \InvalidArgumentException('--pattern / --dir cannot be combined with other target kinds.');
            }
            if ($to !== '') {
                throw new \InvalidArgumentException('--to (relocation) requires --symbol or --namespace.');
            }

            return [$pattern !== '' ? Target::pattern($pattern) : Target::directory($this->absolutePath($dir)), null];
        }

        if ($symbols === [] && $namespaces === []) {
            throw new \InvalidArgumentException('Pass exactly one of --symbol, --namespace, --pattern, --dir (--symbol/--namespace are repeatable for an atomic cluster).');
        }

        // An insight hunt (no --to): exactly one member of one kind, matching the historical contract.
        if ($to === '') {
            $memberCount = count($symbols) + count($namespaces);
            if ($memberCount !== 1) {
                throw new \InvalidArgumentException('Multiple --symbol/--namespace need a shared --to (an atomic cluster-move); an insight hunt takes exactly one target.');
            }

            return [$symbols !== [] ? Target::symbol($symbols[0]) : Target::namespace($namespaces[0]), null];
        }

        // A single lone relocation stays byte-identical to the historical contract: `--to` is the
        // VERBATIM new FQN (a full-FQN move OR a basename rename), never basename-appended, and carries
        // no MovingSet — every single-symbol / single-namespace behaviour is preserved exactly.
        if (count($symbols) + count($namespaces) === 1) {
            return [
                $symbols !== []
                    ? Target::relocatingTo($symbols[0], $to)
                    : $this->namespaceMove($namespaces[0], $to),
                null,
            ];
        }

        // Two or more members (or a symbol + namespace) form the atomic cluster moving to the shared
        // `--to` destination namespace — each member lands as `<to>\<basename>` / a prefix-swap.
        $movingSet = MovingSet::of($symbols, $namespaces, $to);

        return [$movingSet->toTarget(), $movingSet];
    }

    private function namespaceMove(string $old, string $new): Target
    {
        $target = Target::namespace($old);
        $target->newFqn = Target::normalize($new);

        return $target;
    }

    /**
     * A repeatable string option as a clean list (drops empties). Laravel gives `=*` options an array;
     * a bare `= ` legacy single value also arrives here as a one-element array.
     *
     * @return list<string>
     */
    private function stringList(string $name): array
    {
        return array_values(array_filter(
            array_map(fn ($v) => (string) $v, (array) $this->option($name)),
            fn (string $v) => $v !== '',
        ));
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
            $this->info('surgeon:trace — no touch-points found for '.$report->target->value.'.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:trace — '.$report->target->value.'</>'
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
