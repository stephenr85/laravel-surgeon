<?php

use Rushing\Surgeon\Overlay\OverlayAudit;
use Rushing\Surgeon\Overlay\OverlayDiff;
use Rushing\Surgeon\Overlay\OverlayEditor;
use Rushing\Surgeon\Overlay\OverlayManifest;
use Rushing\Surgeon\Overlay\OverlayOperation;
use Rushing\Surgeon\Overlay\OverlayState;
use Rushing\Surgeon\Overlay\OverlayVerb;

/**
 * Stand up a project with a co-dev overlay: a base composer.json requiring `acme/foo`, and an overlay
 * (`composer.local.json`) with three path repos — foo (required + present), bar (present but unrequired
 * = harmless extra), missing (declared but its checkout is gone = broken). Returns the base dir.
 */
function overlay_fixture(string $label, bool $active = true, array $overlayRequire = ['acme/foo' => 'dev-main']): string
{
    $root = surgeon_tmp($label);

    surgeon_write($root.'/packages/foo/composer.json', json_encode(['name' => 'acme/foo']));
    surgeon_write($root.'/packages/bar/composer.json', json_encode(['name' => 'acme/bar']));

    surgeon_write($root.'/composer.json', json_encode([
        'name' => 'acme/app',
        'require' => ['php' => '^8.3', 'acme/foo' => 'dev-main'],
    ], JSON_PRETTY_PRINT));

    $overlay = json_encode([
        'require' => $overlayRequire,
        'repositories' => [
            ['type' => 'path', 'url' => 'packages/foo', 'options' => ['symlink' => true]],
            ['type' => 'path', 'url' => 'packages/bar', 'options' => ['symlink' => true]],
            ['type' => 'path', 'url' => 'packages/missing', 'options' => ['symlink' => true]],
        ],
    ], JSON_PRETTY_PRINT);

    surgeon_write($root.'/composer.local.json'.($active ? '' : '.dist'), $overlay);

    return $root;
}

// --- OverlayManifest (discovery) ------------------------------------------------------------------

it('resolves overlay path repos into required / extra / broken', function () {
    $root = overlay_fixture('manifest');
    $manifest = OverlayManifest::fromDirectory($root);

    expect($manifest->state)->toBe(OverlayState::Active)
        ->and($manifest->entries)->toHaveCount(3)
        ->and($manifest->requiredEntries())->toHaveCount(1)
        ->and($manifest->requiredEntries()[0]->name)->toBe('acme/foo')
        ->and($manifest->extraEntries())->toHaveCount(1)
        ->and($manifest->extraEntries()[0]->name)->toBe('acme/bar')
        ->and($manifest->brokenEntries())->toHaveCount(1)
        ->and($manifest->brokenEntries()[0]->url)->toBe('packages/missing');

    surgeon_rrmdir($root);
});

it('reports the template state when only composer.local.json.dist exists', function () {
    $root = overlay_fixture('template', active: false);

    expect(OverlayManifest::fromDirectory($root)->state)->toBe(OverlayState::Template);

    surgeon_rrmdir($root);
});

it('reports the absent state when there is no overlay file at all', function () {
    $root = surgeon_tmp('absent');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['php' => '^8.3']]));

    $manifest = OverlayManifest::fromDirectory($root);
    expect($manifest->state)->toBe(OverlayState::Absent)
        ->and($manifest->entries)->toBe([]);

    surgeon_rrmdir($root);
});

it('flags an overlay require that has no path repo to satisfy it', function () {
    $root = overlay_fixture('require-nopath', overlayRequire: ['acme/foo' => 'dev-main', 'acme/nopath' => 'dev-main']);

    expect(OverlayManifest::fromDirectory($root)->requiresWithoutPathRepo())->toBe(['acme/nopath']);

    surgeon_rrmdir($root);
});

// --- OverlayEditor (mutation) ---------------------------------------------------------------------

