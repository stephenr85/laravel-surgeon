<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Surgeon\Env\EnvInventory;
use Rushing\Surgeon\Env\EnvInventoryOperation;
use Rushing\Surgeon\Env\EnvVariable;

/**
 * `surgeon:env` — every environment variable a codebase reads or declares, reconciled against
 * `.env.example` and the local `.env`, plus the config keys it reads.
 *
 * Nothing in Laravel answers this. `config:show` prints one config file's *values*, `env` prints
 * `APP_ENV`, and `vlucas/phpdotenv` parses `.env` without enumerating what reads it. So the question
 * "what does this thing actually need in its environment" is normally answered by grepping, which is
 * why `.env.example` files drift from the code the moment anyone stops being careful.
 *
 * The three-way join is what makes it worth running: **read but undocumented** (a deployer cannot
 * know it exists), **documented but unread** (dead weight nobody can prove is safe to delete), and
 * **read outside `config/`** — which is a live bug, because `config:cache` stops `.env` from loading
 * and those calls return null in production and nowhere else.
 *
 * A pure read, and also an MCP tool with no further code: {@see \Rushing\Surgeon\Mcp\SurgeonMcpServer}
 * reflects every `surgeon:*` command, and this falls into the default read-only branch.
 *
 * **Values are never read** — names come from call sites and from the left of the `=` in a dotenv
 * file, so nothing secret-derived can reach stdout, a CI log, or an MCP response.
 */
class EnvCommand extends Command
{
    protected $signature = 'surgeon:env
        {--path= : Root to scan (default: the app base path)}
        {--example= : The documenting dotenv file (default: <path>/.env.example)}
        {--env-file= : The local dotenv file to check names against (default: <path>/.env)}
        {--prune=* : Extra directory names to skip, beyond vendor/node_modules/.git/storage/build/dist}
        {--all : List every variable, not just the ones with findings}
        {--config : Also list the config keys the tree reads}
        {--sites : Show the file:line sites for each variable}
        {--strict : Exit non-zero if anything is undocumented, unused, or cache-unsafe}
        {--json : Emit machine-readable output}';

    protected $description = 'List every env var a codebase reads or declares, reconciled against .env.example and .env (plus the config keys it reads).';

    public function handle(): int
    {
        $inventory = (new EnvInventoryOperation)->run(
            root: $this->rootDir(),
            examplePath: $this->pathOption('example'),
            envPath: $this->pathOption('env-file'),
            prune: array_values(array_map('strval', (array) $this->option('prune'))),
        );

        $ok = ! ($this->option('strict') && $inventory->hasFindings());

        if ($this->option('json')) {
            $this->line((string) json_encode($inventory->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->render($inventory);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function render(EnvInventory $inventory): void
    {
        $this->line('');
        $this->line('<options=bold>surgeon:env</> <fg=gray>('.$inventory->root.')</>');
        $this->line('');
        $this->line('  '.count($inventory->read()).' env var(s) read'
            .'  <fg=gray>·</>  '.count($inventory->variables).' known'
            .'  <fg=gray>·</>  '.count($inventory->config).' config key(s) read');

        if (! $inventory->exampleExists) {
            $this->line('  <fg=yellow>No .env.example found</> <fg=gray>— nothing to reconcile against; every read reads as undocumented.</>');
        }

        $this->line('');

        $this->section(
            $inventory->cacheUnsafe(),
            '<fg=red>read outside config/</>',
            'these return NULL once config:cache runs — i.e. in production',
            outsideOnly: true,
        );

        $this->section(
            $inventory->undocumented(),
            '<fg=yellow>read but not in .env.example</>',
            'a deployer has no way to know these exist',
        );

        $this->section(
            $inventory->unused(),
            '<fg=gray>declared but never read</>',
            'candidates for deletion from .env.example',
        );

        if ($this->option('all')) {
            $this->line('  <options=bold>every known variable</>');
            foreach ($inventory->variables as $variable) {
                $this->line('    '.$this->flags($variable).' '.$variable->name);
            }
            $this->line('');
        }

        if ($this->option('config')) {
            $this->line('  <options=bold>config keys read</> <fg=gray>(literal reads only — a dynamic key cannot be seen)</>');
            foreach ($inventory->config as $key => $sites) {
                $this->line('    '.$key.($this->option('sites') ? ' <fg=gray>'.implode(', ', $sites).'</>' : ''));
            }
            $this->line('');
        }

        if (! $inventory->hasFindings()) {
            $this->line('  <fg=green>Nothing to reconcile — reads, .env.example and .env agree.</>');
            $this->line('');
        }
    }

    /**
     * @param  list<EnvVariable>  $variables
     */
    private function section(array $variables, string $heading, string $why, bool $outsideOnly = false): void
    {
        if ($variables === []) {
            return;
        }

        $this->line('  '.$heading.' <fg=gray>('.count($variables).')</> <fg=gray>— '.$why.'</>');

        foreach ($variables as $variable) {
            $sites = $outsideOnly ? $variable->readsOutsideConfig() : $variable->sites;
            $suffix = ($this->option('sites') || $outsideOnly) && $sites !== []
                ? ' <fg=gray>'.implode(', ', array_slice($sites, 0, 3)).(count($sites) > 3 ? ' +'.(count($sites) - 3).' more' : '').'</>'
                : '';

            $this->line('    '.$variable->name.$suffix);
        }

        $this->line('');
    }

    /** A compact three-column marker: read / documented / set. */
    private function flags(EnvVariable $variable): string
    {
        return '<fg=gray>['
            .($variable->isRead() ? 'r' : '-')
            .($variable->documented ? 'd' : '-')
            .($variable->set ? 's' : '-')
            .']</>';
    }

    private function pathOption(string $option): ?string
    {
        $value = $this->option($option);

        return ($value === null || $value === '') ? null : $this->absolutePath((string) $value);
    }

    private function rootDir(): string
    {
        $path = $this->option('path');

        if ($path === null || $path === '') {
            return rtrim($this->laravel->basePath(), '/');
        }

        return rtrim($this->absolutePath((string) $path), '/');
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
