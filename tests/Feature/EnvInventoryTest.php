<?php

use Illuminate\Support\Facades\Artisan;
use Rushing\Surgeon\Env\DotenvKeys;
use Rushing\Surgeon\Env\EnvInventory;
use Rushing\Surgeon\Env\EnvInventoryOperation;
use Rushing\Surgeon\Env\EnvScanner;

/**
 * `surgeon:env` — the environment inventory. What is worth pinning is the three-way join (read /
 * documented / set), the `config:cache` hazard it surfaces, and the promise that no VALUE from a
 * dotenv file or a call site's default ever reaches the report.
 */

/**
 * A tree with: two documented-and-read vars, one read-but-undocumented, one documented-but-dead,
 * and one `env()` call outside `config/` (the cache hazard).
 */
function env_tree(string $label): string
{
    $root = surgeon_tmp($label);

    surgeon_write($root.'/config/service.php', <<<'PHP'
        <?php

        return [
            'endpoint' => env('SERVICE_ENDPOINT', 'https://secret.example.test'),
            'retries' => env('SERVICE_RETRIES', 3),
            'token' => env('SERVICE_UNDOCUMENTED_TOKEN'),
        ];

        PHP);

    surgeon_write($root.'/src/Service.php', <<<'PHP'
        <?php

        namespace App;

        class Service
        {
            public function run(): void
            {
                // This one is the bug: env() outside config/ goes null under config:cache.
                $key = env('SERVICE_API_KEY');

                $driver = config('service.endpoint');
            }
        }

        PHP);

    surgeon_write($root.'/.env.example', <<<'ENV'
        # A comment, and a blank line follow.

        SERVICE_ENDPOINT=
        SERVICE_RETRIES=3
        SERVICE_API_KEY=
        export SERVICE_LEGACY_MODE=false
        ENV);

    surgeon_write($root.'/.env', <<<'ENV'
        SERVICE_ENDPOINT=https://live.example.test
        SERVICE_API_KEY=sk-live-do-not-leak-me
        ENV);

    surgeon_write($root.'/vendor/acme/lib/Huge.php', "<?php\n\nenv('VENDOR_VAR_SHOULD_NOT_COUNT');\n");

    return $root;
}

function env_inventory(string $root): EnvInventory
{
    return (new EnvInventoryOperation)->run($root);
}

it('lists every env var the tree reads, ignoring vendor', function () {
    $root = env_tree('env-read');

    $read = array_map(fn ($v) => $v->name, env_inventory($root)->read());

    expect($read)->toBe([
        'SERVICE_API_KEY',
        'SERVICE_ENDPOINT',
        'SERVICE_RETRIES',
        'SERVICE_UNDOCUMENTED_TOKEN',
    ]);

    surgeon_rrmdir($root);
});

it('joins reads against .env.example and .env', function () {
    $root = env_tree('env-join');
    $byName = [];
    foreach (env_inventory($root)->variables as $variable) {
        $byName[$variable->name] = $variable;
    }

    // Read, documented, and set locally.
    expect($byName['SERVICE_ENDPOINT']->isRead())->toBeTrue()
        ->and($byName['SERVICE_ENDPOINT']->documented)->toBeTrue()
        ->and($byName['SERVICE_ENDPOINT']->set)->toBeTrue()
        // Documented but nothing reads it — a deletion candidate. `export KEY=` parses too.
        ->and($byName['SERVICE_LEGACY_MODE']->isUnused())->toBeTrue()
        // Read but nowhere documented — a deployer cannot know it exists.
        ->and($byName['SERVICE_UNDOCUMENTED_TOKEN']->isUndocumented())->toBeTrue()
        ->and($byName['SERVICE_UNDOCUMENTED_TOKEN']->set)->toBeFalse();

    surgeon_rrmdir($root);
});

it('flags env() read outside config/ as the config:cache hazard', function () {
    $root = env_tree('env-cache');
    $unsafe = env_inventory($root)->cacheUnsafe();

    expect(array_map(fn ($v) => $v->name, $unsafe))->toBe(['SERVICE_API_KEY'])
        ->and($unsafe[0]->readsOutsideConfig())->toBe(['src/Service.php:10'])
        ->and($unsafe[0]->status())->toBe('cache-unsafe');

    // A read inside config/ is exactly where env() belongs, so it is never flagged.
    surgeon_rrmdir($root);
});

