<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Surgeon\Fingerprint\Fingerprint;
use Rushing\Surgeon\Fingerprint\FingerprintOperation;
use Rushing\Surgeon\Fingerprint\FingerprintRequest;

/**
 * `surgeon:fingerprint` — the identity read: hash every file under a root and enumerate every config
 * key and environment variable it reads, rolled into digests two checkouts can be compared by.
 *
 * A pure read. It is also, with no further code, an MCP tool: {@see \Rushing\Surgeon\Mcp\
 * SurgeonMcpServer} reflects every `surgeon:*` command through
 * {@see \Rushing\McpRegistry\Bridge\ArtisanCommandReflector}, and this command falls into the default
 * policy branch — ungated, annotated read-only, its options advertised as the tool's input schema.
 * That is why the input contract is declared *here*, in the signature, and nowhere else: a second
 * declaration of the same fields is a second thing to keep in step.
 *
 * ## `--expect` is what makes it a gate rather than a curiosity
 * Printing a digest is only half useful; the question is usually "is this still the tree I checked?"
 * Passing the previously-recorded digest turns the command into a CI-usable check that exits non-zero
 * on drift, without a sidecar file or any state of its own.
 *
 * ## Env values are never read
 * The scan collects variable *names* from `env()` call sites. It never resolves them, so nothing
 * secret-derived can reach stdout, a CI log, or an MCP response — see
 * {@see \Rushing\Surgeon\Fingerprint\SymbolScanner}.
 */
class FingerprintCommand extends Command
{
    protected $signature = 'surgeon:fingerprint
        {--path= : Root to fingerprint (default: the app base path)}
        {--algo=xxh128 : Hash algorithm (any hash_algos() name); the default is fast, not cryptographic}
        {--prune=* : Extra directory names to skip, beyond vendor/node_modules/.git/storage/build/dist}
        {--files-only : Hash files only; skip the config()/env() scan}
        {--symbols : List the config keys and env var names read (human output; --json always includes them)}
        {--files : List every file and its hash (human output; --json always includes them)}
        {--expect= : Fail if the combined digest does not match this value}
        {--json : Emit machine-readable output}';

    protected $description = 'Hash every file under a root and enumerate the config keys and env vars it reads, as comparable digests.';

    public function handle(): int
    {
        $fingerprint = (new FingerprintOperation)->run(new FingerprintRequest(
            root: $this->rootDir(),
            algo: (string) $this->option('algo'),
            prune: array_values(array_map('strval', (array) $this->option('prune'))),
            symbols: ! $this->option('files-only'),
        ));

        $expected = $this->option('expect');
        $matched = $expected === null || $expected === '' || $expected === $fingerprint->digest;

        return $this->option('json')
            ? $this->renderJson($fingerprint, $expected, $matched)
            : $this->renderHuman($fingerprint, $expected, $matched);
    }

    private function renderJson(Fingerprint $fingerprint, ?string $expected, bool $matched): int
    {
        $payload = $fingerprint->toArray();

        if ($expected !== null && $expected !== '') {
            $payload['expected'] = $expected;
            $payload['matched'] = $matched;
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $matched ? self::SUCCESS : self::FAILURE;
    }

    private function renderHuman(Fingerprint $fingerprint, ?string $expected, bool $matched): int
    {
        $this->line('');
        $this->line('<options=bold>surgeon:fingerprint</> <fg=gray>('.$fingerprint->root.')</>');
        $this->line('');
        $this->line('  digest   <options=bold>'.$fingerprint->digest.'</> <fg=gray>('.$fingerprint->algo.')</>');
        $this->line('  files    '.$fingerprint->filesDigest.' <fg=gray>'.count($fingerprint->files).' file(s)</>');

        if ($fingerprint->scannedSymbols()) {
            $this->line('  symbols  '.$fingerprint->symbolsDigest.' <fg=gray>'
                .count($fingerprint->config).' config key(s), '.count($fingerprint->env).' env var(s)</>');
        } else {
            $this->line('  symbols  <fg=gray>skipped (--files-only)</>');
        }

        $this->line('');

        if ($this->option('files')) {
            foreach ($fingerprint->files as $file) {
                $this->line('  <fg=gray>'.$file['hash'].'</>  '.$file['path']);
            }
            $this->line('');
        }

        if ($this->option('symbols') && $fingerprint->scannedSymbols()) {
            $this->renderSymbols($fingerprint);
        }

        if ($expected === null || $expected === '') {
            return self::SUCCESS;
        }

        if ($matched) {
            $this->line('  <fg=green>Matches the expected digest.</>');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('  <fg=red>Digest drift.</> expected <fg=gray>'.$expected.'</>');
        $this->line('  <fg=gray>Re-run with --files/--symbols to see what the tree carries now.</>');
        $this->line('');

        return self::FAILURE;
    }

    private function renderSymbols(Fingerprint $fingerprint): void
    {
        $this->line('  <options=bold>config keys</>');
        foreach ($fingerprint->config as $key => $sites) {
            $this->line('    '.$key.' <fg=gray>('.count($sites).' site(s))</>');
        }

        $this->line('');
        $this->line('  <options=bold>env vars</> <fg=gray>(names only — values are never read)</>');
        foreach ($fingerprint->env as $name => $sites) {
            $this->line('    '.$name.' <fg=gray>('.count($sites).' site(s))</>');
        }

        $this->line('');
    }

    private function rootDir(): string
    {
        $path = $this->option('path');

        if ($path === null || $path === '') {
            return rtrim($this->laravel->basePath(), '/');
        }

        $path = (string) $path;

        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        $real = realpath($path);

        return $real !== false ? rtrim($real, '/') : rtrim((string) getcwd(), '/').'/'.$path;
    }
}
