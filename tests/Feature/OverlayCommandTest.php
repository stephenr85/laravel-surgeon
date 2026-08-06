<?php

use Rushing\Surgeon\Overlay\OverlayManifest;
use Rushing\Surgeon\Overlay\OverlayState;

/** A minimal active-overlay project: one required, resolvable path repo. */
function overlay_cmd_fixture(string $label): string
{
    $root = surgeon_tmp($label);
    surgeon_write($root.'/packages/foo/composer.json', json_encode(['name' => 'acme/foo']));
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['acme/foo' => 'dev-main']]));
    surgeon_write($root.'/composer.local.json', json_encode([
        'require' => ['acme/foo' => 'dev-main'],
        'repositories' => [['type' => 'path', 'url' => 'packages/foo', 'options' => ['symlink' => true]]],
    ], JSON_PRETTY_PRINT));

    return $root;
}

it('lists the overlay as JSON through the command', function () {
    $root = overlay_cmd_fixture('cmd-list');

    $this->artisan('surgeon:overlay', ['action' => 'list', '--base' => $root, '--json' => true])
        ->assertSuccessful();

    surgeon_rrmdir($root);
});

it('rejects an unknown action', function () {
    $root = overlay_cmd_fixture('cmd-bad');

    $this->artisan('surgeon:overlay', ['action' => 'frobnicate', '--base' => $root])
        ->assertExitCode(2); // Command::INVALID

    surgeon_rrmdir($root);
});

it('previews an add without writing, then applies it under --apply', function () {
    $root = overlay_cmd_fixture('cmd-add');
    surgeon_write($root.'/packages/baz/composer.json', json_encode(['name' => 'acme/baz']));

    // Preview: nothing written.
    $this->artisan('surgeon:overlay', [
        'action' => 'add', 'target' => 'packages/baz', '--base' => $root, '--require' => 'dev-main',
    ])->assertSuccessful();
    $before = json_decode(file_get_contents($root.'/composer.local.json'), true);
    expect(array_column($before['repositories'], 'url'))->not->toContain('packages/baz');

    // Apply: the repo + require land, and a touch-manifest sidecar is written.
    $this->artisan('surgeon:overlay', [
        'action' => 'add', 'target' => 'packages/baz', '--base' => $root, '--require' => 'dev-main', '--apply' => true,
    ])->assertSuccessful();

    $after = json_decode(file_get_contents($root.'/composer.local.json'), true);
    expect(array_column($after['repositories'], 'url'))->toContain('packages/baz')
        ->and($after['require']['acme/baz'])->toBe('dev-main')
        ->and(is_file($root.'/.surgeon/overlay-manifest.json'))->toBeTrue();

    surgeon_rrmdir($root);
});

it('materializes a template-only project through the command', function () {
    $root = surgeon_tmp('cmd-materialize');
    surgeon_write($root.'/packages/foo/composer.json', json_encode(['name' => 'acme/foo']));
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['acme/foo' => 'dev-main']]));
    surgeon_write($root.'/composer.local.json.dist', json_encode([
        'require' => ['acme/foo' => 'dev-main'],
        'repositories' => [['type' => 'path', 'url' => 'packages/foo']],
    ], JSON_PRETTY_PRINT));

    expect(OverlayManifest::fromDirectory($root)->state)->toBe(OverlayState::Template);

    $this->artisan('surgeon:overlay', ['action' => 'materialize', '--base' => $root, '--apply' => true])
        ->assertSuccessful();

    expect(OverlayManifest::fromDirectory($root)->state)->toBe(OverlayState::Active);

    surgeon_rrmdir($root);
});
