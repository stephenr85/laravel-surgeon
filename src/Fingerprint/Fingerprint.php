<?php

namespace Rushing\Surgeon\Fingerprint;

/**
 * The identity of a tree at one moment: what its files hash to, and what configuration surface it
 * reads — the value {@see FingerprintOperation} returns and `surgeon:fingerprint` renders.
 *
 * Three digests rather than one, because they answer different questions and a single number cannot
 * tell you which one moved:
 *
 *  - {@see $filesDigest} — did any tracked byte change?
 *  - {@see $symbolsDigest} — did the set of config keys / env var names the tree reads change? This is
 *    the one that catches "a dependency bump started reading a variable nobody has set in production",
 *    which no content hash surfaces as anything more useful than "something changed".
 *  - {@see $digest} — the pair, rolled together; the single string worth pasting into a ticket or
 *    comparing between two checkouts.
 *
 * A symbols-skipped run leaves {@see $symbolsDigest} null and folds only the file digest into
 * {@see $digest}, so a `--files-only` fingerprint is never mistaken for a full one that happened to
 * find no config reads.
 */
class Fingerprint
{
    /**
     * @param  string  $root  the tree fingerprinted (absolute; not part of any digest)
     * @param  string  $algo  the hash algorithm every digest here was produced with
     * @param  list<array{path: string, hash: string}>  $files  relative path => content hash, path-sorted
     * @param  array<string, list<string>>  $config  config key => `path:line` read sites
     * @param  array<string, list<string>>  $env  env var NAME => `path:line` read sites (never values)
     */
    public function __construct(
        public string $root,
        public string $algo,
        public array $files,
        public array $config,
        public array $env,
        public string $filesDigest,
        public ?string $symbolsDigest,
        public string $digest,
    ) {}

    /** @return list<string> the config keys read, sorted */
    public function configKeys(): array
    {
        return array_keys($this->config);
    }

    /** @return list<string> the env variable names read, sorted */
    public function envNames(): array
    {
        return array_keys($this->env);
    }

    /** Whether the config/env scan ran (false for a `--files-only` run). */
    public function scannedSymbols(): bool
    {
        return $this->symbolsDigest !== null;
    }

    /**
     * The machine-readable shape — what `--json` prints and what an MCP caller receives.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'algo' => $this->algo,
            'digest' => $this->digest,
            'digests' => [
                'files' => $this->filesDigest,
                'symbols' => $this->symbolsDigest,
            ],
            'counts' => [
                'files' => count($this->files),
                'config_keys' => count($this->config),
                'env_vars' => count($this->env),
            ],
            'files' => $this->files,
            'config' => $this->config,
            'env' => $this->env,
        ];
    }
}
