<?php

use Illuminate\Console\Command;
use Rushing\Surgeon\Overlay\CanonicalizationAudit;
use Rushing\Surgeon\Overlay\CanonicalizationOperation;
use Rushing\Surgeon\Overlay\CanonicalizationPlan;
use Rushing\Surgeon\Overlay\Canonicalizer;
use Rushing\Surgeon\Overlay\RepoStatus;

/**
 * Stand up a project with a co-dev overlay requiring one or more path repos. Each `$name` becomes a
 * `packages/<pkg>/composer.json` checkout, required in both the base composer.json and the overlay, with
 * a matching `type: path` repo. Returns the base dir. The git *readiness* of each checkout is supplied to
 * the audit by an injected inspector (no real remotes needed), so these fixtures need no `.git/`.
 *
 * @param  list<string>  $names
 */
function canon_fixture(string $label, array $names = ['rushing/foo']): string
{
    $root = surgeon_tmp($label);

    $require = ['php' => '^8.3'];
    $overlayRequire = [];
    $repos = [];
    foreach ($names as $name) {
        [, $pkg] = explode('/', $name);
        surgeon_write($root."/packages/{$pkg}/composer.json", json_encode(['name' => $name]));
        $require[$name] = 'dev-main';
        $overlayRequire[$name] = 'dev-main';
        $repos[] = ['type' => 'path', 'url' => "packages/{$pkg}", 'options' => ['symlink' => true]];
    }

    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => $require], JSON_PRETTY_PRINT));
    surgeon_write($root.'/composer.local.json', json_encode(['require' => $overlayRequire, 'repositories' => $repos], JSON_PRETTY_PRINT));

    return $root;
}

/** An inspector that returns a fixed {@see RepoStatus} for every checkout it is asked about. */
function canon_inspector(RepoStatus $status): callable
{
    return fn (string $dir) => $status;
}

// --- CanonicalizationAudit (detect) ---------------------------------------------------------------

it('passes with no overlay — nothing to canonicalize', function () {
    $root = surgeon_tmp('canon-absent');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['php' => '^8.3']]));

    $findings = (new CanonicalizationAudit($root, canon_inspector(new RepoStatus(true, false, true, 0))))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('canonicalize.no-overlay')
        ->and($findings[0]->finding->status->value)->toBe('pass');

    surgeon_rrmdir($root);
});

it('passes when the overlay has no required path repos', function () {
    $root = surgeon_tmp('canon-nopath');
    // A path repo present but NOT required (harmless extra) — nothing to git-resolve.
    surgeon_write($root.'/packages/bar/composer.json', json_encode(['name' => 'acme/bar']));
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'require' => ['php' => '^8.3']], JSON_PRETTY_PRINT));
    surgeon_write($root.'/composer.local.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => 'packages/bar', 'options' => ['symlink' => true]]],
    ], JSON_PRETTY_PRINT));

    $findings = (new CanonicalizationAudit($root, canon_inspector(new RepoStatus(true, false, true, 0))))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('canonicalize.no-path-repos');

    surgeon_rrmdir($root);
});

it('is ready when every required checkout is clean, pushed, and in sync', function () {
    $root = canon_fixture('canon-ready', ['rushing/foo', 'rushing/bar']);

    $findings = (new CanonicalizationAudit($root, canon_inspector(new RepoStatus(true, false, true, 0))))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('canonicalize.ready')
        ->and($findings[0]->finding->status->value)->toBe('pass');

    surgeon_rrmdir($root);
});

it('hard-fails a dirty checkout with no fix (judgment stays with the human)', function () {
    $root = canon_fixture('canon-dirty');

    $findings = (new CanonicalizationAudit($root, canon_inspector(new RepoStatus(true, true, true, 0))))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('canonicalize.dirty')
        ->and($findings[0]->finding->status->value)->toBe('fail')
        ->and($findings[0]->isFixable())->toBeFalse();

    surgeon_rrmdir($root);
});

it('hard-fails a checkout with no upstream', function () {
    $root = canon_fixture('canon-noup');

    $findings = (new CanonicalizationAudit($root, canon_inspector(new RepoStatus(true, false, false, 0))))->suggestOperations();

    expect($findings[0]->finding->check)->toBe('canonicalize.no-upstream')
        ->and($findings[0]->finding->status->value)->toBe('fail')
        ->and($findings[0]->isFixable())->toBeFalse();

    surgeon_rrmdir($root);
});

it('hard-fails a path-repo checkout that is not a git repository', function () {
    $root = canon_fixture('canon-norepo');

    $findings = (new CanonicalizationAudit($root, canon_inspector(RepoStatus::notARepo())))->suggestOperations();

    expect($findings[0]->finding->check)->toBe('canonicalize.not-a-repo')
        ->and($findings[0]->finding->status->value)->toBe('fail');

    surgeon_rrmdir($root);
});

