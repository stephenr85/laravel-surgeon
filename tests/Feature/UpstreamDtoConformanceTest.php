<?php

use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\StaleDownstreamDuplicateAudit;
use Rushing\Surgeon\Conformance\UpstreamDtoAudit;
use Rushing\Surgeon\Operation\ConformanceSweep;
use Rushing\Surgeon\Operation\NullConformanceManifest;

/**
 * Ticket 15 — the two surgeon-native upstream-DTO conformance audits (b1 promotion-candidate, b2
 * stale-downstream-duplicate), proven against synthetic temp PSR-4 roots + a fake installed.json, the way
 * CrossRepoMoveTest fabricates its two-repo estate. No splicewire/* fixtures — the downstream twin is DATA.
 */

/**
 * Stand up a fake HOST estate: an app root with an `App\Data\*` DTO dir, a `vendor/composer/installed.json`
 * describing ONE downstream package, and that package's checkout under `vendor/`. The caller drops the app
 * DTO(s) + the downstream class(es) it wants to exercise.
 *
 * @param  array<string, string>  $appData  relative-under-app-Data path => file contents
 * @param  array<string, array{psr4: string, dir: string, files: array<string,string>}>  $packages
 *                                                                                                  package name => { psr4 prefix (trailing `\`), src dir, relative-under-src path => contents }
 * @return array{root: string}
 */
function surgeon_host(string $label, array $appData, array $packages): array
{
    $root = surgeon_tmp('conformance-'.$label);

    surgeon_write($root.'/composer.json', json_encode([
        'name' => 'acme/host-app',
        'require' => array_fill_keys(array_keys($packages), '*'),
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT));

    foreach ($appData as $rel => $contents) {
        surgeon_write($root.'/app/Data/'.$rel, $contents);
    }

    $installed = [];
    foreach ($packages as $name => $spec) {
        $installPath = 'vendor/'.$name;
        $installed[] = [
            'name' => $name,
            'install-path' => '../../'.$installPath, // relative to vendor/composer/
            'autoload' => ['psr-4' => [$spec['psr4'] => $spec['dir']]],
        ];
        foreach ($spec['files'] as $rel => $contents) {
            surgeon_write($root.'/'.$installPath.'/'.trim($spec['dir'], '/').'/'.$rel, $contents);
        }
        // A minimal composer.json for the package so PackageGraph can read its name for cycle-risk.
        surgeon_write($root.'/'.$installPath.'/composer.json', json_encode([
            'name' => $name,
            'autoload' => ['psr-4' => [$spec['psr4'] => $spec['dir']]],
        ], JSON_PRETTY_PRINT));
    }
    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_PRETTY_PRINT));

    return ['root' => $root];
}

// ── b1 — UpstreamDtoAudit (promotion candidate) ─────────────────────────────────────────────

it('b1: flags an app DTO whose imports point cleanly DOWN into one package as a promotion candidate', function () {
    $host = surgeon_host('b1-promote', [
        'WidgetData.php' => <<<'PHP'
            <?php

            namespace App\Data;

            use Spatie\LaravelData\Data;
            use Vendor\Pkg\Models\Widget;

            class WidgetData extends Data
            {
                public function __construct(public string $name, public Widget $widget) {}
            }

            PHP,
    ], [
        'vendor/pkg' => [
            'psr4' => 'Vendor\\Pkg\\',
            'dir' => 'src',
            'files' => ['Models/Widget.php' => "<?php\n\nnamespace Vendor\\Pkg\\Models;\n\nclass Widget {}\n"],
        ],
    ]);

    try {
        $findings = (new UpstreamDtoAudit($host['root']))->suggestOperations();
        $candidates = array_values(array_filter($findings, fn ($f) => $f->finding->check === 'upstream-dto.promotion-candidate'));

        expect($candidates)->toHaveCount(1)
            ->and($candidates[0]->isFixable())->toBeTrue()
            ->and($candidates[0]->suggestion->kind)->toBe('relocation')
            ->and($candidates[0]->suggestion->payload['old'])->toBe('App\\Data\\WidgetData')
            ->and($candidates[0]->suggestion->payload['new'])->toBe('Vendor\\Pkg\\Data\\WidgetData')
            ->and($candidates[0]->suggestion->payload['destination_package'])->toBe('vendor/pkg')
            ->and($candidates[0]->suggestion->payload['cross_repo'])->toBeTrue();
    } finally {
        surgeon_rrmdir($host['root']);
    }
});

