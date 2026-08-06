<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Overlay\OverlayAudit;
use Rushing\Surgeon\Overlay\OverlayDiff;
use Rushing\Surgeon\Overlay\OverlayEditor;
use Rushing\Surgeon\Overlay\OverlayEntry;
use Rushing\Surgeon\Overlay\OverlayManifest;
use Rushing\Surgeon\Overlay\OverlayMutation;
use Rushing\Surgeon\Overlay\OverlayState;

/**
 * `surgeon:overlay` — the co-dev-overlay operation family (ticket 10), surgeon's first non-AST,
 * non-source-mutating operations. The overlay (`composer.local.json`) is surgeon's ownership boundary
 * (ticket 08 — "anything on a local path is ours to modify"); this command reads and mutates it.
 *
 * **Read verbs — no git gate (they never write):**
 *  - `list` — the resolved path-repo set + overlay state + an {@see OverlayAudit} health read.
 *  - `diff` — this project's path-repo set vs a sibling's canonical set (`--against=<repo>`).
 *
 * **Write verbs — ticket 03 posture (overlay = discovery, NOT authorization; a write still names what
 * it touches):** `add` / `remove` / `materialize` / `teardown` / `sync`. Each **previews by default**
 * (no `--apply` → nothing written), leaves a **touch-manifest sidecar**, and **never auto-commits**. The
 * `composer update` that realizes the symlink change is announced by default and only run with
 * `--composer` — the JSON edit and the composer effect stay distinct so the review story is honest.
 */
class OverlayCommand extends Command
{
    protected $signature = 'surgeon:overlay
        {action : list|diff|add|remove|materialize|teardown|sync}
        {target? : add=<path-url>, remove=<url|package>, diff/sync=<sibling repo path>}
        {--base= : Project root whose overlay to operate on (default: the app base path)}
        {--require= : With add — the version constraint to require for the added package (e.g. dev-main)}
        {--name= : With add — the package name for --require (default: read from the target checkout)}
        {--against= : With diff/sync — sibling project root providing the canonical path-repo set}
        {--apply : Perform the write (write verbs preview by default)}
        {--manifest= : Where to write the touch-manifest sidecar (default: <base>/.surgeon/overlay-manifest.json)}
        {--composer : After a successful write, run composer update (default: print the command to run)}
        {--json : Emit machine-readable output}';

    protected $description = 'Read and mutate the co-dev overlay (composer.local.json): list / diff / add / remove / materialize / teardown / sync.';

    public function handle(): int
    {
        $base = $this->baseDir();
        $action = (string) $this->argument('action');

        return match ($action) {
            'list' => $this->list($base),
            'diff' => $this->diff($base),
            'add' => $this->add($base),
            'remove' => $this->remove($base),
            'materialize' => $this->write($base, fn (OverlayEditor $e) => $e->materialize()),
            'teardown' => $this->write($base, fn (OverlayEditor $e) => $e->tearDown()),
            'sync' => $this->sync($base),
            default => $this->invalidAction($action),
        };
    }

    // --- read verbs --------------------------------------------------------------------------------