it('does not flag a test reading env() — the hazard does not exist there', function () {
    $root = surgeon_tmp('env-tests');
    surgeon_write($root.'/config/db.php', "<?php\n\nreturn ['host' => env('DB_HOST')];\n");
    surgeon_write($root.'/tests/TenantTestCase.php', "<?php\n\n\$host = env('DB_HOST');\n");

    $inventory = env_inventory($root);

    // Still counted as read — it just is not a config:cache bug. Left in, tests dominate the
    // finding list in every repo that has any.
    expect($inventory->cacheUnsafe())->toBe([])
        ->and($inventory->read())->toHaveCount(1);

    surgeon_rrmdir($root);
});

it('never carries a value out of .env or a call-site default', function () {
    $root = env_tree('env-values');
    $json = json_encode(env_inventory($root)->toArray());

    expect($json)->not->toContain('sk-live-do-not-leak-me')
        ->and($json)->not->toContain('https://live.example.test')
        ->and($json)->not->toContain('https://secret.example.test')
        // …while still knowing the name is set locally.
        ->and($json)->toContain('SERVICE_API_KEY');

    surgeon_rrmdir($root);
});

it('does not invent a row for a var that only exists in the local .env', function () {
    $root = env_tree('env-local-only');
    surgeon_write($root.'/.env', "SERVICE_ENDPOINT=x\nMY_PERSONAL_TUNNEL=y\n");

    $names = array_map(fn ($v) => $v->name, env_inventory($root)->variables);

    // Personal .env junk is nobody's problem; reporting it would bury the real findings.
    expect($names)->not->toContain('MY_PERSONAL_TUNNEL');

    surgeon_rrmdir($root);
});

it('reports an absent .env.example rather than pretending it documents nothing', function () {
    $root = surgeon_tmp('env-no-example');
    surgeon_write($root.'/config/app.php', "<?php\n\nreturn ['x' => env('SOME_VAR')];\n");

    $inventory = env_inventory($root);

    expect($inventory->exampleExists())->toBeFalse()
        ->and($inventory->envExists())->toBeFalse()
        ->and($inventory->undocumented())->toHaveCount(1);

    surgeon_rrmdir($root);
});

it('classifies each dotenv file by the role it plays at boot', function () {
    $root = env_tree('env-roles');
    surgeon_write($root.'/.env.testing', "SERVICE_ENDPOINT=\n");
    surgeon_write($root.'/.env.bak', "JUNK=\n");

    $files = env_inventory($root)->files;

    expect(array_map(fn ($f) => [$f->name, $f->role, $f->environment], $files->files))->toBe([
        ['.env.example', 'documentation', null],
        ['.env', 'base', null],
        ['.env.testing', 'environment', 'testing'],
    ]);

    // Editor residue is not something APP_ENV can select; calling it an environment named "bak"
    // would be a confident lie.
    expect($files->named('.env.bak'))->toBeNull();

    surgeon_rrmdir($root);
});

it('flags a documented var missing from an environment file, because Laravel replaces .env', function () {
    $root = env_tree('env-gap');
    // .env.testing declares only one of the four documented variables.
    surgeon_write($root.'/.env.testing', "SERVICE_ENDPOINT=\n");

    $inventory = env_inventory($root);

    expect($inventory->gapsByFile())->toBe([
        '.env.testing' => ['SERVICE_API_KEY', 'SERVICE_LEGACY_MODE', 'SERVICE_RETRIES'],
    ]);

    $byName = [];
    foreach ($inventory->variables as $variable) {
        $byName[$variable->name] = $variable;
    }

    expect($byName['SERVICE_RETRIES']->missingFrom)->toBe(['.env.testing'])
        ->and($byName['SERVICE_RETRIES']->status())->toBe('environment-gap')
        // Declared in .env.testing, so not a gap there.
        ->and($byName['SERVICE_ENDPOINT']->missingFrom)->toBe([])
        ->and($byName['SERVICE_ENDPOINT']->declaredIn)->toBe(['.env.example', '.env', '.env.testing']);

    surgeon_rrmdir($root);
});