it('b1: does NOT flag an app DTO that imports another app-specific class', function () {
    $host = surgeon_host('b1-appcoupled', [
        'OrderData.php' => <<<'PHP'
            <?php

            namespace App\Data;

            use Spatie\LaravelData\Data;
            use Vendor\Pkg\Models\Widget;
            use App\Models\Order;

            class OrderData extends Data
            {
                public function __construct(public Widget $widget, public Order $order) {}
            }

            PHP,
    ], [
        'vendor/pkg' => [
            'psr4' => 'Vendor\\Pkg\\',
            'dir' => 'src',
            'files' => ['Models/Widget.php' => "<?php\n\nnamespace Vendor\\Pkg\\Models;\n\nclass Widget {}\n"],
        ],
    ]);

    try {
        $findings = (new UpstreamDtoAudit($host['root']))->suggestOperations();

        // The app-coupled DTO is not a candidate; the run aggregates to a clean Pass instead.
        expect(array_filter($findings, fn ($f) => $f->finding->check === 'upstream-dto.promotion-candidate'))->toBe([])
            ->and($findings[0]->finding->check)->toBe('upstream-dto.none');
    } finally {
        surgeon_rrmdir($host['root']);
    }
});

// ── b2 — StaleDownstreamDuplicateAudit ──────────────────────────────────────────────────────

it('b2: flags an app class with an IDENTICAL downstream twin as fixable (retire + repoint)', function () {
    $body = fn (bool $withComposable, bool $withTypeScript) => sprintf(
        "<?php\n\nnamespace %%s;\n\n%suse Spatie\\LaravelData\\Data;\n\n%sclass EntryData extends Data\n{\n    public function __construct(public string \$slug, public array \$body%s) {}\n}\n",
        $withTypeScript ? "use Spatie\\TypeScriptTransformer\\Attributes\\TypeScript;\n" : '',
        $withTypeScript ? "#[TypeScript]\n" : '',
        $withComposable ? ', public bool $composable = true' : '',
    );

    $host = surgeon_host('b2-identical', [
        'EntryData.php' => str_replace('%s', 'App\\Data', $body(false, false)),
    ], [
        'vendor/beam' => [
            'psr4' => 'Vendor\\Beam\\',
            'dir' => 'src',
            'files' => ['Data/EntryData.php' => str_replace('%s', 'Vendor\\Beam\\Data', $body(false, false))],
        ],
    ]);

    try {
        $findings = (new StaleDownstreamDuplicateAudit($host['root']))->suggestOperations();
        $twins = array_values(array_filter($findings, fn ($f) => $f->finding->check === 'stale-duplicate.identical'));

        expect($twins)->toHaveCount(1)
            ->and($twins[0]->isFixable())->toBeTrue()
            ->and($twins[0]->suggestion->kind)->toBe('relocation')
            ->and($twins[0]->suggestion->payload['old'])->toBe('App\\Data\\EntryData')
            ->and($twins[0]->suggestion->payload['new'])->toBe('Vendor\\Beam\\Data\\EntryData')
            ->and($twins[0]->suggestion->payload['retire_duplicate'])->toBeTrue();
    } finally {
        surgeon_rrmdir($host['root']);
    }
});

