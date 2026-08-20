<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\DanglingPathRepoAudit;

/**
 * A composer `type: path` repository whose target directory is gone — the defect that aborts EVERY
 * composer command in a repo at parse time. Every case runs against a real disposable tree, because
 * the whole audit is a filesystem-resolution question and the regression that matters
 * (`realpath()`-rooted resolution behind a symlink) cannot be faked with an injected reader.
 */
function danglingRepoRoot(string $label, array $files, array $siblings = []): string
{
    $root = surgeon_tmp($label);

    foreach ($siblings as $sibling) {
        surgeon_write($root.'/packages/'.$sibling.'/composer.json', json_encode(['name' => 'vendor/'.$sibling]));
    }

    foreach ($files as $name => $contents) {
        surgeon_write($root.'/app/'.$name, json_encode($contents, JSON_PRETTY_PRINT));
    }

    return $root;
}

it('fails on a dangling path repo in composer.json, naming the file, the entry, and the missing path', function () {
    $root = danglingRepoRoot('dangling-base', [
        'composer.json' => [
            'name' => 'vendor/app',
            'repositories' => [
                ['type' => 'path', 'url' => '../packages/alive'],
                ['type' => 'path', 'url' => '../packages/deleted'],
                ['type' => 'composer', 'url' => 'https://packagist.org'],
            ],
        ],
    ], siblings: ['alive']);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Fail)
            ->and($findings[0]->finding->check)->toBe(DanglingPathRepoAudit::CHECK)
            ->and($findings[0]->finding->detail)->toContain('composer.json')
            ->and($findings[0]->finding->detail)->toContain('[#1]')
            ->and($findings[0]->finding->detail)->toContain('../packages/deleted')
            ->and($findings[0]->finding->detail)->toContain(realpath($root).'/packages/deleted')
            ->and($findings[0]->finding->detail)->toContain('EVERY composer command');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('resolves relative urls through a SYMLINKED repo directory, not its literal path', function () {
    // The trap: `/Users/stephen/Herd/<site>` is frequently a symlink into a workspace tree. Joining
    // `..` onto the LITERAL directory and collapsing it lexically walks up the symlink's parent, so
    // every live entry in the file reads as dead — the failure mode that emptied a healthy 77-entry
    // config by hand. Resolution must start from realpath() of the config's directory.
    $real = surgeon_tmp('dangling-symlink-real');
    $link = sys_get_temp_dir().'/surgeon-dangling-symlink-'.bin2hex(random_bytes(4));

    // The sibling lives beside the REAL location; nothing at all sits beside the symlink.
    surgeon_write($real.'/packages/alive/composer.json', json_encode(['name' => 'vendor/alive']));
    surgeon_write($real.'/app/composer.local.json', json_encode([
        'repositories' => [
            ['type' => 'path', 'url' => '../packages/alive'],
            ['type' => 'path', 'url' => '../packages/deleted'],
        ],
    ], JSON_PRETTY_PRINT));

    symlink($real.'/app', $link);

    try {
        // Sanity: the symlink genuinely hides the sibling from a lexical reading of the path.
        expect(is_dir(dirname($link).'/packages/alive'))->toBeFalse();

        $findings = (new DanglingPathRepoAudit($link))->suggestOperations();

        // Exactly one dangling entry — the live sibling is seen THROUGH the symlink, not reported dead.
        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->detail)->toContain('../packages/deleted')
            ->and($findings[0]->finding->detail)->toContain(realpath($real).'/packages/deleted')
            ->and($findings[0]->finding->detail)->not->toContain('alive');
    } finally {
        @unlink($link);
        surgeon_rrmdir($real);
    }
});

