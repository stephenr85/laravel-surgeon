<?php

namespace Rushing\Surgeon\Fingerprint;

use InvalidArgumentException;

/**
 * The engine behind `surgeon:fingerprint`: hash every file under a root, scan its PHP for the config
 * keys and env var names it reads, and roll both into comparable digests.
 *
 * A **read**, in surgeon's read/write vocabulary — it opens files and writes nothing, which is what
 * lets the MCP surface expose it ungated (the reflected tool inherits
 * {@see \Rushing\Surgeon\Mcp\SurgeonMcpServer}'s default read-only policy with no per-command entry).
 *
 * The two halves are separable on purpose. Hashing is cheap and total; the symbol scan tokenizes every
 * PHP file, which is the expensive half and the half that only makes sense for a PHP tree — so a
 * caller fingerprinting a large or non-PHP root turns it off ({@see FingerprintRequest::$symbols})
 * and still gets a usable content digest.
 */
class FingerprintOperation
{
    public function run(FingerprintRequest $request): Fingerprint
    {
        $root = rtrim($request->root, '/');

        if (! is_dir($root)) {
            throw new InvalidArgumentException("Cannot fingerprint [{$root}]: not a directory.");
        }

        if (! in_array($request->algo, hash_algos(), true)) {
            throw new InvalidArgumentException("Unknown hash algorithm [{$request->algo}].");
        }

        $files = FileDigest::files($root, $request->prunedDirectories(), $request->algo);
        $filesDigest = FileDigest::rollup($files, $request->algo);

        $symbols = ['config' => [], 'env' => []];
        $symbolsDigest = null;

        if ($request->symbols) {
            $symbols = SymbolScanner::scan($this->phpFiles($root, $files));
            $symbolsDigest = SymbolScanner::rollup($symbols, $request->algo);
        }

        return new Fingerprint(
            root: $root,
            algo: $request->algo,
            files: $files,
            config: $symbols['config'],
            env: $symbols['env'],
            filesDigest: $filesDigest,
            symbolsDigest: $symbolsDigest,
            digest: $this->rollup($filesDigest, $symbolsDigest, $request->algo),
        );
    }

    /**
     * The combined digest. A files-only run hashes the file digest alone, so it can never collide with
     * a full run whose tree happened to read nothing.
     */
    private function rollup(string $filesDigest, ?string $symbolsDigest, string $algo): string
    {
        $lines = 'files '.$filesDigest."\n";

        if ($symbolsDigest !== null) {
            $lines .= 'symbols '.$symbolsDigest."\n";
        }

        return hash($algo, $lines);
    }

    /**
     * The PHP files among an already-walked set — reusing the file walk rather than re-walking the
     * tree, so the symbol scan costs one `file_get_contents` per PHP file and no second traversal.
     *
     * @param  list<array{path: string, hash: string}>  $files
     * @return list<array{path: string, absolute: string}>
     */
    private function phpFiles(string $root, array $files): array
    {
        $php = [];

        foreach ($files as $file) {
            if (! str_ends_with($file['path'], '.php')) {
                continue;
            }

            $php[] = ['path' => $file['path'], 'absolute' => $root.'/'.$file['path']];
        }

        return $php;
    }
}
