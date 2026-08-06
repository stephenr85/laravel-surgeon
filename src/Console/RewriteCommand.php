<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Surgeon\Rewrite\LiteralRewriteOperation;
use Rushing\Surgeon\Rewrite\RewritePlan;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * `surgeon:rewrite` — the thin WRITER for the generic {@see LiteralRewriteOperation} (dogfood
 * campaign). Applies a resolved literal-edit set (`{file, old, new}` per edit) that a host-side audit
 * produced — the command never infers *which* literal is wrong; it applies what it is handed.
 *
 * Basic form of {@see MoveCommand}'s safety posture:
 *  - **Preview by default.** Without `--apply` it prints the plan and writes nothing.
 *  - **Named blast radius.** `--root` names which trees a write may touch; edits outside are refused.
 *  - **Drift refusal** is the applier's own invariant — a stale `old` span aborts before any write.
 *
 * The full clean-tree / deterministic post-write gate / staging machinery of `surgeon:move` is
 * intentionally NOT duplicated here yet (this is the basic writer); the drift refusal + preview +
 * root-fencing are the load-bearing guarantees for a literal splice.
 */
class RewriteCommand extends Command
{
    protected $signature = 'surgeon:rewrite
        {--from= : Path to a JSON literal-edit set — a list of {file, old, new} objects}
        {--apply : Write the changes (default: preview only, no writes)}
        {--root=* : Roots this rewrite may touch; edits outside are refused (defaults to no fence)}';

    protected $description = 'Apply a resolved literal-rewrite edit set — byte-splice drifted string literals in place (preview by default).';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        if ($from === '') {
            $this->error('surgeon:rewrite needs --from=<literal-edit-set JSON> (a list of {file, old, new}).');

            return self::INVALID;
        }

        $path = $this->absolutePath($from);
        if (! is_file($path)) {
            $this->error("no such edit-set file: {$path}");

            return self::INVALID;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->error('edit-set is not a JSON array of {file, old, new} objects.');

            return self::INVALID;
        }

        $edits = [];
        foreach ($decoded as $row) {
            if (! isset($row['file'], $row['old'], $row['new'])) {
                $this->error('each edit needs file, old, new keys.');

                return self::INVALID;
            }
            $edits[] = [
                'file' => $this->absolutePath((string) $row['file']),
                'oldText' => (string) $row['old'],
                'newText' => (string) $row['new'],
            ];
        }

        $roots = $this->authorizedRoots();
        [$edits, $excluded] = $this->restrictToRoots($edits, $roots);
        if ($excluded > 0) {
            $this->warn("{$excluded} edit(s) fell outside the named --root blast radius and were refused.");
        }

        if ($edits === []) {
            $this->info('surgeon:rewrite — nothing to apply.');

            return self::SUCCESS;
        }

        $operation = new LiteralRewriteOperation;
        try {
            $plan = $operation->plan($edits);
        } catch (\RuntimeException $e) {
            $this->error('surgeon:rewrite could not plan: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            return $this->preview($plan);
        }

        try {
            $touched = $operation->apply($plan, new SpliceApplier);
        } catch (\RuntimeException $e) {
            $this->error('apply aborted before any write: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('surgeon:rewrite applied: '.count($plan->edits).' splice(s) across '.count($touched).' file(s).');
        $this->line('  <fg=yellow>Not committed.</> Review the diff.');

        return self::SUCCESS;
    }

    private function preview(RewritePlan $plan): int
    {
        $this->line('');
        $this->line('<options=bold>surgeon:rewrite — PREVIEW</>');
        $this->line('<fg=yellow>No files written. Re-run with --apply to write.</>');
        foreach ($plan->editsByFile() as $edits) {
            $this->line('');
            $this->line('  <fg=cyan>'.$edits[0]->file.'</> ('.count($edits).')');
            foreach ($edits as $edit) {
                $this->line(sprintf(
                    '    %d  <fg=gray>%s</>  →  <fg=green>%s</>',
                    $edit->line,
                    $edit->oldText,
                    $edit->newText,
                ));
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function authorizedRoots(): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($r) => rtrim($this->absolutePath((string) $r), '/'), (array) $this->option('root')),
            fn ($r) => is_dir($r),
        )));
    }

    /**
     * Drop any edit whose file is not under a named root. With no `--root` given, no fence is applied.
     *
     * @param  list<array{file: string, oldText: string, newText: string}>  $edits
     * @param  list<string>  $roots
     * @return array{0: list<array{file: string, oldText: string, newText: string}>, 1: int}
     */
    private function restrictToRoots(array $edits, array $roots): array
    {
        if ($roots === []) {
            return [$edits, 0];
        }
        $under = fn (string $file) => array_filter($roots, fn ($r) => str_starts_with($file, $r.'/')) !== [];
        $allowed = array_values(array_filter($edits, fn ($e) => $under($e['file'])));

        return [$allowed, count($edits) - count($allowed)];
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