    private function list(string $base): int
    {
        $manifest = OverlayManifest::fromDirectory($base);
        $findings = (new OverlayAudit($base))->suggestOperations();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'overlay' => $manifest->toArray(),
                'audit' => array_map(fn ($f) => $f->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:overlay — '.$manifest->state->value.'</> <fg=gray>('.$base.')</>');
        if ($manifest->state === OverlayState::Absent) {
            $this->line('  <fg=gray>No composer.local.json / .dist / .off — this project has no co-dev overlay.</>');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line(str_repeat('─', 60));
        foreach ($manifest->entries as $entry) {
            $this->line('  '.$this->entryTag($entry).' '.($entry->name ?? '<fg=gray>?unresolved</>')
                .' <fg=gray>'.$entry->url.'</>');
        }

        $this->line('');
        $counts = $manifest->toArray()['counts'];
        $this->line("  <options=bold>{$counts['required']}</> required, <options=bold>{$counts['extra']}</> extra, "
            ."<fg=red>{$counts['broken']}</> broken (of {$counts['entries']} path repo(s)).");

        $this->renderAuditFooter($findings);
        $this->line('');

        return self::SUCCESS;
    }

    private function diff(string $base): int
    {
        $against = $this->siblingDir();
        if ($against === null) {
            $this->error('surgeon:overlay diff needs a sibling repo (positional target or --against=<path>).');

            return self::INVALID;
        }

        $diff = OverlayDiff::between(
            OverlayManifest::fromDirectory($base),
            OverlayManifest::fromDirectory($against),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($diff->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:overlay diff</> <fg=gray>vs '.$against.'</>');
        if ($diff->inSync()) {
            $this->info('  In sync — identical path-repo set.');
            $this->line('');

            return self::SUCCESS;
        }
        foreach ($diff->missing as $url) {
            $this->line('  <fg=green>+ '.$url.'</> <fg=gray>(canonical has it; this overlay lacks it — `sync` would add)</>');
        }
        foreach ($diff->extra as $url) {
            $this->line('  <fg=yellow>~ '.$url.'</> <fg=gray>(this overlay has it; canonical does not — left as-is)</>');
        }
        $this->line('');

        return self::SUCCESS;
    }

    // --- write verbs -------------------------------------------------------------------------------

    private function add(string $base): int
    {
        $url = (string) $this->argument('target');
        if ($url === '') {
            $this->error('surgeon:overlay add needs a path url (the target directory of the type:path repo).');

            return self::INVALID;
        }

        $constraint = $this->option('require');
        $name = $this->option('name');
        if ($constraint !== null && $name === null) {
            $name = $this->packageNameAt($base, $url); // read the checkout's own composer.json name
        }

        return $this->write($base, fn (OverlayEditor $e) => $e->add($url, $constraint, $name));
    }

    private function remove(string $base): int
    {
        $nameOrUrl = (string) $this->argument('target');
        if ($nameOrUrl === '') {
            $this->error('surgeon:overlay remove needs a url or package name to drop.');

            return self::INVALID;
        }

        return $this->write($base, fn (OverlayEditor $e) => $e->remove($nameOrUrl));
    }

    private function sync(string $base): int
    {
        $against = $this->siblingDir();
        if ($against === null) {
            $this->error('surgeon:overlay sync needs a sibling repo (positional target or --against=<path>).');

            return self::INVALID;
        }

        $canonical = OverlayManifest::fromDirectory($against);
        $urls = array_values(array_unique(array_map(fn (OverlayEntry $e) => $e->url, $canonical->entries)));

        return $this->write($base, fn (OverlayEditor $e) => $e->sync($urls));
    }

    /** @param callable(OverlayEditor): OverlayMutation $build */
    private function write(string $base, callable $build): int
    {
        try {
            $mutation = $build(new OverlayEditor($base));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($mutation->isEmpty()) {
            $this->info('surgeon:overlay '.$mutation->verb->value.' — nothing to do ('.$mutation->summary.').');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            return $this->preview($mutation);
        }

        $touched = $mutation->apply();
        $manifestPath = $this->writeSidecar($base, $mutation);
        $this->stageWithGit($base, array_merge($touched, [$manifestPath]));

        $this->line('');
        $this->info('surgeon:overlay '.$mutation->verb->value.' applied: '.$mutation->summary.'.');
        foreach ($mutation->changes as $change) {
            $this->line("  <fg=cyan>{$change['action']}</> {$change['relativePath']}");
        }
        $this->line("  touch-manifest → {$manifestPath}");
        $this->line('  <fg=yellow>Not committed.</> Review the staged diff; the manifest is the scoped-undo surface.');
        $this->announceComposer($base);
        $this->line('');

        return self::SUCCESS;
    }

    private function preview(OverlayMutation $mutation): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($mutation->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:overlay '.$mutation->verb->value.' — PREVIEW</>');
        $this->line('<fg=yellow>No files written. Re-run with --apply to write.</>');
        $this->line('  '.$mutation->summary);
        foreach ($mutation->changes as $change) {
            $this->line("  <fg=cyan>{$change['action']}</> {$change['relativePath']}");
        }
        $this->line('');

        return self::SUCCESS;
    }

    // --- composer follow-up ------------------------------------------------------------------------

    private function announceComposer(string $base): void
    {
        $command = 'composer update';
        if (! $this->option('composer')) {
            $this->line('  <fg=gray>Run to realize the change:</> <fg=cyan>'.$command.'</> <fg=gray>(in '.$base.')</>');

            return;
        }

        $this->line('  <fg=gray>running: '.$command.'</>');
        $result = Process::path($base)->timeout(600)->run($command);
        $result->successful()
            ? $this->info('  composer update completed.')
            : $this->error('  composer update failed — the overlay JSON change stands; resolve composer manually.');
    }

    // --- rendering ---------------------------------------------------------------------------------

    private function renderAuditFooter(array $findings): void
    {
        $fixable = array_values(array_filter($findings, fn ($f) => $f->isFixable()));
        $fails = array_values(array_filter($findings, fn ($f) => $f->finding->status->value === 'fail'));

        $this->line('');
        foreach ($findings as $fixable_finding) {
            if ($fixable_finding->finding->status->value === 'pass' && count($findings) > 1) {
                continue; // only show the lone pass when nothing else fired
            }
            $tag = match ($fixable_finding->finding->status->value) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };
            $fix = $fixable_finding->isFixable()
                ? ' <fg=green>[fix: surgeon:overlay '.str_replace('overlay-', '', $fixable_finding->suggestion->kind).']</>'
                : '';
            $this->line("  {$tag} {$fixable_finding->finding->detail}{$fix}");
        }
        if ($fails !== []) {
            $this->line('  <fg=red>'.count($fails).' overlay mal-adaptation(s) — see the nominated fix verbs above.</>');
        }
    }

    private function entryTag(OverlayEntry $entry): string
    {
        return match (true) {
            $entry->isBroken() => '<fg=red>✗</>',
            $entry->required => '<fg=green>●</>',
            default => '<fg=gray>○</>',
        };
    }

    // --- resolution helpers ------------------------------------------------------------------------

    private function baseDir(): string
    {
        $base = $this->option('base');
        if ($base !== null && $base !== '') {
            return rtrim($this->absolutePath((string) $base), '/');
        }

        return rtrim($this->laravel->basePath(), '/');
    }

    private function siblingDir(): ?string
    {
        $against = $this->option('against') ?? $this->argument('target');
        if ($against === null || $against === '') {
            return null;
        }
        $abs = $this->absolutePath((string) $against);

        return is_dir($abs) ? rtrim($abs, '/') : null;
    }

    private function packageNameAt(string $base, string $url): ?string
    {
        $dir = str_starts_with($url, '/') ? $url : $base.'/'.$url;
        $composer = rtrim($dir, '/').'/composer.json';
        if (! is_file($composer)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($composer), true);

        return is_array($data) && isset($data['name']) ? (string) $data['name'] : null;
    }

    private function writeSidecar(string $base, OverlayMutation $mutation): string
    {
        $path = $this->option('manifest');
        $path = ($path !== null && $path !== '')
            ? $this->absolutePath((string) $path)
            : $base.'/.surgeon/overlay-manifest.json';

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, $mutation->toJson().PHP_EOL);

        return $path;
    }

    /** @param list<string> $paths */
    private function stageWithGit(string $base, array $paths): void
    {
        if (! Process::path($base)->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful()) {
            return; // staging is a review affordance, not the safety guarantee
        }
        $under = array_values(array_filter($paths, fn ($p) => str_starts_with($p, $base.'/')));
        if ($under !== []) {
            Process::path($base)->run(array_merge(['git', 'add', '--'], $under));
        }
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action '{$action}'. Use: list, diff, add, remove, materialize, teardown, sync.");

        return self::INVALID;
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
