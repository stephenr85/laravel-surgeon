<?php

use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Docblock\DocblockDerefOperation;
use Rushing\Surgeon\Docblock\DocblockTierAudit;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * Stand up a two-package tree: a lower-tier `acme/beam` (`Acme\Beam\ => src/`) that depends on nothing,
 * and a higher-tier `acme/app` (`App\ => app/`) that requires beam. A beam file carries a docblock
 * a docblock `@see` to an app-tier provider — an UPWARD reference (beam does not reach app).
 * Returns [root, beamFile].
 *
 * @return array{0:string, 1:string}
 */
function docblock_fixture(string $see): array
{
    $root = surgeon_tmp('docblock');

    surgeon_write($root.'/beam/composer.json', json_encode([
        'name' => 'acme/beam',
        'autoload' => ['psr-4' => ['Acme\\Beam\\' => 'src/']],
    ], JSON_PRETTY_PRINT));

    surgeon_write($root.'/app/composer.json', json_encode([
        'name' => 'acme/app',
        'require' => ['acme/beam' => '*'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT));

    $beamFile = $root.'/beam/src/Particle.php';
    surgeon_write($beamFile, <<<PHP
        <?php

        namespace Acme\\Beam;

        /**
         * A beam particle. See {$see} for the app-tier binding.
         */
        class Particle
        {
        }

        PHP);

    return [$root, $beamFile];
}

it('rewrites an upward {@see \\Fqn} to the exact backtick short-name form', function () {
    [$root, $beamFile] = docblock_fixture('{@see \\App\\Providers\\ParticleServiceProvider}');
    $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);

    $findings = (new DocblockTierAudit([$beamFile], 'acme/beam', $graph))->suggestOperations();

    // The audit must place App\* as higher-tier and nominate the deref op with the exact splice payload.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->isFixable())->toBeTrue()
        ->and($findings[0]->suggestion->kind)->toBe('docblock-deref')
        ->and($findings[0]->suggestion->payload['old'])->toBe('{@see \\App\\Providers\\ParticleServiceProvider}')
        ->and($findings[0]->suggestion->payload['new'])->toBe('`ParticleServiceProvider`');

    // Apply the deterministic splice and assert the exact output.
    $plan = (new DocblockDerefOperation)->plan([$findings[0]->suggestion->payload]);
    (new DocblockDerefOperation)->apply($plan, new SpliceApplier);

    $expected = <<<'PHP'
        <?php

        namespace Acme\Beam;

        /**
         * A beam particle. See `ParticleServiceProvider` for the app-tier binding.
         */
        class Particle
        {
        }

        PHP;

    expect(file_get_contents($beamFile))->toBe($expected);

    surgeon_rrmdir($root);
});

it('also derefs the bare @see \\Fqn annotation form', function () {
    [$root, $beamFile] = docblock_fixture('@see \\App\\Providers\\ParticleServiceProvider');
    $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);

    $findings = (new DocblockTierAudit([$beamFile], 'acme/beam', $graph))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->suggestion->payload['old'])->toBe('@see \\App\\Providers\\ParticleServiceProvider')
        ->and($findings[0]->suggestion->payload['new'])->toBe('`ParticleServiceProvider`');

    surgeon_rrmdir($root);
});

it('does not flag a downward @see (owner already reaches the target package)', function () {
    // app references beam — a legal downward @see. beam does NOT require app back.
    $root = surgeon_tmp('docblock-down');
    surgeon_write($root.'/beam/composer.json', json_encode([
        'name' => 'acme/beam', 'autoload' => ['psr-4' => ['Acme\\Beam\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($root.'/app/composer.json', json_encode([
        'name' => 'acme/app', 'require' => ['acme/beam' => '*'], 'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT));
    $appFile = $root.'/app/app/Consumer.php';
    surgeon_write($appFile, <<<'PHP'
        <?php

        namespace App;

        /** Consumes {@see \Acme\Beam\Particle}. */
        class Consumer
        {
        }

        PHP);

    $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);
    $findings = (new DocblockTierAudit([$appFile], 'acme/app', $graph))->suggestOperations();

    expect($findings)->toBe([]);

    surgeon_rrmdir($root);
});

it('produces a form Pint\'s fully_qualified_strict_types provably will NOT import (real pint run)', function () {
    $pint = dirname(__DIR__, 2).'/vendor/bin/pint';
    if (! is_file($pint)) {
        $this->markTestSkipped('pint binary not present');
    }

    [$root, $beamFile] = docblock_fixture('{@see \\App\\Providers\\ParticleServiceProvider}');
    $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);
    $findings = (new DocblockTierAudit([$beamFile], 'acme/beam', $graph))->suggestOperations();
    $plan = (new DocblockDerefOperation)->plan([$findings[0]->suggestion->payload]);
    (new DocblockDerefOperation)->apply($plan, new SpliceApplier);

    // Aggressive config: the exact fixer set that forges the import in the real footgun.
    $config = $root.'/pint.json';
    surgeon_write($config, json_encode([
        'preset' => 'laravel',
        'rules' => [
            'fully_qualified_strict_types' => ['import_symbols' => true],
            'global_namespace_import' => ['import_classes' => true],
        ],
    ]));

    exec(escapeshellarg($pint).' '.escapeshellarg($beamFile).' --config='.escapeshellarg($config).' 2>&1', $out, $code);

    $after = (string) file_get_contents($beamFile);
    // The load-bearing assertion: no upward use import was forged.
    expect($after)->not->toContain('use App\\')
        ->and($after)->toContain('`ParticleServiceProvider`');

    surgeon_rrmdir($root);
});