it('reads the name-keyed map shape of repositories as well as the list shape', function () {
    $root = danglingRepoRoot('dangling-map', [
        'composer.json' => [
            'name' => 'vendor/app',
            'repositories' => [
                'local-alive' => ['type' => 'path', 'url' => '../packages/alive'],
                'local-gone' => ['type' => 'path', 'url' => '../packages/gone'],
                'packagist.org' => false,
            ],
        ],
    ], siblings: ['alive']);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->detail)->toContain('[local-gone]');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('tolerates a wildcard url that expands to nothing, exactly as composer does', function () {
    // Composer accepts a glob with zero matches provided its de-magicked ancestor is a directory —
    // `../*` is the estate's dominant spelling, so flagging it would bury every real finding.
    $root = danglingRepoRoot('dangling-glob-ok', [
        'composer.json' => [
            'repositories' => [
                ['type' => 'path', 'url' => '../packages/*'],       // expands to the sibling
                ['type' => 'path', 'url' => '../packages/*/*'],     // magic, expands to nothing, ancestor lives
            ],
        ],
    ], siblings: ['alive']);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('fails a wildcard whose de-magicked ancestor is itself gone, citing that ancestor', function () {
    $root = danglingRepoRoot('dangling-glob-dead', [
        'composer.json' => ['repositories' => [['type' => 'path', 'url' => '../retired/*']]],
    ], siblings: ['alive']);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Fail)
            ->and($findings[0]->finding->detail)->toContain('../retired/*')
            ->and($findings[0]->finding->detail)->toContain(realpath($root).'/retired,');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('nominates overlay-remove for the live overlay, and stays advisory for the ship contract', function () {
    $root = danglingRepoRoot('dangling-seam', [
        'composer.json' => ['repositories' => [['type' => 'path', 'url' => '../packages/gone-a']]],
        'composer.local.json' => ['repositories' => [['type' => 'path', 'url' => '../packages/gone-b']]],
    ]);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();
        expect($findings)->toHaveCount(2);

        [$base, $overlay] = $findings;

        expect($base->isAdvisory())->toBeTrue()
            ->and($base->suggestion->owningPackage)->toBe('rushing/laravel-surgeon')
            ->and($overlay->isFixable())->toBeTrue()
            ->and($overlay->suggestion->kind)->toBe('overlay-remove')
            ->and($overlay->suggestion->payload)->toBe([
                'url' => '../packages/gone-b',
                'file' => 'composer.local.json',
            ]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('warns separately for a template, which is a latent brick rather than a live one', function () {
    $root = danglingRepoRoot('dangling-template', [
        'composer.local.json.dist' => ['repositories' => [['type' => 'path', 'url' => '../packages/gone']]],
    ]);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Warn)
            ->and($findings[0]->finding->check)->toBe(DanglingPathRepoAudit::CHECK.'.template')
            ->and($findings[0]->finding->detail)->toContain('composer.local.json.dist')
            ->and($findings[0]->finding->detail)->toContain('materializing this template');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('honours an absolute path url and passes cleanly when every target exists', function () {
    $root = danglingRepoRoot('dangling-clean', [], siblings: ['alive']);
    surgeon_write($root.'/app/composer.json', json_encode([
        'repositories' => [
            ['type' => 'path', 'url' => '../packages/alive'],
            ['type' => 'path', 'url' => realpath($root).'/packages/alive'],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(DanglingPathRepoAudit::CHECK.'.clean')
            ->and($findings[0]->finding->detail)->toContain('All 2 type:path');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes with a distinct check when the repo declares no path repositories at all', function () {
    $root = danglingRepoRoot('dangling-no-scope', [
        'composer.json' => ['name' => 'vendor/app', 'require' => ['php' => '^8.3']],
    ]);

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(DanglingPathRepoAudit::CHECK.'.no-scope');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('skips an unparseable composer config without aborting the audit', function () {
    $root = danglingRepoRoot('dangling-unparseable', [
        'composer.local.json' => ['repositories' => [['type' => 'path', 'url' => '../packages/gone']]],
    ]);
    surgeon_write($root.'/app/composer.json', '{ this is not json');

    try {
        $findings = (new DanglingPathRepoAudit($root.'/app'))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->detail)->toContain('composer.local.json');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered as a built-in, so surgeon:audit runs it in every host', function () {
    $classes = array_map(fn ($a) => $a::class, (new BuiltInAudits('/irrelevant'))->audits());

    expect($classes)->toContain(DanglingPathRepoAudit::class);
});

it('is advisory in the sweep — a dangling path repo never reddens the exit code by itself', function () {
    // Built-ins ride the advisory channel (ConformanceSweep passes gate: false), matching the
    // sibling audits: the sweep diagnoses, it does not fail a build.
    $report = (new Rushing\Surgeon\Operation\ConformanceSweep(fn (string $a) => new $a))
        ->run(new Rushing\Surgeon\Operation\NullConformanceManifest, [new DanglingPathRepoAudit('/irrelevant')]);

    expect($report->hasGateFailure())->toBeFalse()
        ->and($report->results[0]->gate)->toBeFalse();
});
