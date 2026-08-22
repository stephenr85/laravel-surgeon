<?php

use Illuminate\Support\Facades\Artisan;
use Rushing\Surgeon\Env\DotenvKeys;
use Rushing\Surgeon\Env\EnvInventory;
use Rushing\Surgeon\Env\EnvInventoryOperation;
use Rushing\Surgeon\Env\EnvScanner;
use Rushing\Surgeon\Env\ViteEnv;

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
        ->toContain('REPLACES .env for PHP, so these are absent at boot, not inherited')
        ->toContain('declaration matrix');

    surgeon_rrmdir($root);
});

it('reads envPrefix out of the vite config instead of assuming VITE_', function () {
    $root = surgeon_tmp('env-vite-prefix');
    surgeon_write($root.'/vite.config.ts', <<<'TS'
        import { defineConfig } from 'vite';

        export default defineConfig({
            envPrefix: ['VITE_', 'APP_'],
            plugins: [],
        });
        TS);

    $vite = ViteEnv::discover($root);

    expect($vite->present)->toBeTrue()
        ->and($vite->prefixes)->toBe(['VITE_', 'APP_'])
        ->and($vite->prefixSource)->toBe('config')
        // The widened prefix is exactly the case hardcoding VITE_ would misclassify.
        ->and($vite->exposes('APP_NAME'))->toBeTrue()
        ->and($vite->exposes('STRIPE_KEY'))->toBeFalse();

    surgeon_rrmdir($root);
});

it('falls back to the documented default, and says which it used', function () {
    $bare = surgeon_tmp('env-vite-default');
    surgeon_write($bare.'/vite.config.js', "export default { plugins: [] };\n");

    expect(ViteEnv::discover($bare)->prefixes)->toBe(['VITE_'])
        ->and(ViteEnv::discover($bare)->prefixSource)->toBe('default');

    // A single-string envPrefix is the other documented form.
    $single = surgeon_tmp('env-vite-single');
    surgeon_write($single.'/vite.config.ts', "export default { envPrefix: 'PUBLIC_' };\n");

    expect(ViteEnv::discover($single)->prefixes)->toBe(['PUBLIC_']);

    // vite in package.json counts even with no root config.
    $pkg = surgeon_tmp('env-vite-pkg');
    surgeon_write($pkg.'/package.json', '{"devDependencies":{"vite":"^7.0"}}');

    expect(ViteEnv::discover($pkg)->present)->toBeTrue();

    surgeon_rrmdir($bare);
    surgeon_rrmdir($single);
    surgeon_rrmdir($pkg);
});

it('applies no Vite semantics to a repo with no front end', function () {
    $root = env_tree('env-vite-absent');

    $vite = env_inventory($root)->vite;

    // A backend-only package must not inherit a front-end tool's rules.
    expect($vite->present)->toBeFalse()
        ->and($vite->exposes('VITE_ANYTHING'))->toBeFalse();

    surgeon_rrmdir($root);
});

it('classifies one file on both axes, because Laravel and Vite disagree about it', function () {
    $root = env_tree('env-both-axes');
    surgeon_write($root.'/vite.config.ts', "export default { plugins: [] };\n");
    surgeon_write($root.'/.env.local', "SERVICE_ENDPOINT=\n");
    surgeon_write($root.'/.env.production', "SERVICE_ENDPOINT=\n");
    surgeon_write($root.'/.env.production.local', "SERVICE_ENDPOINT=\n");

    $files = env_inventory($root)->files;

    // .env.local is the sharpest case: APP_ENV=local to Laravel, an always-merged override to Vite.
    expect([$files->named('.env.local')->environment, $files->named('.env.local')->viteRole])
        ->toBe(['local', 'always'])
        ->and($files->named('.env.local')->viteLocal)->toBeTrue()
        // .local is peeled off before reading the mode, or the mode would be "production.local".
        ->and([$files->named('.env.production.local')->viteMode, $files->named('.env.production.local')->viteLocal])
        ->toBe(['production', true])
        ->and($files->named('.env')->viteRole)->toBe('always')
        ->and($files->named('.env.example')->viteRole)->toBe('none');

    surgeon_rrmdir($root);
});

