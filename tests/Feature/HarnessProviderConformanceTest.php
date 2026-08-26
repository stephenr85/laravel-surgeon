<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\HarnessProviderAudit;

/**
 * api-surface-coherence ticket 86 — a package's testbench harness never boots a provider its own `src/`
 * imports. Every case runs against a real disposable tree with a real `vendor/composer/installed.json`,
 * because the defect is a disagreement between a dependency's `extra.laravel.providers` and a package's
 * `tests/`, and only a filesystem holds both.
 */

/**
 * Stand up a host root with path-installed neighbours.
 *
 * @param  array<string, array{source?: string, require?: array<string,string>, providers?: list<string>, prefix?: string, src?: array<string,string>, tests?: array<string,string>}>  $packages
 */
function harnessRoot(string $label, array $packages): string
{
    $root = surgeon_tmp('harness-'.$label);
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/host-app'], JSON_PRETTY_PRINT));

    $installed = [];
    foreach ($packages as $name => $spec) {
        $prefix = $spec['prefix'] ?? ('Acme\\'.str_replace('-', '', ucfirst(basename($name))).'\\');
        $installed[] = [
            'name' => $name,
            'install-path' => '../'.$name,
            'dist' => ['type' => $spec['source'] ?? 'composer'],
            'autoload' => ['psr-4' => [$prefix => 'src/']],
        ];

        $manifest = ['name' => $name, 'require' => $spec['require'] ?? []];
        if (($spec['providers'] ?? []) !== []) {
            $manifest['extra'] = ['laravel' => ['providers' => $spec['providers']]];
        }
        surgeon_write($root.'/vendor/'.$name.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

        foreach (($spec['src'] ?? []) as $file => $contents) {
            surgeon_write($root.'/vendor/'.$name.'/src/'.$file, $contents);
        }
        foreach (($spec['tests'] ?? []) as $file => $contents) {
            surgeon_write($root.'/vendor/'.$name.'/tests/'.$file, $contents);
        }
    }

    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_PRETTY_PRINT));

    return $root;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function harnessWarnings(string $root): array
{
    return array_values(array_filter(
        (new HarnessProviderAudit($root))->suggestOperations(),
        fn ($f) => $f->finding->status === DoctorStatus::Warn,
    ));
}

function harnessSummary(string $root): string
{
    $findings = (new HarnessProviderAudit($root))->suggestOperations();

    return end($findings)->finding->detail;
}

/** The measured shape: a package importing a provider-shipping dependency its harness never names. */
function laravelDataShaped(array $overrides = []): array
{
    return [
        'vendor/data' => [
            'prefix' => 'Vendor\\Data\\',
            'providers' => ['Vendor\\Data\\LaravelDataServiceProvider'],
        ],
        'acme/consumer' => [
            'source' => 'path',
            'require' => ['php' => '^8.3', 'vendor/data' => '^4.0'],
            'src' => ['Thing.php' => "<?php\n\nnamespace Acme\\Consumer;\n\nuse Vendor\\Data\\Data;\n\nclass Thing extends Data {}\n"],
            'tests' => ['TestCase.php' => "<?php\n\nuse Orchestra\\Testbench\\TestCase as Orchestra;\n\nclass TestCase extends Orchestra\n{\n    protected function getPackageProviders(\$app): array\n    {\n        return [ConsumerServiceProvider::class];\n    }\n}\n"],
        ],
    ] + $overrides;
}

it('warns when a path-installed package imports a provider-shipping dependency its harness never names', function () {
    $root = harnessRoot('gap', laravelDataShaped());

    try {
        $warnings = harnessWarnings($root);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->check)->toBe(HarnessProviderAudit::CHECK)
            ->and($warnings[0]->finding->detail)->toContain('acme/consumer imports vendor/data')
            ->and($warnings[0]->finding->detail)->toContain('Vendor\\Data\\LaravelDataServiceProvider')
            ->and($warnings[0]->suggestion?->summary)->toContain('LaravelDataServiceProvider::class');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is silent once the harness names the provider', function () {
    $packages = laravelDataShaped();
    $packages['acme/consumer']['tests']['TestCase.php'] = "<?php\n\nuse Orchestra\\Testbench\\TestCase as Orchestra;\nuse Vendor\\Data\\LaravelDataServiceProvider;\n\nclass TestCase extends Orchestra\n{\n    protected function getPackageProviders(\$app): array\n    {\n        return [LaravelDataServiceProvider::class];\n    }\n}\n";
    $root = harnessRoot('covered', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
        expect(harnessSummary($root))->toContain('is named in that package\'s own `tests/`');
    } finally {
        surgeon_rrmdir($root);
    }
});

