<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\UnsatisfiedRootRequireAudit;

/**
 * beam-facade ticket 129 — this root requires a package that is not in its own installed set. The
 * signature is a suite that dies at BOOT with zero assertions, which reports exactly like a real
 * regression; measured live at `splicewire/laravel-satellite` (12 red) and
 * `splicewire/laravel-satellite-training` (166 red), both `ConvergentTable not found`.
 */

/**
 * @param  array<string, mixed>  $manifest
 * @param  list<string>  $installed  package names composer records as installed here
 */
function rootRequireRoot(string $label, array $manifest, array $installed, ?array $overlay = null): string
{
    $root = surgeon_tmp('rootreq-'.$label);
    surgeon_write($root.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

    if ($overlay !== null) {
        surgeon_write($root.'/composer.local.json', json_encode($overlay, JSON_PRETTY_PRINT));
    }

    $packages = array_map(fn (string $name) => [
        'name' => $name,
        'install-path' => '../'.$name,
        'dist' => ['type' => 'composer'],
    ], $installed);

    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_PRETTY_PRINT));

    return $root;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function rootRequireFindings(string $root, DoctorStatus $status): array
{
    return array_values(array_filter(
        (new UnsatisfiedRootRequireAudit($root))->suggestOperations(),
        fn ($f) => $f->finding->status === $status,
    ));
}

it('fails for a `require` the root\'s own installed set does not carry, naming the boot signature', function () {
    $root = rootRequireRoot('missing', [
        'name' => 'acme/satellite',
        'require' => ['php' => '^8.3', 'acme/present' => '*', 'acme/convergence' => '*'],
    ], ['acme/present']);

    try {
        $failures = rootRequireFindings($root, DoctorStatus::Fail);

        expect($failures)->toHaveCount(1)
            ->and($failures[0]->finding->check)->toBe(UnsatisfiedRootRequireAudit::CHECK)
            ->and($failures[0]->finding->detail)->toContain('acme/convergence')
            ->and($failures[0]->finding->detail)->toContain('`require`')
            ->and($failures[0]->finding->detail)->toContain('zero assertions')
            ->and($failures[0]->suggestion?->payload)->toMatchArray([
                'command' => 'composer update acme/convergence -W',
                'missing' => 'acme/convergence',
                'block' => 'require',
            ]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('warns rather than fails for a missing require-dev, and names the merge-plugin consequence', function () {
    $root = rootRequireRoot('dev', [
        'name' => 'acme/pkg',
        'require-dev' => ['wikimedia/composer-merge-plugin' => '*'],
    ], ['acme/filler']);

    try {
        expect(rootRequireFindings($root, DoctorStatus::Fail))->toBeEmpty();

        $warnings = rootRequireFindings($root, DoctorStatus::Warn);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->detail)->toContain('`require-dev`')
            ->and($warnings[0]->finding->detail)->toContain('overlay is NOT being merged');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('resolves through replace/provide, which is composer\'s own rule', function () {
    $root = surgeon_tmp('rootreq-provide');
    surgeon_write($root.'/composer.json', json_encode([
        'name' => 'acme/pkg',
        'require' => ['acme/old-name' => '*'],
    ], JSON_PRETTY_PRINT));
    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/new-name',
        'install-path' => '../acme/new-name',
        'replace' => ['acme/old-name' => 'self.version'],
    ]]], JSON_PRETTY_PRINT));

    try {
        expect(rootRequireFindings($root, DoctorStatus::Fail))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('counts the overlay\'s own require as a require', function () {
    $root = rootRequireRoot('overlay', ['name' => 'acme/pkg', 'require' => []], ['acme/filler'], overlay: [
        'require' => ['acme/co-dev' => '*'],
    ]);

    try {
        $failures = rootRequireFindings($root, DoctorStatus::Fail);

        expect($failures)->toHaveCount(1)
            ->and($failures[0]->finding->detail)->toContain('acme/co-dev');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('stays silent where nothing has been installed at all — a different state, and reporting it would bury the real ones', function () {
    $root = surgeon_tmp('rootreq-noinstall');
    surgeon_write($root.'/composer.json', json_encode([
        'name' => 'acme/pkg',
        'require' => ['acme/anything' => '*'],
    ], JSON_PRETTY_PRINT));

    try {
        $findings = (new UnsatisfiedRootRequireAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(UnsatisfiedRootRequireAudit::CHECK.'.no-scope');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes cleanly and states that constraints are not evaluated', function () {
    $root = rootRequireRoot('clean', [
        'name' => 'acme/pkg',
        'require' => ['acme/present' => '^99.0'],
    ], ['acme/present']);

    try {
        $findings = (new UnsatisfiedRootRequireAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            // ^99.0 against whatever is installed: presence, never the constraint.
            ->and($findings[0]->finding->detail)->toContain('PRESENT, not that its version satisfies the constraint');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('ships on the built-in channel', function () {
    $classes = array_map(fn ($audit) => $audit::class, (new BuiltInAudits(__DIR__))->audits());

    expect($classes)->toContain(UnsatisfiedRootRequireAudit::class);
});
