<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\RequireWithoutSupplyAudit;

/**
 * A require whose only declared source is a `type: path` repository — green on the machine that holds
 * the checkout, unresolvable on every other one. Every case runs against a real disposable tree,
 * because supply is a filesystem question: whether a path url reaches a package at all is decided by
 * what is on disk, glob included.
 */
function supplyRoot(string $label, array $app, array $siblings = []): string
{
    $root = surgeon_tmp($label);

    foreach ($siblings as $dir => $name) {
        surgeon_write($root.'/packages/'.$dir.'/composer.json', json_encode(['name' => $name]));
    }

    foreach ($app as $name => $contents) {
        surgeon_write($root.'/app/'.$name, json_encode($contents, JSON_PRETTY_PRINT));
    }

    return $root;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function supplyWarnings(string $appRoot): array
{
    return array_values(array_filter(
        (new RequireWithoutSupplyAudit($appRoot))->suggestOperations(),
        fn ($f) => $f->finding->status === DoctorStatus::Warn,
    ));
}

it('warns for a require whose only source is a path repo, citing the file and the url', function () {
    $root = supplyRoot('supply-pathonly', [
        'composer.json' => [
            'name' => 'vendor/app',
            'require' => ['php' => '^8.3', 'acme/local' => 'dev-main'],
        ],
        'composer.local.json' => [
            'repositories' => [['type' => 'path', 'url' => '../packages/local']],
        ],
    ], siblings: ['local' => 'acme/local']);

    try {
        $warnings = supplyWarnings($root.'/app');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->check)->toBe(RequireWithoutSupplyAudit::CHECK)
            ->and($warnings[0]->finding->detail)->toContain('acme/local is required')
            ->and($warnings[0]->finding->detail)->toContain('composer.local.json: "../packages/local"')
            ->and($warnings[0]->finding->detail)->toContain('Not checked: Packagist membership')
            // Which supply mechanism a repo adopts is an infrastructure call, never a splice.
            ->and($warnings[0]->isAdvisory())->toBeTrue();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('says nothing about a require that is not path-supplied at all', function () {
    // The overwhelming majority of requires: no path repo, so they resolve from Packagist or a
    // declared repository and this audit has no question to ask. Flagging them would bury the rest.
    $root = supplyRoot('supply-plain', [
        'composer.json' => [
            'require' => ['laravel/framework' => '^12.0', 'php' => '^8.3'],
        ],
    ]);

    try {
        $findings = (new RequireWithoutSupplyAudit($root.'/app'))->suggestOperations();

        expect(supplyWarnings($root.'/app'))->toBeEmpty()
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(RequireWithoutSupplyAudit::CHECK.'.clean');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('accepts a vcs entry as supply, matching the package stem against the remote url', function () {
    // A vcs entry names a remote, never a package, so the stem in the url is the only offline reading
    // available — and it is the estate's actual spelling.
    $root = supplyRoot('supply-vcs', [
        'composer.json' => [
            'require' => ['acme/local' => 'dev-main'],
            'repositories' => [
                ['type' => 'vcs', 'url' => 'https://github.com/acme/local.git'],
                ['type' => 'path', 'url' => '../packages/local'],
            ],
        ],
    ], siblings: ['local' => 'acme/local']);

    try {
        expect(supplyWarnings($root.'/app'))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('accepts an inlined package repository by exact name, not by url', function () {
    $root = supplyRoot('supply-inline', [
        'composer.json' => [
            'require' => ['acme/local' => 'dev-main'],
            'repositories' => [
                ['type' => 'package', 'package' => ['name' => 'acme/local', 'version' => '1.0.0']],
                ['type' => 'path', 'url' => '../packages/local'],
            ],
        ],
    ], siblings: ['local' => 'acme/local']);

    try {
        expect(supplyWarnings($root.'/app'))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('expands a glob path repo, because `../*` supplies its packages exactly like a named entry', function () {
    $root = supplyRoot('supply-glob', [
        'composer.json' => ['require' => ['acme/one' => '*', 'acme/two' => '*']],
        'composer.local.json' => ['repositories' => [['type' => 'path', 'url' => '../packages/*']]],
    ], siblings: ['one' => 'acme/one', 'two' => 'acme/two']);

    try {
        expect(supplyWarnings($root.'/app'))->toHaveCount(2);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reads the overlay\'s own require block, since co-dev intent is still a require', function () {
    $root = supplyRoot('supply-overlay-require', [
        'composer.json' => ['name' => 'vendor/app'],
        'composer.local.json' => [
            'require' => ['acme/local' => 'dev-main'],
            'repositories' => [['type' => 'path', 'url' => '../packages/local']],
        ],
    ], siblings: ['local' => 'acme/local']);

    try {
        expect(supplyWarnings($root.'/app'))->toHaveCount(1);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('carries a caveat rather than staying silent when the repo declares a private registry', function () {
    // A composer-type registry may or may not carry the package and this audit does not go and look —
    // so it says both things instead of picking one.
    $root = supplyRoot('supply-registry', [
        'composer.json' => [
            'require' => ['acme/local' => 'dev-main'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://repo.example.test'],
                ['type' => 'path', 'url' => '../packages/local'],
            ],
        ],
    ], siblings: ['local' => 'acme/local']);

    try {
        $warnings = supplyWarnings($root.'/app');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->detail)->toContain('https://repo.example.test')
            ->and($warnings[0]->finding->detail)->toContain('which is not read here');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered as a built-in, so surgeon:audit runs it in every host', function () {
    $classes = array_map(fn ($a) => $a::class, (new BuiltInAudits('/irrelevant'))->audits());

    expect($classes)->toContain(RequireWithoutSupplyAudit::class);
});

it('is advisory in the sweep — an undeclared source never reddens the exit code by itself', function () {
    $report = (new Rushing\Surgeon\Operation\ConformanceSweep(fn (string $a) => new $a))
        ->run(new Rushing\Surgeon\Operation\NullConformanceManifest, [new RequireWithoutSupplyAudit('/irrelevant')]);

    expect($report->hasGateFailure())->toBeFalse()
        ->and($report->results[0]->gate)->toBeFalse();
});