it('does not turn an undocumented var into an environment gap', function () {
    $root = env_tree('env-gap-gate');
    surgeon_write($root.'/.env.testing', "SERVICE_ENDPOINT=\n");

    $byName = [];
    foreach (env_inventory($root)->variables as $variable) {
        $byName[$variable->name] = $variable;
    }

    // Read, never documented, absent from .env.testing — but .env.example never claimed it was
    // required, so the gap is not the repo's own statement being violated. Without this gate every
    // var a minimal .env.testing omits becomes a finding, which is how a check gets ignored.
    expect($byName['SERVICE_UNDOCUMENTED_TOKEN']->missingFrom)->toBe([])
        ->and($byName['SERVICE_UNDOCUMENTED_TOKEN']->status())->toBe('undocumented');

    surgeon_rrmdir($root);
});

it('names the replace-not-merge rule in the human output', function () {
    $root = env_tree('env-gap-render');
    surgeon_write($root.'/.env.testing', "SERVICE_ENDPOINT=\n");

    Artisan::call('surgeon:env', ['--path' => $root, '--matrix' => true]);
    $output = Artisan::output();

    expect($output)->toContain('loaded INSTEAD of .env when APP_ENV=testing')
        ->toContain('missing from .env.testing')
        ->toContain('REPLACES .env, so these are absent at boot, not inherited')
        ->toContain('declaration matrix');

    surgeon_rrmdir($root);
});

it('collects config keys read, and ignores prose and same-named methods', function () {
    $found = EnvScanner::scanSource(<<<'PHP'
        <?php

        class Impostor
        {
            public function env(string $name): string { return $name; }

            public function read(): void
            {
                $this->env('NOT_AN_ENV_VAR');
                $collection->get('not.a.config.key');
                $real = config('app.timeout');
                $typed = Config::string('app.name');

                // config('documented.only') and env('DOCUMENTED_ONLY') are prose, not reads.
            }
        }

        PHP);

    expect($found['env'])->toBe([])
        ->and(array_keys($found['config']))->toBe(['app.name', 'app.timeout']);
});

it('parses dotenv names without retaining values', function () {
    $root = surgeon_tmp('env-dotenv');
    surgeon_write($root.'/.env', "# comment\n\nA=1\nexport B=two\nnot a declaration\nC=\n");

    expect(DotenvKeys::in($root.'/.env'))->toBe(['A', 'B', 'C'])
        ->and(DotenvKeys::in($root.'/.env.missing'))->toBe([])
        ->and(DotenvKeys::exists($root.'/.env.missing'))->toBeFalse();

    surgeon_rrmdir($root);
});

it('exits non-zero under --strict when there are findings, zero when clean', function () {
    $root = env_tree('env-strict');

    expect(Artisan::call('surgeon:env', ['--path' => $root, '--strict' => true]))->toBe(1);

    // A tree whose reads, .env.example and .env all agree passes.
    $clean = surgeon_tmp('env-clean');
    surgeon_write($clean.'/config/app.php', "<?php\n\nreturn ['x' => env('ONLY_VAR')];\n");
    surgeon_write($clean.'/.env.example', "ONLY_VAR=\n");

    expect(Artisan::call('surgeon:env', ['--path' => $clean, '--strict' => true]))->toBe(0);

    surgeon_rrmdir($root);
    surgeon_rrmdir($clean);
});

it('emits the inventory as json', function () {
    $root = env_tree('env-json');

    expect(Artisan::call('surgeon:env', ['--path' => $root, '--json' => true]))->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload['counts'])->toBe([
        'read' => 4,
        'undocumented' => 1,
        'unused' => 1,
        'cache_unsafe' => 1,
        'environment_gaps' => 0,
        'config_keys' => 1,
    ])
        ->and(array_column($payload['files'], 'role', 'name'))
        ->toBe(['.env.example' => 'documentation', '.env' => 'base']);

    surgeon_rrmdir($root);
});

it('names the cache-unsafe reads in the human output', function () {
    $root = env_tree('env-render');

    Artisan::call('surgeon:env', ['--path' => $root]);
    $output = Artisan::output();

    expect($output)->toContain('read outside config/')
        ->toContain('SERVICE_API_KEY')
        ->toContain('src/Service.php:10')
        ->toContain('read but not in .env.example')
        ->toContain('declared but never read');

    surgeon_rrmdir($root);
});
