<?php

namespace Rushing\Surgeon\Fingerprint;

/**
 * The resolved input to a {@see FingerprintOperation} run — a plain value the command builds from its
 * signature and the operation consumes, so nothing about artisan reaches into the engine.
 *
 * It is deliberately NOT the MCP input contract. Surgeon's tools are reflected off the artisan
 * signature ({@see \Rushing\McpRegistry\Bridge\ArtisanCommandReflector}), so the signature already IS
 * the advertised schema; declaring the same fields a second time as a Data class would be two
 * contracts to keep in step, which is the drift the reflection bridge exists to remove.
 */
class FingerprintRequest
{
    /**
     * Directory names never descended into. Mirrors the pruned set
     * {@see \Rushing\Surgeon\Lint\LintOrchestrator} and the estate's other file walks assume: build
     * output and dependency trees are not part of a repo's identity, and hashing them would make the
     * digest depend on whether someone had run `composer install` yet.
     *
     * @var list<string>
     */
    public const PRUNED = ['vendor', 'node_modules', '.git', '.idea', 'storage', 'build', 'dist', '.surgeon'];

    /**
     * @param  string  $root  absolute path to the tree being fingerprinted
     * @param  string  $algo  any `hash_algos()` name; the default is fast and non-cryptographic on purpose
     * @param  list<string>  $prune  directory names to skip, on top of {@see PRUNED}
     * @param  bool  $symbols  whether to run the `config()`/`env()` scan (false = hash files only)
     */
    public function __construct(
        public string $root,
        public string $algo = 'xxh128',
        public array $prune = [],
        public bool $symbols = true,
    ) {}

    /**
     * The full set of directory names this run skips.
     *
     * @return list<string>
     */
    public function prunedDirectories(): array
    {
        return array_values(array_unique([...self::PRUNED, ...$this->prune]));
    }
}