it('b2: flags a DRIFTED twin as advisory-only and NAMES the drift (adds $composable, drops #[TypeScript])', function () {
    // The real BeamUxEntryBodyData shape: app has #[TypeScript] + 2 params; downstream adds $composable
    // and drops #[TypeScript]. Reconciliation is judgment → advisory only, never a blind retire.
    $appClass = "<?php\n\nnamespace App\\Data;\n\nuse Spatie\\LaravelData\\Data;\nuse Spatie\\TypeScriptTransformer\\Attributes\\TypeScript;\n\n#[TypeScript]\nclass EntryData extends Data\n{\n    public function __construct(public string \$slug, public array \$body) {}\n}\n";
    $twinClass = "<?php\n\nnamespace Vendor\\Beam\\Data;\n\nuse Spatie\\LaravelData\\Data;\n\nclass EntryData extends Data\n{\n    public function __construct(public string \$slug, public array \$body, public bool \$composable = true) {}\n}\n";

    $host = surgeon_host('b2-drift', [
        'EntryData.php' => $appClass,
    ], [
        'vendor/beam' => [
            'psr4' => 'Vendor\\Beam\\',
            'dir' => 'src',
            'files' => ['Data/EntryData.php' => $twinClass],
        ],
    ]);

    try {
        $findings = (new StaleDownstreamDuplicateAudit($host['root']))->suggestOperations();
        $drifted = array_values(array_filter($findings, fn ($f) => $f->finding->check === 'stale-duplicate.drifted'));

        expect($drifted)->toHaveCount(1)
            ->and($drifted[0]->isAdvisory())->toBeTrue()
            ->and($drifted[0]->isFixable())->toBeFalse()
            ->and($drifted[0]->finding->detail)->toContain('$composable')
            ->and($drifted[0]->finding->detail)->toContain('#[TypeScript]')
            ->and($drifted[0]->suggestion->owningPackage)->toBe('vendor/beam');
    } finally {
        surgeon_rrmdir($host['root']);
    }
});

it('b2: does NOT flag an app DTO with no downstream twin', function () {
    $host = surgeon_host('b2-notwin', [
        'LonelyData.php' => "<?php\n\nnamespace App\\Data;\n\nuse Spatie\\LaravelData\\Data;\n\nclass LonelyData extends Data\n{\n    public function __construct(public string \$x) {}\n}\n",
    ], [
        'vendor/beam' => [
            'psr4' => 'Vendor\\Beam\\',
            'dir' => 'src',
            'files' => ['Data/OtherData.php' => "<?php\n\nnamespace Vendor\\Beam\\Data;\n\nclass OtherData {}\n"],
        ],
    ]);

    try {
        $findings = (new StaleDownstreamDuplicateAudit($host['root']))->suggestOperations();

        expect(array_filter($findings, fn ($f) => str_starts_with($f->finding->check, 'stale-duplicate.') && $f->finding->check !== 'stale-duplicate.none'))->toBe([])
            ->and($findings[0]->finding->check)->toBe('stale-duplicate.none');
    } finally {
        surgeon_rrmdir($host['root']);
    }
});

// ── Wiring — the built-in set flows through the ConformanceSweep alongside a host manifest ───

it('surfaces surgeon built-in audits through the ConformanceSweep alongside the host manifest', function () {
    $host = surgeon_host('sweep', [
        'WidgetData.php' => <<<'PHP'
            <?php

            namespace App\Data;

            use Spatie\LaravelData\Data;
            use Vendor\Pkg\Models\Widget;

            class WidgetData extends Data
            {
                public function __construct(public Widget $widget) {}
            }

            PHP,
    ], [
        'vendor/pkg' => [
            'psr4' => 'Vendor\\Pkg\\',
            'dir' => 'src',
            'files' => ['Models/Widget.php' => "<?php\n\nnamespace Vendor\\Pkg\\Models;\n\nclass Widget {}\n"],
        ],
    ]);

    try {
        $builtIn = (new BuiltInAudits($host['root']))->audits();
        $report = (new ConformanceSweep(fn (string $a) => new $a))->run(new NullConformanceManifest, $builtIn);

        // Two built-in audits reported even with an empty host manifest, and the b1 candidate is fixable.
        expect($report->auditCount())->toBe(2)
            ->and($report->results[0]->audit)->toBe(UpstreamDtoAudit::class)
            ->and($report->results[1]->audit)->toBe(StaleDownstreamDuplicateAudit::class)
            ->and($report->fixableCount())->toBeGreaterThanOrEqual(1);
    } finally {
        surgeon_rrmdir($host['root']);
    }
});
