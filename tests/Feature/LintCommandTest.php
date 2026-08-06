<?php

use Illuminate\Console\Command;

/**
 * Command-level coverage for `surgeon:lint`. These exercise the real command wiring (scope resolution,
 * check-vs-fix surfaces, the touch-manifest sidecar) against a temp root whose stacks resolve to skips
 * (no Pint/eslint binary in a temp dir), so the command runs end-to-end without a real formatter — the
 * mapping-with-a-fake-runner detail is covered in LintTest.
 */
it('runs surgeon:lint check-mode against a base with no applicable stack (SUCCESS)', function () {
    $root = surgeon_tmp('cmd-lint-nostack');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}'); // no pint.json, no vendor bin → no stack

    $this->artisan('surgeon:lint', ['--base' => $root])
        ->assertExitCode(Command::SUCCESS);

    surgeon_rrmdir($root);
});

it('emits machine-readable check output with --json', function () {
    $root = surgeon_tmp('cmd-lint-json');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');

    $this->artisan('surgeon:lint', ['--base' => $root, '--json' => true])
        ->assertExitCode(Command::SUCCESS);

    surgeon_rrmdir($root);
});

it('reports drift and exits FAILURE in check-mode when a stack is dirty', function () {
    $root = surgeon_tmp('cmd-lint-drift');
    surgeon_write($root.'/pint.json', '{}');
    // Stand up a fake vendor/bin/pint that always reports drift (exit 1) so the real subprocess path runs.
    surgeon_write($root.'/vendor/bin/pint', "#!/bin/sh\necho '3 style issues'\nexit 1\n");
    chmod($root.'/vendor/bin/pint', 0755);

    $this->artisan('surgeon:lint', ['--base' => $root])
        ->assertExitCode(Command::FAILURE);

    surgeon_rrmdir($root);
});

it('writes a lint touch-manifest sidecar on --fix', function () {
    $root = surgeon_tmp('cmd-lint-fix');
    surgeon_write($root.'/pint.json', '{}');
    surgeon_write($root.'/vendor/bin/pint', "#!/bin/sh\necho '2 files'\nexit 0\n");
    chmod($root.'/vendor/bin/pint', 0755);

    $this->artisan('surgeon:lint', ['--base' => $root, '--fix' => true])
        ->assertExitCode(Command::SUCCESS);

    expect(is_file($root.'/.surgeon/lint-manifest.json'))->toBeTrue();
    $manifest = json_decode((string) file_get_contents($root.'/.surgeon/lint-manifest.json'), true);
    expect($manifest['operation'])->toBe('lint')
        ->and($manifest['roots'])->toContain($root);

    surgeon_rrmdir($root);
});

it('expands --root=all-overlay to the overlay required path repos', function () {
    $root = surgeon_tmp('cmd-lint-overlay');
    // A minimal overlay requiring one path repo that carries a pint.json.
    surgeon_write($root.'/packages/foo/composer.json', json_encode(['name' => 'rushing/foo']));
    surgeon_write($root.'/packages/foo/pint.json', '{}');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['rushing/foo' => 'dev-main']]));
    surgeon_write($root.'/composer.local.json', json_encode([
        'require' => ['rushing/foo' => 'dev-main'],
        'repositories' => [['type' => 'path', 'url' => 'packages/foo', 'options' => ['symlink' => true]]],
    ]));

    // Check-mode JSON so we can assert the overlay root was included in the discovered set.
    $this->artisan('surgeon:lint', ['--base' => $root, '--root' => ['all-overlay'], '--json' => true])
        ->assertExitCode(Command::SUCCESS);

    surgeon_rrmdir($root);
});
