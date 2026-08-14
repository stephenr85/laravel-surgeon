<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Tier;
use Rushing\Surgeon\Operation\RelocationOperation;
use Rushing\Surgeon\Rewrite\DestinationDepAdvisor;
use Rushing\Surgeon\Rewrite\DeterministicGate;
use Rushing\Surgeon\Rewrite\FindingSetLoader;
use Rushing\Surgeon\Rewrite\PhysicalMove;
use Rushing\Surgeon\Rewrite\PlannedEdit;
use Rushing\Surgeon\Rewrite\RewritePlan;
use Rushing\Surgeon\Rewrite\SpliceApplier;
use Rushing\Surgeon\Rewrite\TouchManifest;

/**
 * `surgeon:move` — the Tier-1 WRITER (ticket 09), where the first fully-deterministic operation
 * (FQN-relocation) actually mutates source, under ticket 03's whole safety contract:
 *
 *  - **Resolved-set only, no inference.** It applies a `surgeon:trace --out` finding-set (`--from`);
 *    it never re-audits or infers intent. Insight and execution are separated by construction.
 *  - **Preview by default.** Without `--apply` it prints the plan and writes nothing.
 *  - **Named blast radius.** `--root` names which trees a write may touch; edits outside are refused
 *    (defaults to the finding-set's own roots — overlay membership makes `--root` ergonomic, never
 *    implicit).
 *  - **Clean-tree gate** per root (skippable with `--allow-dirty`, whose recovery is manifest-scoped).
 *  - **No auto-commit.** Changes are left in the tree with a touch-manifest sidecar as the review +
 *    scoped-undo surface.
 *  - **Deterministic post-write gate** (`php -l` → `dump-autoload` → topology) with manifest-scoped
 *    auto-rollback on failure — a failed pass leaves the tree exactly as found.
 *  - **Tests emitted, not run** — the derived filtered set is reported for the consumer to run against
 *    an isolated DB; the tool never owns DB isolation as a blocking step.
 */
class MoveCommand extends Command
{
    protected $signature = 'surgeon:move
        {--from= : Path to a surgeon:trace --out finding-set — the resolved operation set to apply}
        {--apply : Write the changes (default: preview only, no writes)}
        {--root=* : Roots this move may touch; edits outside are refused (defaults to the finding-set roots)}
        {--allow-dirty : Proceed even if a --root working tree is dirty (recovery stays manifest-scoped)}
        {--manifest= : Where to write the touch-manifest sidecar (default: <cwd>/.surgeon/move-manifest.json)}
        {--no-verify : Skip the deterministic post-write gate}
        {--no-composer : Within the gate, skip composer dump-autoload}';

    protected $description = 'Apply a Tier-1 FQN-relocation from a resolved audit finding-set — byte-splice + physical move, under the safety contract (preview by default).';

    /** Per-category cap on the Tier-2/3 handoff notice — a notice, not a second report. */
    private const GUIDED_PREVIEW_LIMIT = 5;

