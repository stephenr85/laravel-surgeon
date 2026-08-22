<?php

use Illuminate\Support\Facades\Artisan;
use Rushing\Surgeon\Fingerprint\Fingerprint;
use Rushing\Surgeon\Fingerprint\FingerprintOperation;
use Rushing\Surgeon\Fingerprint\FingerprintRequest;
use Rushing\Surgeon\Fingerprint\SymbolScanner;

/**
 * `surgeon:fingerprint` — the identity read. The properties worth pinning are the ones that make a
 * digest comparable at all: relative paths, fixed ordering, pruned dependency trees, and a symbol scan
 * that reads env var NAMES and never values.
 */

/** Stand up a small tree with two PHP files, a pruned vendor dir, and a config read. */
function fingerprint_tree(string $label): string
{
    $root = surgeon_tmp($label);

    surgeon_write($root.'/src/Service.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Support\Facades\Config;

        class Service
        {
            public function run(): void
            {
                $driver = config('app.driver');
                $key = env('SERVICE_API_KEY');
                $timeout = Config::integer('app.timeout');

                // config('app.documented_only') and env('DOCUMENTED_ONLY') are prose, not reads.
            }
        }

        PHP);

    surgeon_write($root.'/config/service.php', <<<'PHP'
        <?php

        return [
            'endpoint' => env('SERVICE_ENDPOINT', 'https://example.test'),
        ];

        PHP);

    surgeon_write($root.'/README.md', "# fixture\n");
    surgeon_write($root.'/vendor/acme/lib/Huge.php', "<?php\n\nconfig('vendor.should.not.count');\n");

    return $root;
}

function fingerprint_of(string $root, bool $symbols = true): Fingerprint
{
    return (new FingerprintOperation)->run(new FingerprintRequest(root: $root, symbols: $symbols));
}

it('hashes every file under the root and prunes dependency trees', function () {
    $root = fingerprint_tree('fp-files');

    $paths = array_column(fingerprint_of($root)->files, 'path');

    expect($paths)->toBe(['README.md', 'config/service.php', 'src/Service.php']);

    surgeon_rrmdir($root);
});

it('produces the same digest for the same content at a different absolute path', function () {
    $one = fingerprint_tree('fp-path-one');
    $two = fingerprint_tree('fp-path-two');

    // Paths are relative to the root and the file list is sorted, so two checkouts of identical
    // content agree — the property that makes the digest worth comparing across machines at all.
    expect(fingerprint_of($one)->digest)->toBe(fingerprint_of($two)->digest);

    surgeon_rrmdir($one);
    surgeon_rrmdir($two);
});

it('moves the file digest when content changes and leaves the symbols digest alone', function () {
    $root = fingerprint_tree('fp-content');
    $before = fingerprint_of($root);

    surgeon_write($root.'/README.md', "# fixture, edited\n");
    $after = fingerprint_of($root);

    expect($after->filesDigest)->not->toBe($before->filesDigest)
        ->and($after->symbolsDigest)->toBe($before->symbolsDigest)
        ->and($after->digest)->not->toBe($before->digest);

    surgeon_rrmdir($root);
});

it('moves the symbols digest when a new env var is read', function () {
    $root = fingerprint_tree('fp-symbols');
    $before = fingerprint_of($root);

    surgeon_write($root.'/src/Extra.php', "<?php\n\n\$x = env('NEWLY_READ_VAR');\n");
    $after = fingerprint_of($root);

    expect($after->symbolsDigest)->not->toBe($before->symbolsDigest)
        ->and($after->envNames())->toContain('NEWLY_READ_VAR');

    surgeon_rrmdir($root);
});

it('collects config keys and env names, ignoring pruned trees and comments', function () {
    $root = fingerprint_tree('fp-collect');
    $fingerprint = fingerprint_of($root);

    expect($fingerprint->configKeys())->toBe(['app.driver', 'app.timeout'])
        ->and($fingerprint->envNames())->toBe(['SERVICE_API_KEY', 'SERVICE_ENDPOINT'])
        // The commented reads are prose; the vendor tree is pruned before it is ever opened.
        ->and($fingerprint->configKeys())->not->toContain('app.documented_only')
        ->and($fingerprint->configKeys())->not->toContain('vendor.should.not.count')
        ->and($fingerprint->envNames())->not->toContain('DOCUMENTED_ONLY');

    surgeon_rrmdir($root);
});

it('records env sites but never env values', function () {
    $root = fingerprint_tree('fp-values');
    $json = json_encode(fingerprint_of($root)->toArray());

    // The default in config/service.php is a value, and it must not appear anywhere in the report.
    expect($json)->not->toContain('https://example.test')
        ->and(fingerprint_of($root)->env['SERVICE_ENDPOINT'])->toBe(['config/service.php:4']);

    surgeon_rrmdir($root);
});

it('ignores same-named methods that are not the global helpers', function () {
    $found = SymbolScanner::scanSource(<<<'PHP'
        <?php

        class Impostor
        {
            public function env(string $name): string { return $name; }

            public function read(): void
            {
                $this->env('NOT_AN_ENV_VAR');
                Other::config('not.a.config.key');
                $collection->get('not.a.config.key');
            }
        }

        PHP);

    expect($found['env'])->toBe([])
        ->and($found['config'])->toBe([]);
});

it('keeps a files-only run distinguishable from a full one', function () {
    $root = fingerprint_tree('fp-files-only');
    $filesOnly = fingerprint_of($root, symbols: false);
    $full = fingerprint_of($root);

    expect($filesOnly->scannedSymbols())->toBeFalse()
        ->and($filesOnly->symbolsDigest)->toBeNull()
        ->and($filesOnly->filesDigest)->toBe($full->filesDigest)
        ->and($filesOnly->digest)->not->toBe($full->digest);

    surgeon_rrmdir($root);
});

it('exits non-zero when --expect does not match, and zero when it does', function () {
    $root = fingerprint_tree('fp-expect');
    $digest = fingerprint_of($root)->digest;

    $this->artisan('surgeon:fingerprint', ['--path' => $root, '--expect' => $digest])
        ->assertExitCode(0);

    $this->artisan('surgeon:fingerprint', ['--path' => $root, '--expect' => 'deadbeef'])
        ->assertExitCode(1);

    surgeon_rrmdir($root);
});

it('emits the digests and the symbol surface as json', function () {
    $root = fingerprint_tree('fp-json');

    expect(Artisan::call('surgeon:fingerprint', ['--path' => $root, '--json' => true]))->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload['digest'])->toBe(fingerprint_of($root)->digest)
        ->and($payload['counts'])->toBe(['files' => 3, 'config_keys' => 2, 'env_vars' => 2])
        ->and($payload['env'])->toHaveKey('SERVICE_API_KEY');

    surgeon_rrmdir($root);
});

it('rejects an unknown hash algorithm rather than silently falling back', function () {
    $root = fingerprint_tree('fp-algo');

    expect(fn () => (new FingerprintOperation)->run(
        new FingerprintRequest(root: $root, algo: 'not-a-real-algo'),
    ))->toThrow(InvalidArgumentException::class);

    surgeon_rrmdir($root);
});
