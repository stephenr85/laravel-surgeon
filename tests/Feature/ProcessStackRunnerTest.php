<?php

use Rushing\Surgeon\Lint\ProcessStackRunner;

/**
 * The real subprocess boundary (gate-reachability 02). `ProcessStackRunner` used to hand `vendor/bin/pint`
 * to the OS as argv[0], so the kernel followed the script's `#!/usr/bin/env php` shebang to whatever `php`
 * PATH resolves — the system interpreter at 128M — regardless of the `XDEBUG_MODE=off herd php` the
 * parent runs under. The flagship, the one root `pnpm surgeon` is guaranteed to stand in, read DEAD on
 * every run. AGENTS.md "Running a suite" §2: a per-process setting must be delivered the way the child
 * receives it — an ini flag dies at the fan-out, an env var survives it.
 *
 * A fake `pint` reports the interpreter that actually ran it; the assertions are against the PARENT's
 * values, so the test is red whenever the child was spawned by shebang and green only when it inherits
 * the parent's interpreter, memory, and an xdebug-off environment.
 */
function fake_php_script(string $dir, string $name, string $body): string
{
    $path = $dir.'/'.$name;
    surgeon_write($path, "#!/usr/bin/env php\n<?php\n".$body."\n");
    chmod($path, 0755);

    return $path;
}

it('runs a PHP-shebang binary with the parent interpreter, memory limit and XDEBUG_MODE=off', function () {
    $dir = surgeon_tmp('stackrunner-php');
    $pint = fake_php_script($dir, 'pint', <<<'PHP'
        echo json_encode([
            'binary' => PHP_BINARY,
            'memory_limit' => ini_get('memory_limit'),
            'xdebug_mode' => getenv('XDEBUG_MODE'),
            'argv' => $argv,
        ]);
        PHP);

    $result = (new ProcessStackRunner)->run($dir, [$pint, '--test']);

    surgeon_rrmdir($dir);

    expect($result->ran)->toBeTrue()
        ->and($result->exitCode)->toBe(0);

    $child = json_decode($result->output, true);

    expect($child)->toBeArray()
        ->and($child['binary'])->toBe(PHP_BINARY)
        ->and($child['memory_limit'])->toBe(ini_get('memory_limit'))
        ->and($child['xdebug_mode'])->toBe('off')
        // argv is still the script and its own arguments — the interpreter is not visible to the tool.
        ->and($child['argv'])->toBe([$pint, '--test']);
});

it('leaves a non-PHP binary to its own shebang', function () {
    $dir = surgeon_tmp('stackrunner-sh');
    $script = $dir.'/eslint';
    surgeon_write($script, "#!/bin/sh\necho \"argv0=\$0 mode=\$XDEBUG_MODE\"\nexit 3\n");
    chmod($script, 0755);

    $result = (new ProcessStackRunner)->run($dir, [$script, '.']);

    surgeon_rrmdir($dir);

    expect($result->ran)->toBeTrue()
        ->and($result->exitCode)->toBe(3)
        ->and($result->output)->toBe('argv0='.$script.' mode=off');
});

it('still records an honest not-run when the binary cannot be spawned', function () {
    $dir = surgeon_tmp('stackrunner-missing');

    $result = (new ProcessStackRunner)->run($dir, [$dir.'/no-such-binary', '--test']);

    surgeon_rrmdir($dir);

    expect($result->ran)->toBeFalse();
});
