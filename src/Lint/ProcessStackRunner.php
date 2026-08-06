<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Surgeon\Overlay\GitInspector;
use Rushing\Surgeon\Replay\GitRepo;

/**
 * The real {@see StackRunner} — runs a lint/format command argv-array (no shell) in a root, capturing its
 * combined output (ticket 12). Dependency-free `proc_open` (mirrors {@see GitInspector}
 * and the replay {@see GitRepo}); a root path from the overlay is passed as data,
 * never interpolated into a command line. If the binary can't be spawned it returns {@see StackRunResult::notRun()}
 * so the adapter records an honest skip rather than a fabricated failure.
 */
class ProcessStackRunner implements StackRunner
{
    public function run(string $cwd, array $command): StackRunResult
    {
        $process = @proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        if (! is_resource($process)) {
            return StackRunResult::notRun('could not spawn '.($command[0] ?? '?'));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return new StackRunResult($code, trim($stdout."\n".$stderr));
    }
}
