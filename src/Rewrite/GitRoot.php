<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * Answers the one question a cross-repo physical move (ticket 14) turns on: *which git repository does
 * this path live in, and are two paths in the same repo?*
 *
 * The single-repo relocation ({@see PhysicalMove}, ticket 09) `git mv`'d within one repo — a `git mv`
 * physically cannot cross a repo boundary, and that boundary is exactly where the estate's most common
 * relocation lands (promoting a class from the *app* into one of its *packages*). Before choosing the
 * relocation mechanism the planner must know honestly whether the moved file's source and its
 * PSR-4-derived destination sit in the same `.git` or two different ones.
 *
 * "Honestly" = walk up the directory tree to the nearest `.git` marker and compare the containing
 * repo's **real** path (symlinks resolved). Real-path comparison matters in a co-dev overlay: a package
 * checkout is symlinked into the app's `vendor/`, so a naive string compare of the two paths could read
 * as "same tree" when they are two independent repos on disk. Resolving to real paths cuts through the
 * symlink and compares the actual repositories.
 *
 * This is a pure filesystem read — no subprocess, no git binary — so it is trivially unit-testable
 * against temp `.git` markers and never depends on git being installed to *classify* a move (the actual
 * staging, which does shell git, is a separate best-effort review affordance in {@see SpliceApplier}).
 */
class GitRoot
{
    /**
     * The real path of the git repository containing `$path`, or null if `$path` is not inside any
     * git working tree. Walks parent directories until a `.git` (dir *or* file — a worktree/submodule
     * gitlink is a file) is found, then returns that directory's canonical real path.
     */
    public static function of(string $path): ?string
    {
        $dir = is_dir($path) ? $path : dirname($path);
        $dir = rtrim($dir, '/');

        while ($dir !== '' && $dir !== '/' && $dir !== '.') {
            if (is_dir($dir.'/.git') || is_file($dir.'/.git')) {
                $real = realpath($dir);

                return $real === false ? $dir : $real;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Are two paths in the SAME git repository? True only when both resolve to a git root and those
     * roots are the same real path. A path with no repo (either side null) is never "same repo" — a
     * cross-repo move into/out of a non-git tree still relocates by copy+delete, not `git mv`.
     */
    public static function sameRepo(string $a, string $b): bool
    {
        $rootA = self::of($a);
        $rootB = self::of($b);

        return $rootA !== null && $rootA === $rootB;
    }
}
