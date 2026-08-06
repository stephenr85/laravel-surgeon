<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Docblock\DocblockDerefOperation;
use Rushing\Surgeon\Docblock\DocblockTierAudit;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * `surgeon:docblock` — thin CLI over {@see DocblockTierAudit} + {@see DocblockDerefOperation}. It scans
 * the named roots for `{@see \Fqn}` / `@see \Fqn` docblock references to a HIGHER-tier class — the form
 * Pint's `fully_qualified_strict_types` would forge into an illegal upward `use` import — and, with
 * `--fix`, byte-splices each to a non-importable backtick short-name.
 *
 * **Dry-run by default (writes nothing):** report each upward reference and the deref it would apply,
 * exit non-zero if any is found (usable as a standalone CI gate). `--fix` applies the splices via the
 * shared {@see SpliceApplier} (descending-offset, drift-refusing). `--root=*` is repeatable and, together
 * with the base, forms the scan set; each root is expected to be one package checkout with a composer.json
 * (so `PackageGraph` can place its FQNs).
 */
class DocblockCommand extends Command
{
    protected $signature = 'surgeon:docblock
        {--fix : Apply the derefs (byte-splice write); default is dry-run (writes nothing)}
        {--base= : Project root to scan (default: the app base path)}
        {--root=* : Additional package roots to scan (repeatable); each should hold a composer.json}
        {--json : Emit machine-readable output}';

    protected $description = 'Deref upward {@see \\Fqn} docblock references that Pint would forge into an illegal upward use import.';

    public function handle(): int
    {
        $roots = $this->resolveRoots();
        if ($roots === []) {
            $this->error('No roots to scan (base did not resolve).');

            return self::INVALID;
        }

        $graph = PackageGraph::fromRoots($roots);

        /** @var list<FixableFinding> $findings */
        $findings = [];
        foreach ($roots as $root) {
            $package = $graph->packageForRoot($root);
            if ($package === null) {
                continue; // A root with no resolvable composer name can't be tier-placed — skip it.
            }
            $audit = new DocblockTierAudit($this->phpFilesIn($root), $package, $graph);
            foreach ($audit->suggestOperations() as $finding) {
                $findings[] = $finding;
            }
        }

        return $this->option('fix')
            ? $this->fix($findings)
            : $this->report($findings);
    }

    /** @param  list<FixableFinding>  $findings */
    private function report(array $findings): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(fn (FixableFinding $f) => $f->toArray(), $findings),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->line('<options=bold>surgeon:docblock — CHECK</>');
        if ($findings === []) {
            $this->line('  <fg=green>No upward @see docblock references found.</>');
            $this->line('');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->line('  <fg=red>UPWARD</> '.$finding->finding->detail);
        }
        $this->line('  <fg=red>'.count($findings).' reference(s).</> <fg=green>[fix: surgeon:docblock --fix]</>');
        $this->line('');

        return self::FAILURE;
    }

    /** @param  list<FixableFinding>  $findings */
    private function fix(array $findings): int
    {
        $refs = [];
        foreach ($findings as $finding) {
            if ($finding->isFixable()) {
                $refs[] = $finding->suggestion->payload;
            }
        }

        if ($refs === []) {
            $this->line('  <fg=green>Nothing to fix.</>');

            return self::SUCCESS;
        }

        $plan = (new DocblockDerefOperation)->plan($refs);
        (new DocblockDerefOperation)->apply($plan, new SpliceApplier);

        $this->line('');
        $this->line('<options=bold>surgeon:docblock --fix</>');
        $this->line('  deref\'d: <options=bold>'.count($refs).'</> reference(s) across '.count($plan->editsByFile()).' file(s).');
        $this->line('  <fg=yellow>Not committed.</> Review the diff.');
        $this->line('');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveRoots(): array
    {
        $roots = [rtrim($this->baseDir(), '/')];
        foreach ((array) $this->option('root') as $root) {
            $roots[] = $this->absolutePath((string) $root);
        }

        return array_values(array_unique(array_filter($roots)));
    }

    /** @return list<string> */
    private function phpFilesIn(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), ['vendor', 'node_modules', '.git'], true);
                    }

                    return $current->getExtension() === 'php';
                },
            ),
        );

        $files = [];
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function baseDir(): string
    {
        $base = $this->option('base');
        if ($base !== null && $base !== '') {
            return $this->absolutePath((string) $base);
        }

        return rtrim($this->laravel->basePath(), '/');
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
