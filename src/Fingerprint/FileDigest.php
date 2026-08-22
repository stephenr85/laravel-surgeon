<?php

namespace Rushing\Surgeon\Fingerprint;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Hashes every file under a root and rolls the result into one digest — the content half of a
 * {@see Fingerprint}.
 *
 * Three properties make the digest comparable across machines, which is the only thing that makes it
 * worth computing:
 *
 *  - **Paths are relative to the root.** The same checkout at `~/Workspaces/...` and at
 *    `/build/ci/...` must produce the same digest, so the absolute prefix is never hashed.
 *  - **Order is fixed.** Directory iteration order is filesystem-dependent (and differs between APFS
 *    and ext4); entries are sorted by path with a byte comparison before rolling up.
 *  - **Dependency and build trees are pruned.** Otherwise the digest answers "have I run
 *    `composer install`" rather than "has this repo changed".
 *
 * Symlinked directories are not followed — `RecursiveDirectoryIterator` only does so when asked, and
 * a walk that escapes the root would hash a tree the root does not own.
 */
class FileDigest
{
    /**
     * Every file under `$root`, relative-pathed and hashed, sorted by path.
     *
     * @param  list<string>  $prune  directory names to skip
     * @return list<array{path: string, hash: string}>
     */
    public static function files(string $root, array $prune, string $algo): array
    {
        $root = rtrim($root, '/');

        if (! is_dir($root)) {
            return [];
        }

        $entries = [];

        foreach (self::walk($root, $prune) as $path) {
            $hash = @hash_file($algo, $path);

            // An unreadable file is skipped rather than faked into the digest — a fingerprint that
            // silently absorbs a permission error would compare equal to one that could read it.
            if ($hash === false) {
                continue;
            }

            $entries[substr($path, strlen($root) + 1)] = $hash;
        }

        ksort($entries, SORT_STRING);

        $files = [];

        foreach ($entries as $path => $hash) {
            $files[] = ['path' => $path, 'hash' => $hash];
        }

        return $files;
    }

    /**
     * The roll-up over an already-sorted file list: one hash of the `hash  path` lines, in the shape
     * a `shasum`-style manifest would carry, so the digest is reproducible by hand if anyone doubts it.
     *
     * @param  list<array{path: string, hash: string}>  $files
     */
    public static function rollup(array $files, string $algo): string
    {
        $lines = '';

        foreach ($files as $file) {
            $lines .= $file['hash'].'  '.$file['path']."\n";
        }

        return hash($algo, $lines);
    }

    /**
     * Absolute paths of every file under the root, pruned dirs excluded.
     *
     * The prune decision is a *descent* filter, not a post-hoc path match: `vendor/` and
     * `node_modules/` are the two largest trees in a package estate, and a walk that enumerates them
     * only to discard them is the difference between a fingerprint that feels instant and one nobody
     * runs twice.
     *
     * @param  list<string>  $prune
     * @return list<string>
     */
    private static function walk(string $root, array $prune): array
    {
        $directories = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

        $filtered = new RecursiveCallbackFilterIterator(
            $directories,
            static function (SplFileInfo $item) use ($prune): bool {
                if ($item->isLink()) {
                    return false;
                }

                return ! ($item->isDir() && in_array($item->getFilename(), $prune, true));
            },
        );

        $paths = [];

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator($filtered) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->getPathname();
            }
        }

        return $paths;
    }
}
