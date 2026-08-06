<?php

namespace Rushing\Surgeon\Replay;

/**
 * The two git reads the golden-replay harness needs, wrapped in a dependency-free subprocess seam.
 *
 * The harness only ever *reads* history — it reconstructs a pre-move tree ({@see self::archiveTo()})
 * and asks which files a campaign actually changed ({@see self::changedFiles()}). It never touches
 * the working tree or index of the repo under replay, so no clean-tree gate applies (unlike the
 * writer path, ticket 03). Subprocesses run argv-array (no shell), so refs from a fixture file are
 * passed as data, never interpolated into a command line.
 */
class GitRepo
{
    public function __construct(private string $repoPath)
    {
        if (! is_dir($repoPath.'/.git') && ! is_file($repoPath.'/.git')) {
            throw new \InvalidArgumentException("not a git repository: {$repoPath}");
        }
    }

    /**
     * Materialise the tree at `$ref` into `$destDir` — the honest pre-move reconstruction the audit
     * scans. `git archive` snapshots exactly the tracked tree at the ref (no vendor/, no untracked
     * noise), so what the audit walks is what the campaign started from.
     */
    public function archiveTo(string $ref, string $destDir): void
    {
        if (! is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $tar = $destDir.'/.surgeon-archive.tar';

        $this->run(['git', '-C', $this->repoPath, 'archive', '--format=tar', '-o', $tar, $ref]);
        $this->run(['tar', '-x', '-f', $tar, '-C', $destDir]);

        @unlink($tar);
    }

    /**
     * The repo-relative paths a campaign changed, `$before..$after`. This is the ground truth the
     * tool's enumeration is diffed against.
     *
     * `--no-renames` is deliberate: rename detection collapses a moved-from/moved-to pair to the new
     * path alone, which would hide the moved-from file the audit legitimately enumerates (its own
     * declaration is a real touch-point the physical-move step owns). Counting both paths keeps the
     * ground truth honest — every path the campaign disturbed, not git's similarity-collapsed view.
     *
     * @return list<string>
     */
    public function changedFiles(string $before, string $after): array
    {
        $out = $this->run(['git', '-C', $this->repoPath, 'diff', '--no-renames', '--name-only', $before, $after]);

        $files = array_values(array_filter(array_map('trim', explode("\n", $out)), fn (string $l) => $l !== ''));
        sort($files);

        return $files;
    }

    /** Resolve a ref to its full commit hash (stamped into the report for provenance). */
    public function resolve(string $ref): string
    {
        return trim($this->run(['git', '-C', $this->repoPath, 'rev-parse', $ref]));
    }

    /**
     * Run a git/tar subprocess argv-array (no shell) and return stdout, throwing on non-zero exit.
     *
     * @param  list<string>  $cmd
     */
    private function run(array $cmd): string
    {
        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('failed to spawn: '.implode(' ', $cmd));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            throw new \RuntimeException(
                'command failed ('.$code.'): '.implode(' ', $cmd).PHP_EOL.trim((string) $stderr),
            );
        }

        return (string) $stdout;
    }
}
