<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Overlay\CanonicalizationAudit;
use Rushing\Surgeon\Overlay\CanonicalizationPlan;
use Rushing\Surgeon\Overlay\Canonicalizer;

/**
 * `surgeon:canonicalize` — take the project out of its co-dev overlay and regenerate a shippable,
 * git-resolved `composer.lock` (ticket 11). The inverse of `surgeon:overlay`: the overlay goes co-dev
 * (path-resolve), canonicalize goes shippable (git-resolve). The `composer-canonicalize` skill's prose
 * hardened into a tested, gated operation.
 *
 * **Default = dry-run (writes nothing):** a {@see CanonicalizationAudit} readiness read ("what would
 * canonicalize break?") plus the derived {@see CanonicalizationPlan} preview.
 *
 * **`--apply` = perform, under ticket 03's writer posture (per ticket 08 decision 3):**
 *  - **dirty / no-upstream / not-a-repo package = HARD STOP.** Canonicalizing over one ships a lie (the
 *    `dev-main` pushed tip is what resolves), so the command refuses before touching anything.
 *  - **ahead package = refuse-and-report by default**; `--push` opts into the deterministic fix (push the
 *    ahead checkouts). No silent push — "no auto-push" holds unless explicitly asked.
 *  - overlay offline (backed up to `.git/`, which is ignored) → `composer update "<vendor>/*" -W` per
 *    derived glob → **verify path-free** (0 `type: path` sources survive in the lock).
 *  - **no auto-commit** (deploy-adjacent — human confirms); on any failure the overlay is **relinked**
 *    (the manifest-scoped rollback) so co-dev resumes cleanly. On success the two shippable files
 *    (`composer.json` + `composer.lock`) are staged **by name** — never `git add -A` (package checkouts
 *    may be dirty).
 *
 * **`--relink` = restore the overlay and exit** (resume co-dev after a successful canonicalize).
 */
class CanonicalizeCommand extends Command
{
    protected $signature = 'surgeon:canonicalize
        {--base= : Project root to canonicalize (default: the app base path)}
        {--apply : Perform the canonicalization (default: dry-run — audit + plan preview, writes nothing)}
        {--push : Also push required path repos that are ahead of upstream (default: refuse and report)}
        {--relink : Restore the overlay to resume co-dev (the manifest-scoped rollback) and exit}
        {--json : Emit machine-readable output}';

    protected $description = 'Take the project out of its co-dev overlay and regenerate a shippable, git-resolved composer.lock (the overlay inverse).';

    public function handle(): int
    {
        $base = $this->baseDir();
        $canonicalizer = new Canonicalizer($base);
        $plan = $canonicalizer->plan();

        if ($this->option('relink')) {
            return $this->relink($canonicalizer, $plan);
        }

        $findings = (new CanonicalizationAudit($base))->suggestOperations();

        return $this->option('apply')
            ? $this->apply($base, $canonicalizer, $plan, $findings)
            : $this->dryRun($plan, $findings);
    }

    // --- dry-run -----------------------------------------------------------------------------------

    /** @param  list<FixableFinding>  $findings */
    private function dryRun(CanonicalizationPlan $plan, array $findings): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'plan' => $plan->toArray(),
                'audit' => array_map(fn (FixableFinding $f) => $f->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:canonicalize — DRY RUN</> <fg=gray>('.$this->baseDir().')</>');
        $this->line('<fg=yellow>Nothing written. Re-run with --apply to canonicalize.</>');
        $this->renderPlan($plan);
        $this->renderFindings($findings);
        $this->line('');

        return self::SUCCESS;
    }

    // --- apply -------------------------------------------------------------------------------------