    public function handle(): int
    {
        $from = (string) $this->option('from');
        if ($from === '') {
            $this->error('surgeon:move needs --from=<audit finding-set JSON> (the resolved operation set).');

            return self::INVALID;
        }

        try {
            $loaded = FindingSetLoader::load($this->absolutePath($from));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        /** @var AuditReport $report */
        $report = $loaded['report'];
        $roots = $this->authorizedRoots($report);
        if ($roots === []) {
            $this->error('No valid roots to write to (pass --root or ensure the finding-set roots exist).');

            return self::INVALID;
        }

        $operation = new RelocationOperation($roots);
        $plan = $operation->plan($report);
        [$plan, $excluded] = $this->restrictToRoots($plan, $roots);

        if ($loaded['unresolved'] > 0) {
            $this->warn("{$loaded['unresolved']} reference(s) in the finding-set did not resolve under the given roots and were dropped.");
        }
        if ($excluded > 0) {
            $this->warn("{$excluded} edit(s) fell outside the named --root blast radius and were refused.");
        }

        if ($plan->isUnsupported()) {
            $this->warn('surgeon:move (Tier-1) will not apply this move:');
            $this->line('  '.$plan->unsupported);
            if ($this->option('apply')) {
                return self::FAILURE;
            }
            // Preview still shows the FQN-reference edits it WOULD make, for the guided path to build on.
            $this->preview($plan, $report);

            return self::FAILURE;
        }

        if ($plan->isEmpty()) {
            $this->info('surgeon:move — nothing to apply (no Tier-1 edits and no physical move).');
            $this->renderSkips($plan);

            return self::SUCCESS;
        }

        return $this->option('apply')
            ? $this->apply($plan, $report, $roots, $operation)
            : $this->preview($plan, $report);
    }

    private function preview(RewritePlan $plan, AuditReport $report): int
    {
        $this->line('');
        $this->line('<options=bold>surgeon:move — PREVIEW</> '.$plan->target->value.' → '.$plan->target->newFqn);
        $this->line('<fg=yellow>No files written. Re-run with --apply to write.</>');
        $this->renderPlan($plan);
        $this->renderGuidedReferences($report);
        $this->renderDestinationDepAdvisory($plan);
        $this->renderDerivedTests($report);

        return self::SUCCESS;
    }

    /** @param list<string> $roots */
    private function apply(RewritePlan $plan, AuditReport $report, array $roots, RelocationOperation $operation): int
    {
        if (! $this->option('allow-dirty')) {
            $dirty = $this->firstDirtyRoot($roots);
            if ($dirty !== null) {
                $this->error("refusing to write: working tree is dirty at {$dirty['root']}");
                $this->line($dirty['status']);
                $this->line('<fg=yellow>Commit/stash first, or pass --allow-dirty (recovery stays manifest-scoped).</>');

                return self::FAILURE;
            }
        }

        $applier = new SpliceApplier;
        try {
            $manifest = $operation->apply($plan, $applier);
        } catch (\RuntimeException $e) {
            $this->error('apply aborted before any write: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('no-verify')) {
            // The gate must run in the DESTINATION repo too for a cross-repo move (ticket 14) — php -l
            // on the moved file at its new home, dump-autoload/topology where the class now lives.
            $gate = (new DeterministicGate(runComposer: ! $this->option('no-composer')))
                ->run($manifest->touchedFiles(), $manifest->gateRoots($roots));

            foreach ($gate->stages as $stage) {
                $this->line('  '.$this->gateTag($stage['status'])." {$stage['stage']} — {$stage['detail']}");
            }

            if (! $gate->passed()) {
                $applier->rollback($manifest);
                $this->error('deterministic gate failed — rolled back (tree left exactly as found).');

                return self::FAILURE;
            }
        }

        $manifestPath = $this->writeManifest($manifest);
        $this->stageWithGit($manifest, $roots, $manifestPath);

        $this->line('');
        $this->info('surgeon:move applied: '.count($plan->edits).' splice(s) across '
            .count($plan->editsByFile()).' file(s)'.($plan->moves !== [] ? ' + '.count($plan->moves).' physical move(s)' : '').'.');
        $this->line("  touch-manifest → {$manifestPath}");
        $this->line('  <fg=yellow>Not committed.</> Review the staged diff; the manifest is the scoped-undo surface.');
        $this->renderSkips($plan);
        $this->renderGuidedReferences($report);
        $this->renderDestinationDepAdvisory($plan);
        $this->renderDerivedTests($report);

        return self::SUCCESS;
    }

    /**
     * The Tier-2/Tier-3 references the finding-set carries and this command will NEVER touch.
     *
     * `surgeon:move` is a Tier-1 writer by contract — Tier 2 is guided, Tier 3 is advisory, and the
     * audit deliberately does not promote across that seam. But the command used to say nothing about
     * them, so a run reported "applied: N splice(s)" and left the operator believing the relocation
     * was complete. It reported the Tier-1 refs it had to SKIP and the destination composer-dep
     * advisory, and stayed silent on every reference that was correctly classified out of its reach.
     *
     * That silence is not a small omission. A Tier-3 `route-config-reference` is a class-string in a
     * config seam — a stale one does not fail at boot, it fails the first time the seam is exercised,
     * which can be far from the move. The trace command lists these; the MOVE is where someone acts,
     * so it has to name them here too.
     *
     * Grouped by category with a count, and capped: this is a handoff notice, not a second report —
     * `surgeon:trace` remains the full listing.
     */
    private function renderGuidedReferences(AuditReport $report): void
    {
        $byCategory = [];
        foreach ($report->references() as $ref) {
            if ($ref->tier === Tier::One) {
                continue;
            }
            $byCategory[$ref->tier->value][$ref->category->value][] = $ref;
        }

        if ($byCategory === []) {
            return;
        }

        ksort($byCategory);
        $total = 0;
        foreach ($byCategory as $categories) {
            foreach ($categories as $refs) {
                $total += count($refs);
            }
        }

        $this->line('');
        $this->line('  <fg=yellow>Not touched by this command — '.$total.' reference(s) above Tier 1:</>');

        foreach ($byCategory as $tier => $categories) {
            ksort($categories);
            foreach ($categories as $category => $refs) {
                $this->line("    <options=bold>{$tier}</> {$category} (".count($refs).')');
                foreach (array_slice($refs, 0, self::GUIDED_PREVIEW_LIMIT) as $ref) {
                    $this->line("      <fg=gray>{$ref->relativePath}:{$ref->line}</>");
                }
                if (count($refs) > self::GUIDED_PREVIEW_LIMIT) {
                    $this->line('      <fg=gray>… '.(count($refs) - self::GUIDED_PREVIEW_LIMIT).' more</>');
                }
            }
        }

        $this->line('    <fg=gray>(surgeon:trace lists them in full — these need a human or a cleanup agent.)</>');
    }

    /**
     * Tier-3 composer-dep advisory (ticket 14) — after a cross-repo promotion, flag any vendor the moved
     * file imports that the destination package does not `require`. The exact tail that bit the manual
     * `SurgeonMcpServer` promotion (laravel/mcp + mcp-registry added by hand). We flag, never edit
     * composer: choosing the constraint is judgment — the deterministic/agentic seam, upheld.
     */
    private function renderDestinationDepAdvisory(RewritePlan $plan): void
    {
        $advisories = [];
        foreach ($plan->moves as $move) {
            if (! $move->crossRepo || $move->destinationRepo === null) {
                continue;
            }
            foreach ((new DestinationDepAdvisor)->advise($move->to, $move->destinationRepo) as $line) {
                $advisories[] = $line;
            }
        }
        $advisories = array_values(array_unique($advisories));
        if ($advisories === []) {
            return;
        }

        $this->line('');
        $this->line('  <fg=yellow>Tier-3 advisory — destination composer deps ('.count($advisories).'):</>');
        foreach ($advisories as $line) {
            $this->line('    <fg=gray>'.$line.'</>');
        }
        $this->line('    <fg=gray>(surgeon does not edit composer.json — add the deps and dump-autoload before committing.)</>');
    }

    // --- rendering ---------------------------------------------------------------------------------

    private function renderPlan(RewritePlan $plan): void
    {
        foreach ($plan->editsByFile() as $file => $edits) {
            $this->line('');
            $this->line('  <fg=cyan>'.$edits[0]->relativePath.'</> ('.count($edits).')');
            foreach ($edits as $edit) {
                $this->line(sprintf(
                    '    %d  <fg=gray>%s</>  →  <fg=green>%s</>  <fg=gray>[%s]</>',
                    $edit->line,
                    trim($edit->oldText),
                    trim($edit->newText),
                    $edit->category->value,
                ));
            }
        }

        if ($plan->moves !== []) {
            $this->line('');
            $this->line('  <options=bold>physical move'.(count($plan->moves) > 1 ? 's' : '').'</>');
            foreach ($plan->moves as $move) {
                $this->line('    <fg=gray>'.$this->moveEndpoint($move->fromRelative, $move->sourceRepo, $move)
                    .'</>  →  <fg=green>'.$this->moveEndpoint($move->toRelative, $move->destinationRepo, $move).'</>'
                    .($move->crossRepo ? '  <fg=yellow>[cross-repo]</>' : ''));
            }
        }

        $this->renderSkips($plan);
    }

    /**
     * Render one end of a physical move, repo-qualified when the relative path alone would be
     * ambiguous or misleading.
     *
     * A relative path is root-relative, so on a CROSS-REPO move the two ends can print identically
     * while naming files in different repositories — `src/Models/Foo.php → src/Models/Foo.php` reads
     * as a no-op when it is in fact a promotion out of one package into another. That is the exact
     * moment a reviewer most needs to know which tree each side is in, so prefix the repo's directory
     * name and flag the move. Same-repo moves keep the original bare-relative rendering, which is
     * unambiguous by construction.
     */
    private function moveEndpoint(string $relative, ?string $repo, PhysicalMove $move): string
    {
        if (! $move->crossRepo || $repo === null) {
            return $relative;
        }

        return basename($repo).'/'.$relative;
    }

    private function renderSkips(RewritePlan $plan): void
    {
        if ($plan->skips === []) {
            return;
        }
        $this->line('');
        $this->line('  <fg=yellow>Tier-1 references handed to the guided path ('.count($plan->skips).'):</>');
        foreach ($plan->skips as $skip) {
            $this->line("    {$skip->relativePath}:{$skip->line}  {$skip->snippet}");
            $this->line("      <fg=gray>{$skip->reason}</>");
        }
    }

    private function renderDerivedTests(AuditReport $report): void
    {
        $tests = $this->derivedTests($report);
        if ($tests === []) {
            return;
        }
        $this->line('');
        $this->line('  <options=bold>Derived test set</> (run vs an isolated DB — the tool does not run them):');
        foreach ($tests as $test) {
            $this->line("    {$test}");
        }
    }

    /** @return list<string> test files that reference the moved symbol (the emitted, consumer-run set) */
    private function derivedTests(AuditReport $report): array
    {
        $tests = [];
        foreach ($report->references() as $ref) {
            if ($ref->category === ReferenceCategory::TestFixtureReference
                || str_ends_with($ref->relativePath, 'Test.php')) {
                $tests[$ref->relativePath] = true;
            }
        }

        return array_keys($tests);
    }

    // --- safety machinery --------------------------------------------------------------------------

    /**
     * The roots this write is authorized to touch. `--root` names the blast radius explicitly; absent
     * it, the finding-set's own roots (already app-only-by-default at audit time). No inference beyond
     * what the operator or the resolved set named.
     *
     * @return list<string>
     */
    private function authorizedRoots(AuditReport $report): array
    {
        $named = (array) $this->option('root');
        $roots = $named === [] ? $report->roots : array_map(fn ($r) => $this->absolutePath((string) $r), $named);

        return array_values(array_unique(array_filter(
            array_map(fn ($r) => rtrim((string) $r, '/'), $roots),
            fn ($r) => is_dir($r),
        )));
    }

    /**
     * Drop any edit (and the physical move) whose file is not under a named root — the writer never
     * reaches outside the authorized blast radius.
     *
     * @param  list<string>  $roots
     * @return array{0: RewritePlan, 1: int} the restricted plan and the count of excluded edits
     */
    private function restrictToRoots(RewritePlan $plan, array $roots): array
    {
        $under = fn (string $file) => array_filter($roots, fn ($r) => str_starts_with($file, $r.'/')) !== [];

        $allowed = array_values(array_filter($plan->edits, fn (PlannedEdit $e) => $under($e->file)));
        $excluded = count($plan->edits) - count($allowed);

        // Both ends of a physical move must fall inside the named blast radius. For a same-repo move
        // that is one root; for a cross-repo promotion (ticket 14) the destination lives under a
        // DIFFERENT named root (the package) — so the writer only honours it when the operator named
        // *both* the source and destination roots (`--root=<app> --root=<pkg>`). Naming only one refuses
        // that member's relocation rather than writing into an unauthorized tree. An atomic cluster
        // filters each member move independently.
        $moves = array_values(array_filter(
            $plan->moves,
            fn ($m) => $under($m->from) && $under($m->to),
        ));

        return [new RewritePlan($plan->target, $allowed, $plan->skips, $moves, $plan->unsupported), $excluded];
    }

    /**
     * @param  list<string>  $roots
     * @return array{root: string, status: string}|null
     */
    private function firstDirtyRoot(array $roots): ?array
    {
        foreach ($roots as $root) {
            if (! is_dir($root.'/.git') && ! $this->insideGitRepo($root)) {
                continue; // not a git repo — nothing to protect; in-memory rollback still applies.
            }
            $proc = Process::path($root)->run(['git', 'status', '--short']);
            if ($proc->successful() && trim($proc->output()) !== '') {
                return ['root' => $root, 'status' => rtrim($proc->output())];
            }
        }

        return null;
    }

    private function insideGitRepo(string $root): bool
    {
        return Process::path($root)->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful();
    }

    private function writeManifest(TouchManifest $manifest): string
    {
        $path = (string) $this->option('manifest');
        if ($path === '') {
            $path = rtrim((string) getcwd(), '/').'/.surgeon/move-manifest.json';
        }
        $path = $this->absolutePath($path);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, $manifest->toJson().PHP_EOL);

        return $path;
    }

    /**
     * Best-effort staging (ticket 03: "changes left staged"): stage the touched files + the moved
     * pair + the manifest so the review surface is a `git diff --staged`. Never commits. Silently
     * no-ops outside a git repo — staging is a review affordance, not the safety guarantee.
     *
     * @param  list<string>  $roots
     */
    private function stageWithGit(TouchManifest $manifest, array $roots, string $manifestPath): void
    {
        $paths = array_merge($manifest->touchedFiles(), [$manifestPath]);
        foreach ($manifest->moves as $move) {
            $paths[] = $move['from']; // stage the deletion of each moved member's old path too
        }

        foreach ($roots as $root) {
            if (! $this->insideGitRepo($root)) {
                continue;
            }
            $under = array_values(array_filter($paths, fn ($p) => str_starts_with($p, $root.'/')));
            if ($under !== []) {
                Process::path($root)->run(array_merge(['git', 'add', '--'], $under));
            }
        }
    }

    private function gateTag(string $status): string
    {
        return match ($status) {
            'pass' => '<fg=green>PASS</>',
            'fail' => '<fg=red>FAIL</>',
            default => '<fg=yellow>SKIP</>',
        };
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $real = realpath($path);

        return $real !== false ? $real : rtrim((string) getcwd(), '/').'/'.$path;
    }
}