/**
 * The false-positive control, measured: a raw text sweep over the thirteen flagged two packages whose
 * ONLY mention of the dependency is a docblock and a default option string naming a base class for
 * GENERATED code. Neither loads it, so neither is a hole.
 */
it('does not flag a dependency named only in a docblock or a class-string, because neither is a use', function () {
    $packages = laravelDataShaped();
    $packages['acme/consumer']['src'] = [
        'Generator.php' => "<?php\n\nnamespace Acme\\Consumer;\n\n/**\n * The base class defaults to `Vendor\\Data\\Data` and is referenced as a string.\n */\nclass Generator\n{\n    public string \$base = 'Vendor\\\\Data\\\\Data';\n}\n",
    ];
    $root = harnessRoot('docblock', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
    } finally {
        surgeon_rrmdir($root);
    }
});

/**
 * The under-report a require-scoped read produced: a package consuming a namespace it never declares.
 * The dependency arrives transitively and autoloads perfectly, so `src/` compiles and no manifest check
 * can see it — five of the thirteen were this shape. The finding stamps the second repair.
 */
it('flags a consumed package the manifest never declares, and names the second repair', function () {
    $packages = laravelDataShaped();
    $packages['acme/consumer']['require'] = ['php' => '^8.3'];
    $root = harnessRoot('undeclared', $packages);

    try {
        $warnings = harnessWarnings($root);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->detail)->toContain('does NOT declare vendor/data in its own `require`');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('does not flag a require the package never imports at all', function () {
    $packages = laravelDataShaped();
    $packages['acme/consumer']['src'] = ['Thing.php' => "<?php\n\nnamespace Acme\\Consumer;\n\nclass Thing {}\n"];
    $root = harnessRoot('unused', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
    } finally {
        surgeon_rrmdir($root);
    }
});

/**
 * The gate that keeps the running HOST out of its own report. A host boots every installed package's
 * providers through Laravel's discovery, so it cannot carry this defect — and the audit runs inside a
 * host most of the time, which is where a missing gate would be loudest.
 */
it('skips a suite that is not testbench-based, so a host never reports against itself', function () {
    $packages = laravelDataShaped();
    $packages['acme/consumer']['tests']['ThingTest.php'] = "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n";
    unset($packages['acme/consumer']['tests']['TestCase.php']);
    $root = harnessRoot('nontestbench', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
        expect(harnessSummary($root))->not->toContain('acme/consumer');
    } finally {
        surgeon_rrmdir($root);
    }
});

/**
 * A package with NO tests at all cannot hold a harness hole — and equally has nothing to un-mask, which
 * is the worse finding. It is named in the summary rather than skipped silently.
 */
it('names a package that would have been in scope but ships no tests at all', function () {
    $packages = laravelDataShaped();
    unset($packages['acme/consumer']['tests']);
    $root = harnessRoot('nosuite', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
        expect(harnessSummary($root))
            ->toContain('hold no PHP under `tests/`')
            ->toContain('acme/consumer');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('ignores a non-path install, whose harness is not this root\'s to repair', function () {
    $packages = laravelDataShaped();
    $packages['acme/consumer']['source'] = 'dist';
    $root = harnessRoot('resolved', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('accepts any one of a dependency\'s declared providers', function () {
    $packages = laravelDataShaped();
    $packages['vendor/data']['providers'] = ['Vendor\\Data\\LaravelDataServiceProvider', 'Vendor\\Data\\ExtraServiceProvider'];
    $packages['acme/consumer']['tests']['TestCase.php'] = "<?php\n\nuse Orchestra\\Testbench\\TestCase as Orchestra;\n\nclass TestCase extends Orchestra\n{\n    protected function getPackageProviders(\$app): array\n    {\n        return [\\Vendor\\Data\\ExtraServiceProvider::class];\n    }\n}\n";
    $root = harnessRoot('anyone', $packages);

    try {
        expect(harnessWarnings($root))->toBe([]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('says nothing when no installed set is present', function () {
    $root = surgeon_tmp('harness-empty');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/bare']));

    try {
        $findings = (new HarnessProviderAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(HarnessProviderAudit::CHECK.'.no-scope');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is wired into the built-in audit set', function () {
    $classes = array_map(fn ($audit) => $audit::class, (new BuiltInAudits('/irrelevant'))->audits());

    expect($classes)->toContain(HarnessProviderAudit::class);
});