it('adds a path repo and require entry, previewably then applied', function () {
    $root = overlay_fixture('add');
    $mutation = (new OverlayEditor($root))->add('packages/baz', 'dev-main', 'acme/baz');

    expect($mutation->verb)->toBe(OverlayVerb::Add)
        ->and($mutation->isEmpty())->toBeFalse();

    // Nothing written until apply().
    $before = json_decode(file_get_contents($root.'/composer.local.json'), true);
    expect($before['repositories'])->toHaveCount(3);

    $mutation->apply();
    $after = json_decode(file_get_contents($root.'/composer.local.json'), true);
    $urls = array_column($after['repositories'], 'url');
    expect($urls)->toContain('packages/baz')
        ->and($after['require']['acme/baz'])->toBe('dev-main');

    surgeon_rrmdir($root);
});

it('does not duplicate a path repo that is already declared', function () {
    $root = overlay_fixture('add-dup');
    (new OverlayEditor($root))->add('packages/foo')->apply();

    $after = json_decode(file_get_contents($root.'/composer.local.json'), true);
    expect(array_column($after['repositories'], 'url'))->toHaveCount(3); // unchanged

    surgeon_rrmdir($root);
});

it('removes a path repo and its require by package name', function () {
    $root = overlay_fixture('remove');
    (new OverlayEditor($root))->remove('acme/foo')->apply();

    $after = json_decode(file_get_contents($root.'/composer.local.json'), true);
    expect(array_column($after['repositories'], 'url'))->not->toContain('packages/foo')
        ->and($after['require'] ?? [])->not->toHaveKey('acme/foo');

    surgeon_rrmdir($root);
});

it('removes a path repo by literal url too', function () {
    $root = overlay_fixture('remove-url');
    (new OverlayEditor($root))->remove('packages/bar')->apply();

    $after = json_decode(file_get_contents($root.'/composer.local.json'), true);
    expect(array_column($after['repositories'], 'url'))->not->toContain('packages/bar');

    surgeon_rrmdir($root);
});

it('materializes the overlay from a template', function () {
    $root = overlay_fixture('materialize', active: false);
    expect(is_file($root.'/composer.local.json'))->toBeFalse();

    $mutation = (new OverlayEditor($root))->materialize();
    expect($mutation->verb)->toBe(OverlayVerb::Materialize);
    $mutation->apply();

    expect(is_file($root.'/composer.local.json'))->toBeTrue()
        ->and(OverlayManifest::fromDirectory($root)->state)->toBe(OverlayState::Active);

    surgeon_rrmdir($root);
});

it('tears down the overlay and rolls back to restore it', function () {
    $root = overlay_fixture('teardown');
    $mutation = (new OverlayEditor($root))->tearDown();

    $mutation->apply();
    expect(is_file($root.'/composer.local.json'))->toBeFalse();

    $mutation->rollback();
    expect(is_file($root.'/composer.local.json'))->toBeTrue();

    surgeon_rrmdir($root);
});

it('refuses to edit when there is no active overlay', function () {
    $root = overlay_fixture('no-active', active: false);

    expect(fn () => (new OverlayEditor($root))->remove('acme/foo'))
        ->toThrow(InvalidArgumentException::class);

    surgeon_rrmdir($root);
});

it('syncs additively toward a canonical set, never removing extras', function () {
    $mine = overlay_fixture('sync-mine');
    $canonical = overlay_fixture('sync-canon');
    // Give the canonical set a repo mine lacks.
    (new OverlayEditor($canonical))->add('packages/extra-canonical')->apply();

    $canonicalUrls = array_map(fn ($e) => $e->url, OverlayManifest::fromDirectory($canonical)->entries);
    $mutation = (new OverlayEditor($mine))->sync($canonicalUrls);
    $mutation->apply();

    $after = json_decode(file_get_contents($mine.'/composer.local.json'), true);
    $urls = array_column($after['repositories'], 'url');
    expect($urls)->toContain('packages/extra-canonical')     // additive: the missing one landed
        ->and($urls)->toContain('packages/bar');             // never removed an extra

    surgeon_rrmdir($mine);
    surgeon_rrmdir($canonical);
});

it('produces an empty sync mutation when already in sync', function () {
    $root = overlay_fixture('sync-noop');
    $urls = array_map(fn ($e) => $e->url, OverlayManifest::fromDirectory($root)->entries);

    expect((new OverlayEditor($root))->sync($urls)->isEmpty())->toBeTrue();

    surgeon_rrmdir($root);
});

