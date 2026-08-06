<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Surgeon\Console\CanonicalizeCommand;
use Rushing\Surgeon\Replay\GitRepo;

/**
 * The dependency-free git reads canonicalization readiness needs (ticket 11): is a path-repo checkout a
 * git repo, is it clean, does it track an upstream, and how far ahead of that upstream is it? These four
 * facts decide whether a canonical `composer update` resolves the code you tested — `dev-main` resolves
 * the *pushed tip*, so a dirty or ahead checkout silently drops work from the shippable lock.
 *
 * Strictly read-only — it never touches the checkout's tree or index (the push that fixes an `ahead`
 * checkout is the writer's job, gated by {@see CanonicalizeCommand}).
 * Subprocesses run argv-array (no shell), so a path from the overlay is passed as data, never
 * interpolated into a command line. Mirrors the {@see GitRepo} seam, kept
 * separate because these are *readiness* reads, not the archive/diff reads the replay harness needs.
 */
class GitInspector
{
    public static function status(string $dir): RepoStatus
    {
        [$code] = self::run($dir, ['rev-parse', '--git-dir']);
        if ($code !== 0) {
            return RepoStatus::notARepo();
        }

        [, $porcelain] = self::run($dir, ['status', '--porcelain']);
        $isDirty = trim($porcelain) !== '';

        [$upCode] = self::run($dir, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
        $hasUpstream = $upCode === 0;

        $ahead = 0;
        if ($hasUpstream) {
            [, $count] = self::run($dir, ['rev-list', '--count', '@{u}..HEAD']);
            $ahead = (int) trim($count);
        }

        return new RepoStatus(true, $isDirty, $hasUpstream, $ahead);
    }

    /**
     * Run `git -C <dir> <args...>` argv-array (no shell) and return [exitCode, stdout].
     *
     * @param  list<string>  $args
     * @return array{0: int, 1: string}
     */
    private static function run(string $dir, array $args): array
    {
        $process = proc_open(
            array_merge(['git', '-C', $dir], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return [1, ''];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $stdout];
    }
}
