<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Surgeon\HouseStyle\HouseStyleAudit;
use Rushing\Surgeon\HouseStyle\HouseStyleStripOperation;

/**
 * `surgeon:house-style` — scan the owned roots for the three forbidden modern-PHP constructs
 * (`declare(strict_types=1);`, `final`, `readonly`) and, with `--fix`, strip them deterministically.
 *
 * **Default = check-mode (writes nothing):** report every file carrying a forbidden construct, exit
 * non-zero if any is found (usable as a standalone CI gate, the same posture as `surgeon:lint`).
 *
 * **`--fix` = the byte-splice write pass:** apply {@see HouseStyleStripOperation} to each offending file
 * in place. It is a separate write-op from `move`/`lint` (the check-vs-fix split), so a strip never runs
 * inside another operation's gate.
 *
 * The strip is mechanical and generic; whether an estate *forbids* these constructs is the caller's
 * policy (the app documents it in its house-style convention).
 */
class HouseStyleCommand extends Command
{
    protected $signature = 'surgeon:house-style
        {--fix : Strip the forbidden constructs in place (write-op); default is check-mode (writes nothing)}
        {--root=* : Root(s) to scan (repeatable); default is the app base path}
        {--json : Emit machine-readable output}';

    protected $description = 'Scan for (and, with --fix, strip) forbidden modern-PHP constructs: declare(strict_types), final, readonly.';

    public function handle(): int
    {
        $roots = $this->resolveRoots();
        if ($roots === []) {
            $this->error('No roots to scan.');

            return self::INVALID;
        }

        $fixable = array_values(array_filter(
            (new HouseStyleAudit($roots))->suggestOperations(),
            fn ($f) => $f->isFixable(),
        ));

        return $this->option('fix')
            ? $this->fix($roots, $fixable)
            : $this->check($roots, $fixable);
    }

    /** @param  list<string>  $roots  @param  list<\Rushing\Surgeon\Operation\FixableFinding>  $fixable */
    private function check(array $roots, array $fixable): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'operation' => 'house-style',
                'mode' => 'check',
                'roots' => $roots,
                'offending' => array_map(fn ($f) => $f->toArray(), $fixable),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $fixable === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:house-style — CHECK</> <fg=gray>('.count($roots).' root(s))</>');
        $this->line('');

        if ($fixable === []) {
            $this->line('  <fg=green>No forbidden constructs found.</>');
            $this->line('');

            return self::SUCCESS;
        }

        foreach ($fixable as $finding) {
            $this->line('  <fg=red>FAIL</> '.$finding->finding->detail);
        }
        $this->line('');
        $this->line('  <fg=red>'.count($fixable).' file(s) with forbidden construct(s).</> <fg=green>[fix: surgeon:house-style --fix]</>');
        $this->line('');

        return self::FAILURE;
    }

    /** @param  list<string>  $roots  @param  list<\Rushing\Surgeon\Operation\FixableFinding>  $fixable */
    private function fix(array $roots, array $fixable): int
    {
        $operation = new HouseStyleStripOperation;
        $stripped = 0;

        foreach ($fixable as $finding) {
            $file = (string) ($finding->suggestion->payload['file'] ?? '');
            if ($file === '') {
                continue;
            }
            $plan = $operation->plan($file);
            if ($plan->isEmpty()) {
                continue;
            }
            $operation->apply($plan);
            $stripped++;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'operation' => 'house-style',
                'mode' => 'fix',
                'roots' => $roots,
                'stripped_files' => $stripped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:house-style --fix</> <fg=gray>('.count($roots).' root(s))</>');
        $this->line('  stripped: <options=bold>'.$stripped.'</> file(s).');
        $this->line('  <fg=yellow>Not committed.</> Review the diff.');
        $this->line('');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveRoots(): array
    {
        $roots = [];
        foreach ((array) $this->option('root') as $root) {
            $roots[] = $this->absolutePath((string) $root);
        }
        if ($roots === []) {
            $roots[] = rtrim($this->laravel->basePath(), '/');
        }

        return array_values(array_unique(array_filter($roots)));
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        $real = realpath($path);

        return $real !== false ? rtrim($real, '/') : rtrim((string) getcwd(), '/').'/'.$path;
    }
}