// --- OverlayDiff ----------------------------------------------------------------------------------

it('diffs a project overlay against a canonical set by url', function () {
    $mine = overlay_fixture('diff-mine');
    $canonical = overlay_fixture('diff-canon');
    (new OverlayEditor($canonical))->add('packages/only-canonical')->apply();
    (new OverlayEditor($mine))->add('packages/only-mine')->apply();

    $diff = OverlayDiff::between(
        OverlayManifest::fromDirectory($mine),
        OverlayManifest::fromDirectory($canonical),
    );

    expect($diff->inSync())->toBeFalse()
        ->and($diff->missing)->toContain('packages/only-canonical')
        ->and($diff->extra)->toContain('packages/only-mine');

    surgeon_rrmdir($mine);
    surgeon_rrmdir($canonical);
});

// --- OverlayAudit (the bridge) --------------------------------------------------------------------

it('emits a Fail + overlay-remove nomination for a broken path repo', function () {
    $root = overlay_fixture('audit-broken');
    $findings = (new OverlayAudit($root))->suggestOperations();

    $broken = collect($findings)->first(fn ($f) => $f->finding->check === 'overlay.broken-path-repo');
    expect($broken)->not->toBeNull()
        ->and($broken->finding->status->value)->toBe('fail')
        ->and($broken->isFixable())->toBeTrue()
        ->and($broken->suggestion->kind)->toBe('overlay-remove')
        ->and($broken->suggestion->payload['url'])->toBe('packages/missing');

    surgeon_rrmdir($root);
});

it('emits a Warn + overlay-materialize nomination when the overlay is off', function () {
    $root = overlay_fixture('audit-off', active: false);
    $findings = (new OverlayAudit($root))->suggestOperations();

    $off = collect($findings)->first(fn ($f) => $f->finding->check === 'overlay.off');
    expect($off)->not->toBeNull()
        ->and($off->isFixable())->toBeTrue()
        ->and($off->suggestion->kind)->toBe('overlay-materialize');

    surgeon_rrmdir($root);
});

it('emits a plain diagnosis (no suggestion) for a require without a path repo', function () {
    $root = overlay_fixture('audit-nopath', overlayRequire: ['acme/foo' => 'dev-main', 'acme/nopath' => 'dev-main']);
    $findings = (new OverlayAudit($root))->suggestOperations();

    $nopath = collect($findings)->first(fn ($f) => $f->finding->check === 'overlay.require-without-path-repo');
    expect($nopath)->not->toBeNull()
        ->and($nopath->finding->status->value)->toBe('warn')
        ->and($nopath->suggestion)->toBeNull()      // locating the checkout is a judgment call
        ->and($nopath->isFixable())->toBeFalse();

    surgeon_rrmdir($root);
});

it('passes clean when every required path repo resolves and the overlay is active', function () {
    $root = surgeon_tmp('audit-clean');
    surgeon_write($root.'/packages/foo/composer.json', json_encode(['name' => 'acme/foo']));
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['acme/foo' => 'dev-main']]));
    surgeon_write($root.'/composer.local.json', json_encode([
        'require' => ['acme/foo' => 'dev-main'],
        'repositories' => [['type' => 'path', 'url' => 'packages/foo']],
    ]));

    $findings = (new OverlayAudit($root))->suggestOperations();
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->status->value)->toBe('pass')
        ->and($findings[0]->finding->check)->toBe('overlay.healthy');

    surgeon_rrmdir($root);
});

it('passes with an absent-overlay note when there is no overlay', function () {
    $root = surgeon_tmp('audit-absent');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['php' => '^8.3']]));

    $findings = (new OverlayAudit($root))->suggestOperations();
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('overlay.absent');

    surgeon_rrmdir($root);
});

// --- OverlayOperation -----------------------------------------------------------------------------

it('exposes each overlay verb as a writer Operation with an overlay-<verb> kind', function () {
    $op = new OverlayOperation(OverlayVerb::Add);
    expect($op->kind())->toBe('overlay-add')
        ->and($op->isWriter())->toBeTrue()
        ->and($op->describe())->toContain('overlay');

    expect((new OverlayOperation(OverlayVerb::Materialize))->kind())->toBe('overlay-materialize');
});