it('warns and nominates the canonicalize op for an ahead checkout (the auto-fixable case)', function () {
    $root = canon_fixture('canon-ahead');

    $findings = (new CanonicalizationAudit($root, canon_inspector(new RepoStatus(true, false, true, 3))))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('canonicalize.ahead')
        ->and($findings[0]->finding->status->value)->toBe('warn')
        ->and($findings[0]->isFixable())->toBeTrue()
        ->and($findings[0]->suggestion->kind)->toBe('canonicalize')
        ->and($findings[0]->suggestion->payload['ahead'])->toBe(3)
        ->and($findings[0]->suggestion->payload['package'])->toBe('rushing/foo');

    surgeon_rrmdir($root);
});

// --- Canonicalizer (perform, pure halves) ---------------------------------------------------------

it('collapses required package names to unique vendor globs', function () {
    expect(Canonicalizer::vendorGlobs(['rushing/foo', 'rushing/bar', 'acme/baz']))
        ->toBe(['rushing/*', 'acme/*']);
});

it('derives a plan naming the packages, globs, and the .git backup path', function () {
    $root = canon_fixture('canon-plan', ['rushing/foo', 'acme/bar']);
    $plan = (new Canonicalizer($root))->plan();

    expect($plan)->toBeInstanceOf(CanonicalizationPlan::class)
        ->and($plan->requiredPackages)->toContain('rushing/foo', 'acme/bar')
        ->and($plan->vendorGlobs)->toBe(['rushing/*', 'acme/*'])
        ->and($plan->overlayFile)->toBe($root.'/composer.local.json')
        ->and($plan->backupPath)->toBe($root.'/.git/composer.local.json.bak');

    surgeon_rrmdir($root);
});

it('takes the overlay offline to .git and relinks it (the scoped rollback)', function () {
    $root = canon_fixture('canon-offline');
    $canonicalizer = new Canonicalizer($root);
    $plan = $canonicalizer->plan();

    expect(is_file($root.'/composer.local.json'))->toBeTrue();

    expect($canonicalizer->takeOverlayOffline($plan))->toBeTrue();
    expect(is_file($root.'/composer.local.json'))->toBeFalse()
        ->and(is_file($plan->backupPath))->toBeTrue();

    // Idempotent: a second offline call when already offline is a no-op.
    expect($canonicalizer->takeOverlayOffline($plan))->toBeFalse();

    expect($canonicalizer->relink($plan))->toBeTrue();
    expect(is_file($root.'/composer.local.json'))->toBeTrue()
        ->and(is_file($plan->backupPath))->toBeFalse();

    surgeon_rrmdir($root);
});

it('counts surviving path sources in the lock — the shippable-lock gate', function () {
    $root = surgeon_tmp('canon-lock');
    $canonicalizer = new Canonicalizer($root);

    // A path-polluted lock (co-dev) has type:path sources; a git-resolved one has none.
    surgeon_write($root.'/composer.lock', json_encode([
        'packages' => [
            ['name' => 'rushing/foo', 'dist' => ['type' => 'path']],
            ['name' => 'rushing/bar', 'dist' => ['type' => 'path']],
        ],
    ], JSON_PRETTY_PRINT));
    expect($canonicalizer->pathSourceCount())->toBe(2);

    surgeon_write($root.'/composer.lock', json_encode([
        'packages' => [['name' => 'rushing/foo', 'dist' => ['type' => 'zip']]],
    ], JSON_PRETTY_PRINT));
    expect($canonicalizer->pathSourceCount())->toBe(0);

    surgeon_rrmdir($root);
});

// --- CanonicalizationOperation (identity) ---------------------------------------------------------

it('exposes canonicalize as a writer operation', function () {
    $op = new CanonicalizationOperation;

    expect($op->kind())->toBe('canonicalize')
        ->and($op->isWriter())->toBeTrue()
        ->and($op->describe())->toContain('shippable');
});

// --- surgeon:canonicalize command (dry-run) -------------------------------------------------------

it('runs the dry-run without writing anything', function () {
    $root = canon_fixture('canon-cmd');

    // The checkout is not a real git repo → the audit reports not-a-repo, but a dry-run only renders.
    $this->artisan('surgeon:canonicalize', ['--base' => $root])
        ->assertExitCode(Command::SUCCESS);

    // Nothing written: the overlay is untouched, no .git backup created.
    expect(is_file($root.'/composer.local.json'))->toBeTrue()
        ->and(is_file($root.'/.git/composer.local.json.bak'))->toBeFalse();

    surgeon_rrmdir($root);
});

it('emits machine-readable dry-run output with --json', function () {
    $root = canon_fixture('canon-cmd-json');

    $this->artisan('surgeon:canonicalize', ['--base' => $root, '--json' => true])
        ->assertExitCode(Command::SUCCESS);

    surgeon_rrmdir($root);
});

it('hard-stops --apply when a required checkout is not canonicalize-ready', function () {
    $root = canon_fixture('canon-cmd-apply');

    // packages/foo isn't a git repo → not-a-repo Fail → apply must refuse, overlay untouched.
    $this->artisan('surgeon:canonicalize', ['--base' => $root, '--apply' => true])
        ->assertExitCode(Command::FAILURE);

    expect(is_file($root.'/composer.local.json'))->toBeTrue()
        ->and(is_file($root.'/.git/composer.local.json.bak'))->toBeFalse();

    surgeon_rrmdir($root);
});
