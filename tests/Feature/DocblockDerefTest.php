<?php

use Rushing\Surgeon\Docblock\DocblockDerefOperation;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * The generic deref MECHANISM (`DocblockDerefOperation`) — the byte-splice writer that de-forges an
 * upward-import footgun. It stays foundation-tier in surgeon; the POLICY that DECIDES which references
 * are upward (the tier-audit) moved DOWN to beam, so this test exercises the operation directly over a
 * hand-built splice payload — no tier-audit involved.
 *
 * Stand up a lower-tier `acme/beam` file whose docblock carries an importable `@see` to an app-tier FQN,
 * then apply the deref and assert the exact output. Returns [root, beamFile].
 *
 * @return array{0:string, 1:string}
 */
function docblock_fixture(string $see): array
{
    $root = surgeon_tmp('docblock');

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

/**
 * The splice payload the (beam-resident) tier-audit would emit for one upward `@see` reference — built
 * here by hand so the surgeon test exercises only the operation, never the moved audit.
 */
function docblock_payload(string $file, string $old, string $new): array
{
    $code = (string) file_get_contents($file);
    $start = strpos($code, $old);
    $end = $start + strlen($old) - 1;

    return [
        'file' => $file,
        'relativePath' => basename($file),
        'line' => substr_count($code, "\n", 0, $start) + 1,
        'span' => [$start, $end],
        'old' => $old,
        'new' => $new,
    ];
}

it('derefText renders the class short-name in the exact backtick form', function () {
    expect(DocblockDerefOperation::derefText('App\\Providers\\ParticleServiceProvider'))
        ->toBe('`ParticleServiceProvider`');
});

it('rewrites an upward {@see \\Fqn} to the exact backtick short-name form', function () {
    [$root, $beamFile] = docblock_fixture('{@see \\App\\Providers\\ParticleServiceProvider}');

    $payload = docblock_payload(
        $beamFile,
        '{@see \\App\\Providers\\ParticleServiceProvider}',
        '`ParticleServiceProvider`',
    );

    $plan = (new DocblockDerefOperation)->plan([$payload]);
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

    $payload = docblock_payload(
        $beamFile,
        '@see \\App\\Providers\\ParticleServiceProvider',
        '`ParticleServiceProvider`',
    );

    $plan = (new DocblockDerefOperation)->plan([$payload]);
    (new DocblockDerefOperation)->apply($plan, new SpliceApplier);

    expect(file_get_contents($beamFile))->toContain('See `ParticleServiceProvider` for');

    surgeon_rrmdir($root);
});

it('produces a form Pint\'s fully_qualified_strict_types provably will NOT import (real pint run)', function () {
    $pint = dirname(__DIR__, 2).'/vendor/bin/pint';
    if (! is_file($pint)) {
        $this->markTestSkipped('pint binary not present');
    }

    [$root, $beamFile] = docblock_fixture('{@see \\App\\Providers\\ParticleServiceProvider}');

    $payload = docblock_payload(
        $beamFile,
        '{@see \\App\\Providers\\ParticleServiceProvider}',
        '`ParticleServiceProvider`',
    );
    $plan = (new DocblockDerefOperation)->plan([$payload]);
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