it('does not call a vite-only var missing when vite would merge it from .env', function () {
    $root = surgeon_tmp('env-vite-inherit');
    surgeon_write($root.'/vite.config.ts', "export default { plugins: [] };\n");
    surgeon_write($root.'/config/app.php', "<?php\n\nreturn ['k' => env('STRIPE_KEY')];\n");
    surgeon_write($root.'/.env.example', "STRIPE_KEY=\nVITE_APP_NAME=\nVITE_ONLY_HERE=\n");
    surgeon_write($root.'/.env', "STRIPE_KEY=\nVITE_APP_NAME=\n");
    surgeon_write($root.'/.env.production', "STRIPE_KEY=\n");
    surgeon_write($root.'/resources/js/app.ts', "const n = import.meta.env.VITE_APP_NAME;\nconst o = import.meta.env.VITE_ONLY_HERE;\n");

    $byName = [];
    foreach (env_inventory($root)->variables as $variable) {
        $byName[$variable->name] = $variable;
    }

    // VITE_APP_NAME is absent from .env.production but Vite merges it in from .env — not a gap.
    expect($byName['VITE_APP_NAME']->missingFrom)->toBe([])
        ->and($byName['VITE_APP_NAME']->consumers())->toBe(['vite'])
        // …whereas one declared nowhere Vite loads really is missing.
        ->and($byName['VITE_ONLY_HERE']->missingFrom)->toBe(['.env.production'])
        // STRIPE_KEY is read by PHP, so the strict swap semantics apply — and it is present.
        ->and($byName['STRIPE_KEY']->consumers())->toBe(['php']);

    surgeon_rrmdir($root);
});

it('applies the stricter PHP semantics when both readers want the same var', function () {
    $root = surgeon_tmp('env-vite-both');
    surgeon_write($root.'/vite.config.ts', "export default { envPrefix: ['VITE_', 'APP_'] };\n");
    // APP_NAME is exposed to Vite by the widened prefix AND read by PHP config.
    surgeon_write($root.'/config/app.php', "<?php\n\nreturn ['n' => env('APP_NAME')];\n");
    surgeon_write($root.'/resources/js/app.ts', "document.title = import.meta.env.APP_NAME;\n");
    surgeon_write($root.'/.env.example', "APP_NAME=\n");
    surgeon_write($root.'/.env', "APP_NAME=\n");
    surgeon_write($root.'/.env.production', "OTHER=\n");

    $byName = [];
    foreach (env_inventory($root)->variables as $variable) {
        $byName[$variable->name] = $variable;
    }

    // Vite would inherit it from .env; PHP would not, because .env.production replaces .env.
    // The gap is real for PHP whatever Vite does, so it is reported.
    expect($byName['APP_NAME']->consumers())->toBe(['php', 'vite'])
        ->and($byName['APP_NAME']->missingFrom)->toBe(['.env.production']);

    surgeon_rrmdir($root);
});

it('reads the client bundle, so a front-end var is not called dead', function () {
    $root = surgeon_tmp('env-client');
    surgeon_write($root.'/vite.config.ts', "export default { plugins: [] };\n");
    surgeon_write($root.'/.env.example', "VITE_MAP_TOKEN=\nVITE_TRULY_DEAD=\n");
    surgeon_write($root.'/resources/js/Map.vue', <<<'VUE'
        <script setup lang="ts">
        const token = import.meta.env.VITE_MAP_TOKEN;
        const alt = import.meta.env['VITE_MAP_TOKEN'];
        </script>
        VUE);

    $inventory = env_inventory($root);
    $byName = [];
    foreach ($inventory->variables as $variable) {
        $byName[$variable->name] = $variable;
    }

    // Read only from a .vue file — no PHP site at all. Judged on PHP alone it would be reported as
    // a deletion candidate, and the report would be telling you to delete live config.
    expect($byName['VITE_MAP_TOKEN']->isUnused())->toBeFalse()
        ->and($byName['VITE_MAP_TOKEN']->readByPhp())->toBeFalse()
        ->and($byName['VITE_MAP_TOKEN']->consumers())->toBe(['vite'])
        ->and($byName['VITE_MAP_TOKEN']->clientSites)
        ->toBe(['resources/js/Map.vue:2', 'resources/js/Map.vue:3'])
        // …while one nothing reads on either side is still honestly dead.
        ->and($byName['VITE_TRULY_DEAD']->isUnused())->toBeTrue();

    surgeon_rrmdir($root);
});

it('does not walk the front end when the repo has none', function () {
    $root = env_tree('env-client-absent');
    // A .js file exists but there is no Vite, so it is never opened.
    surgeon_write($root.'/public/legacy.js', "const x = import.meta.env.VITE_GHOST;\n");

    $names = array_map(fn ($v) => $v->name, env_inventory($root)->variables);

    expect($names)->not->toContain('VITE_GHOST');

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
