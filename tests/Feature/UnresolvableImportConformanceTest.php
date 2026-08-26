<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\UnresolvableImportAudit;

/**
 * api-surface-coherence ticket 87 — a package's `src/` imports a class nothing installed supplies.
 * Every case runs against a real disposable tree with a real `vendor/composer/installed.json` and real
 * files (and real ABSENCES of files), because the whole question is PSR-4 path arithmetic over a
 * filesystem and only a filesystem holds it.
 */

/**
 * Stand up a root with path-installed neighbours.
 *
 * @param  array<string, array{prefix?: string|array<string, string|list<string>>, src?: array<string,string>, classmap?: bool, files?: bool, files_only?: bool}>  $packages
 */
function importRoot(string $label, array $packages, array $rootManifest = [], array $classmapEntries = []): string
{
    $root = surgeon_tmp('imports-'.$label);
    surgeon_write($root.'/composer.json', json_encode($rootManifest + ['name' => 'acme/host-app'], JSON_PRETTY_PRINT));

    $installed = [];
    foreach ($packages as $name => $spec) {
        $psr4 = $spec['prefix'] ?? ['Acme\\'.str_replace('-', '', ucfirst(basename($name))).'\\' => 'src/'];
        $psr4 = is_string($psr4) ? [$psr4 => 'src/'] : $psr4;

        $autoload = [];
        if (! ($spec['files_only'] ?? false)) {
            $autoload['psr-4'] = $psr4;
        }
        if ($spec['classmap'] ?? false) {
            $autoload['classmap'] = ['src/'];
        }
        if ($spec['files'] ?? false) {
            $autoload['files'] = ['src/helpers.php'];
        }

        $installed[] = [
            'name' => $name,
            'install-path' => '../'.$name,
            'dist' => ['type' => 'path'],
            'autoload' => $autoload,
        ];
        surgeon_write($root.'/vendor/'.$name.'/composer.json', json_encode(['name' => $name, 'autoload' => $autoload], JSON_PRETTY_PRINT));

        foreach (($spec['src'] ?? []) as $file => $contents) {
            surgeon_write($root.'/vendor/'.$name.'/src/'.$file, $contents);
        }
    }

    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_PRETTY_PRINT));

    if ($classmapEntries !== []) {
        $lines = '';
        foreach ($classmapEntries as $class) {
            $lines .= "    '".str_replace('\\', '\\\\', $class)."' => \$vendorDir . '/x.php',\n";
        }
        surgeon_write($root.'/vendor/composer/autoload_classmap.php', "<?php\n\nreturn array(\n".$lines.");\n");
    }

    return $root;
}

/** @return list<string> the detail of every non-Pass finding, in report order */
function importFindings(string $root, DoctorStatus $status): array
{
    return array_values(array_map(
        fn ($f) => $f->finding->detail,
        array_filter(
            (new UnresolvableImportAudit($root))->suggestOperations(),
            fn ($f) => $f->finding->status === $status,
        ),
    ));
}

function importSummary(string $root): string
{
    $findings = (new UnresolvableImportAudit($root))->suggestOperations();

    return end($findings)->finding->detail;
}

/**
 * The motivating instance, reduced: a model that `use`s a trait from a namespace whose PSR-4 prefix an
 * installed package DOES own, where the package ships no such file.
 */
it('fails an import used in a class-load position that no installed file supplies', function () {
    $root = importRoot('trait', [
        'acme/engine' => ['prefix' => ['Acme\\Engine\\' => 'src/'], 'src' => ['Model.php' => '<?php namespace Acme\Engine; class Model {}']],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Acme\Engine\Concerns\HasStatus;
use Acme\Engine\Model;

class Thing extends Model
{
    use HasStatus;
}
PHP]],
    ]);

    $fails = importFindings($root, DoctorStatus::Fail);

    expect($fails)->toHaveCount(1)
        ->and($fails[0])->toContain('Acme\Engine\Concerns\HasStatus')
        ->and($fails[0])->toContain('CLASS-LOAD POSITION')
        ->and($fails[0])->toContain('acme/engine IS installed')
        // The half a prefix-ownership read calls satisfied.
        ->and($fails[0])->toContain('reports this case as SATISFIED');
});