    /** @param  list<FixableFinding>  $findings */
    private function apply(string $base, Canonicalizer $canonicalizer, CanonicalizationPlan $plan, array $findings): int
    {
        $fails = array_values(array_filter($findings, fn (FixableFinding $f) => $f->finding->status->value === 'fail'));
        if ($fails !== []) {
            $this->renderFindings($findings);
            $this->error('Hard stop — resolve the failing checkout(s) above before canonicalizing (a dirty/unpushed package would ship a lie).');

            return self::FAILURE;
        }

        $ahead = array_values(array_filter($findings, fn (FixableFinding $f) => $f->finding->check === 'canonicalize.ahead'));
        if ($ahead !== [] && ! $this->option('push')) {
            $this->renderFindings($findings);
            $this->error(count($ahead).' path repo(s) ahead of upstream — push them, or re-run with --push (their tips must be pushed or the lock drops them).');

            return self::FAILURE;
        }
        if ($ahead !== [] && ! $this->pushAhead($ahead)) {
            return self::FAILURE;
        }

        $canonicalizer->takeOverlayOffline($plan);

        $selectors = $plan->vendorGlobs;
        $command = array_merge(['composer', 'update'], $selectors, ['-W']);
        $this->line('  <fg=gray>running: '.implode(' ', $command).'</>');
        $result = Process::path($base)->timeout(1200)->run($command);
        if (! $result->successful()) {
            $canonicalizer->relink($plan);
            $this->error('composer update failed — overlay relinked (co-dev restored). Resolve composer manually.');

            return self::FAILURE;
        }

        $pathSources = $canonicalizer->pathSourceCount($plan->lockPath);
        if ($pathSources > 0) {
            $canonicalizer->relink($plan);
            $this->error("Lock is NOT path-free ({$pathSources} `type: path` source(s) survived) — overlay relinked. Investigate before shipping.");

            return self::FAILURE;
        }

        $this->stageShippableFiles($base);

        $this->line('');
        $this->info('surgeon:canonicalize applied — composer.lock is git-resolved and path-free.');
        $this->line('  overlay offline → '.$plan->backupPath.' <fg=gray>(ignored under .git/)</>');
        $this->line('  <fg=yellow>Not committed.</> Review the staged composer.json + composer.lock, then commit by name.');
        $this->line('  <fg=gray>Resume co-dev with:</> <fg=cyan>surgeon:canonicalize --relink</>');
        $this->line('');

        return self::SUCCESS;
    }

    /** @param  list<FixableFinding>  $ahead */
    private function pushAhead(array $ahead): bool
    {
        foreach ($ahead as $finding) {
            $dir = (string) ($finding->suggestion?->payload['dir'] ?? '');
            $pkg = (string) ($finding->suggestion?->payload['package'] ?? $dir);
            if ($dir === '') {
                continue;
            }
            $this->line("  <fg=gray>pushing {$pkg}…</>");
            if (! Process::path($dir)->timeout(300)->run(['git', 'push'])->successful()) {
                $this->error("git push failed in {$dir} — nothing canonicalized (overlay untouched).");

                return false;
            }
        }

        return true;
    }

    // --- relink ------------------------------------------------------------------------------------

    private function relink(Canonicalizer $canonicalizer, CanonicalizationPlan $plan): int
    {
        if (! $canonicalizer->relink($plan)) {
            $this->error('No overlay backup at '.$plan->backupPath.' — nothing to relink (overlay may already be live).');

            return self::INVALID;
        }
        $this->info('Overlay relinked — '.$plan->overlayFile.' restored. Run `composer update` to re-symlink the path repos.');

        return self::SUCCESS;
    }

    // --- rendering ---------------------------------------------------------------------------------

    private function renderPlan(CanonicalizationPlan $plan): void
    {
        $this->line('');
        $this->line('  <options=bold>Plan</>');
        $this->line('  packages: '.($plan->requiredPackages === [] ? '<fg=gray>none</>' : implode(', ', $plan->requiredPackages)));
        $this->line('  update selectors: '.($plan->vendorGlobs === [] ? '<fg=gray>none</>' : implode(' ', $plan->vendorGlobs)));
        $this->line('  overlay → backup: <fg=gray>'.$plan->backupPath.'</>');
    }

    /** @param  list<FixableFinding>  $findings */
    private function renderFindings(array $findings): void
    {
        $this->line('');
        foreach ($findings as $finding) {
            if ($finding->finding->status->value === 'pass' && count($findings) > 1) {
                continue; // only surface the lone pass when nothing else fired
            }
            $tag = match ($finding->finding->status->value) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };
            $fix = $finding->isFixable() ? ' <fg=green>[fix: surgeon:canonicalize --apply --push]</>' : '';
            $this->line("  {$tag} {$finding->finding->detail}{$fix}");
        }
    }

    // --- helpers -----------------------------------------------------------------------------------

    private function stageShippableFiles(string $base): void
    {
        if (! Process::path($base)->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful()) {
            return; // staging is a review affordance, not the safety guarantee
        }
        Process::path($base)->run(['git', 'add', '--', 'composer.json', 'composer.lock']);
    }

    private function baseDir(): string
    {
        $base = $this->option('base');
        if ($base !== null && $base !== '') {
            $abs = str_starts_with((string) $base, '/') ? (string) $base : (realpath((string) $base) ?: (string) $base);

            return rtrim($abs, '/');
        }

        return rtrim($this->laravel->basePath(), '/');
    }
}
