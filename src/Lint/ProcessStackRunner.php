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
 *
 * **A PHP-shebang binary runs under the parent's interpreter** (gate-reachability 02). Handed
 * `vendor/bin/pint` as argv[0], the kernel follows its `#!/usr/bin/env php` to whatever `php` PATH
 * resolves — the system interpreter at 128M — so the flagship, the one root `pnpm surgeon` always stands
 * in, died on memory on every run no matter which `php` launched surgeon. The interpreter that matters is
 * the CHILD's, and the only way to choose it is to name it: `[PHP_BINARY, 'vendor/bin/pint', …]`. The
 * child also gets `XDEBUG_MODE=off` in its environment — an env var survives the spawn where an ini flag
 * would not (AGENTS.md "Running a suite" §2), and Herd's `develop` mode is the other half of that pair.
 * A non-PHP binary (eslint's `#!/usr/bin/env node`) is left to its own shebang.
 */
class ProcessStackRunner implements StackRunner
{
    public function run(string $cwd, array $command): StackRunResult
    {
        $process = @proc_open(
            $this->withInterpreter($command),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $this->environment(),
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

    /**
     * Prefix a PHP-shebang script with the parent's own interpreter so the child inherits its memory limit
     * rather than the shebang's. Decided by reading the script, not by its name: a bare `pint` on PATH, a
     * node script, or a binary with no shebang is passed through untouched.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function withInterpreter(array $command): array
    {
        $binary = $command[0] ?? null;
        if (! is_string($binary) || $binary === '' || PHP_BINARY === '' || ! $this->isPhpScript($binary)) {
            return $command;
        }

        return array_merge([PHP_BINARY], $command);
    }

    private function isPhpScript(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $first = (string) fgets($handle, 256);
        fclose($handle);

        return str_starts_with($first, '#!') && preg_match('/\bphp\b/', $first) === 1;
    }

    /**
     * The child's environment: the parent's, with xdebug off. The prescription that makes every other
     * invocation in this estate finish is `XDEBUG_MODE=off herd php`; a child spawned here would otherwise
     * inherit Herd's `debug,develop` and walk into the segfault the memory fix just walked it out of.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[(string) $key] = (string) $value;
        }
        $env['XDEBUG_MODE'] = 'off';

        return $env;
    }
}