/** The false positive this audit exists to avoid: `use` alone never autoloads. */
it('does not fail an absent import that is only referenced as a class-string', function () {
    $root = importRoot('classstring', [
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Generator.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Vendor\Sdk\Connector;

class Generator
{
    public function emit(): string
    {
        return Connector::class;
    }
}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty();

    $warnings = importFindings($root, DoctorStatus::Warn);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('DEFERRED fatal')
        ->and($warnings[0])->toContain('Vendor\Sdk\Connector');
});

/**
 * PSR-4 falls through: a longer prefix that does not hold the file must not stop the search. This is
 * the `laravel/framework` shape — `Illuminate\Support\` mapped to four subtrees that do not contain
 * `ServiceProvider.php`, `Illuminate\` mapped to the tree that does.
 */
it('falls through from a longer prefix to a shorter one that supplies the file', function () {
    $root = importRoot('fallthrough', [
        'acme/framework' => [
            'prefix' => ['Acme\\' => 'src/Acme/', 'Acme\\Support\\' => ['src/Acme/Collections/', 'src/Acme/Macroable/']],
            'src' => [
                'Acme/Support/ServiceProvider.php' => '<?php namespace Acme\Support; class ServiceProvider {}',
                'Acme/Collections/Collection.php' => '<?php namespace Acme\Support; class Collection {}',
            ],
        ],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Provider.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Acme\Support\Collection;
use Acme\Support\ServiceProvider;

class Provider extends ServiceProvider
{
    public function items(): Collection
    {
        return new Collection;
    }
}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty()
        ->and(importFindings($root, DoctorStatus::Warn))->toBeEmpty();
});

/** Composer's generated classmap is the supply for a classmap-autoloaded package (phpunit's shape). */
it('treats a class named in composer\'s generated classmap as supplied', function () {
    $root = importRoot('classmap', [
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Conformance.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Harness\Framework\TestCase;

class Conformance extends TestCase {}
PHP]],
    ], classmapEntries: ['Harness\\Framework\\TestCase']);

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty();
});

/**
 * A `files` autoload includes a bootstrap file and resolves no class by name, so it must NOT make an
 * owner opaque. Counting it silenced this audit's own motivating instance.
 */
it('does not let a files autoload suppress an absent class in the same package', function () {
    $root = importRoot('filesautoload', [
        'acme/engine' => ['prefix' => ['Acme\\Engine\\' => 'src/'], 'files' => true, 'src' => ['helpers.php' => '<?php']],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Acme\Engine\Concerns\HasStatus;

class Thing
{
    use HasStatus;
}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toHaveCount(1);
});

/** A `classmap` autoload, by contrast, defeats path arithmetic and must suppress. */
it('lets a classmap autoload make its owner opaque', function () {
    $root = importRoot('opaque', [
        'acme/engine' => ['prefix' => ['Acme\\Engine\\' => 'src/'], 'classmap' => true],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Acme\Engine\Concerns\HasStatus;

class Thing
{
    use HasStatus;
}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty();
});

/**
 * `laravel/pint` ships a Laravel application and declares `App\`, `Database\Seeders\`,
 * `Database\Factories\`. Left in the index it becomes the apparent owner of every `App\Models\*`
 * reference in the estate and manufactures a Fail attributed to a code formatter.
 */
it('drops application-skeleton prefixes declared by an installed package', function () {
    $root = importRoot('skeleton', [
        'acme/formatter' => ['prefix' => ['App\\' => 'app/', 'Database\\Seeders\\' => 'database/seeders/']],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use App\Models\Widget;

class Thing extends Widget {}
PHP]],
    ]);

    $fails = importFindings($root, DoctorStatus::Fail);

    expect($fails)->toHaveCount(1)
        // Unowned, not "acme/formatter owns it and the file is missing".
        ->and($fails[0])->toContain('No installed package declares a PSR-4 prefix')
        ->and($fails[0])->not->toContain('acme/formatter');
});

/** An unqualified name falls back to the global namespace, so it is never resolved. */
it('ignores names that no top-level use statement binds', function () {
    $root = importRoot('unqualified', [
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Boom.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

class Boom extends Exception {}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty()
        ->and(importFindings($root, DoctorStatus::Warn))->toBeEmpty();
});

/** A closure's `use (...)` is not an import and a function import is not a class. */
it('ignores closure use clauses and function imports', function () {
    $root = importRoot('nonclass', [
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Closures.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use function Vendor\Missing\helper;

class Closures
{
    public function run(int $n): callable
    {
        return function () use ($n) {
            return helper($n);
        };
    }
}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty()
        ->and(importFindings($root, DoctorStatus::Warn))->toBeEmpty();
});

/** A grouped import expands, and each member resolves on its own. */
it('expands a grouped use statement', function () {
    $root = importRoot('grouped', [
        'acme/engine' => ['prefix' => ['Acme\\Engine\\' => 'src/'], 'src' => ['Here.php' => '<?php namespace Acme\Engine; class Here {}']],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Acme\Engine\{Here, Gone};

class Thing extends Gone
{
    public ?Here $here = null;
}
PHP]],
    ]);

    $fails = importFindings($root, DoctorStatus::Fail);

    expect($fails)->toHaveCount(1)->and($fails[0])->toContain('Acme\Engine\Gone');
});

/** An aliased import is keyed by its alias, which is what the class-load position names. */
it('keys an aliased import by its alias', function () {
    $root = importRoot('aliased', [
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Vendor\Gone\Concern as GoneConcern;

class Thing
{
    use GoneConcern;
}
PHP]],
    ]);

    $fails = importFindings($root, DoctorStatus::Fail);

    expect($fails)->toHaveCount(1)->and($fails[0])->toContain('Vendor\Gone\Concern');
});

/**
 * A neighbour carrying its own installed set answers for itself. Resolved against the running root
 * instead, every lean root becomes a noise generator about its path-linked neighbours.
 */
it('resolves a neighbour against its own installed set when it has one', function () {
    $root = importRoot('ownvendor', [
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => <<<'PHP'
<?php

namespace Acme\Satellite;

use Vendor\Sdk\Base;

class Thing extends Base {}
PHP]],
    ]);

    expect(importFindings($root, DoctorStatus::Fail))->toHaveCount(1);

    // Give the neighbour its own vendor, supplying the class the running root never heard of.
    $neighbour = $root.'/vendor/acme/satellite';
    surgeon_write($neighbour.'/vendor/vendor/sdk/composer.json', json_encode(['name' => 'vendor/sdk', 'autoload' => ['psr-4' => ['Vendor\\Sdk\\' => 'src/']]]));
    surgeon_write($neighbour.'/vendor/vendor/sdk/src/Base.php', '<?php namespace Vendor\Sdk; class Base {}');
    surgeon_write($neighbour.'/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'vendor/sdk',
        'install-path' => '../vendor/sdk',
        'autoload' => ['psr-4' => ['Vendor\\Sdk\\' => 'src/']],
    ]]]));

    expect(importFindings($root, DoctorStatus::Fail))->toBeEmpty();
});

it('passes cleanly and says what it did not check', function () {
    $root = importRoot('clean', [
        'acme/engine' => ['prefix' => ['Acme\\Engine\\' => 'src/'], 'src' => ['Model.php' => '<?php namespace Acme\Engine; class Model {}']],
        'acme/satellite' => ['prefix' => ['Acme\\Satellite\\' => 'src/'], 'src' => ['Thing.php' => "<?php\n\nnamespace Acme\Satellite;\n\nuse Acme\Engine\Model;\n\nclass Thing extends Model {}\n"]],
    ]);

    expect(importSummary($root))
        ->toContain('resolves to a file on disk')
        ->toContain(UnresolvableImportAudit::SCOPE_NOTE);
});

it('says so rather than guessing when nothing is installed', function () {
    $root = surgeon_tmp('imports-empty');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/bare']));

    $findings = (new UnresolvableImportAudit($root))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe(UnresolvableImportAudit::CHECK.'.no-scope');
});

it('ships in the built-in audit set', function () {
    $audits = (new BuiltInAudits(sys_get_temp_dir()))->audits();

    expect(array_filter($audits, fn ($a) => $a instanceof UnresolvableImportAudit))->not->toBeEmpty();
});
