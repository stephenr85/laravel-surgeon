<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\ConfigClassStringAudit;

/**
 * Particle-doctrine-followups #04 — a class-string in a config file that does not resolve. All
 * three readers are injected (file list, source, existence predicate) — no filesystem and no class
 * that must not exist — the same posture as LocalMcpWiringAudit's injected readers; plus one
 * end-to-end case over a disposable temp tree with a real config file.
 */
function configAudit(array $sources, ?callable $resolves = null, ?callable $isNamespace = null): ConfigClassStringAudit
{
    return new ConfigClassStringAudit(
        appRoot: '/host',
        configFiles: fn () => array_keys($sources),
        readSource: fn (string $file) => $sources[$file] ?? false,
        resolves: $resolves ?? fn (string $fqn) => true,
        // Default to "nothing is a namespace" so every pre-existing case keeps measuring what it measured.
        isNamespace: $isNamespace ?? fn (string $fqn) => false,
    );
}

it('fails on an unresolvable ::class fed through a stale use import, citing file and line', function () {
    // The historical defect shape: a stale FQN in the config file's import, feeding a config value
    // a provider later resolves as a binding — two hops from any binding call site.
    $findings = configAudit(
        [
            '/host/config/beam.php' => <<<'PHP'
                <?php

                use App\Old\StaleSource;

                return [
                    'client' => [
                        'sources' => [
                            'defaults' => StaleSource::class,
                        ],
                    ],
                ];
                PHP,
        ],
        resolves: fn (string $fqn) => $fqn !== 'App\\Old\\StaleSource',
    )->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->status)->toBe(DoctorStatus::Fail)
        ->and($findings[0]->finding->check)->toBe(ConfigClassStringAudit::CHECK)
        ->and($findings[0]->finding->detail)->toContain('config/beam.php:8')
        ->and($findings[0]->finding->detail)->toContain('App\\Old\\StaleSource');
});

it('pairs the failure with an advisory suggestion naming the rename tool, never a fix', function () {
    $findings = configAudit(
        ['/host/config/beam.php' => "<?php\n\nreturn ['source' => \\App\\Gone\\Thing::class];\n"],
        resolves: fn () => false,
    )->suggestOperations();

    expect($findings[0]->isAdvisory())->toBeTrue()
        ->and($findings[0]->isFixable())->toBeFalse()
        ->and($findings[0]->suggestion->owningPackage)->toBe('rushing/laravel-surgeon')
        ->and($findings[0]->suggestion->summary)->toContain('surgeon:trace')
        ->and($findings[0]->suggestion->summary)->toContain('surgeon:move');
});

it('flags an FQN-shaped string literal too, and passes cleanly when everything resolves', function () {
    $sources = [
        '/host/config/bindings.php' => <<<'PHP'
            <?php

            return [
                'model' => 'App\Models\Missing',
                'label' => 'plain words never harvested',
                'path' => 'not/a/class/string',
            ];
            PHP,
    ];

    $failing = configAudit($sources, resolves: fn () => false)->suggestOperations();
    expect($failing)->toHaveCount(1)
        ->and($failing[0]->finding->detail)->toContain('App\\Models\\Missing')
        ->and($failing[0]->finding->detail)->toContain('string literal');

    $clean = configAudit($sources, resolves: fn () => true)->suggestOperations();
    expect($clean)->toHaveCount(1)
        ->and($clean[0]->finding->status)->toBe(DoctorStatus::Pass)
        ->and($clean[0]->finding->check)->toBe(ConfigClassStringAudit::CHECK.'.clean');
});

it('harvests statically: a config that throws at runtime still yields its class-strings', function () {
    // The audit parses, never executes — a config whose evaluation would throw cannot abort the run.
    $findings = configAudit(
        [
            '/host/config/volatile.php' => <<<'PHP'
                <?php

                throw new RuntimeException('reading this config at runtime explodes');

                return ['source' => \App\Gone\Thing::class];
                PHP,
        ],
        resolves: fn (string $fqn) => $fqn === 'RuntimeException',
    )->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->status)->toBe(DoctorStatus::Fail)
        ->and($findings[0]->finding->detail)->toContain('App\\Gone\\Thing');
});

it('skips an unparseable config file without aborting the audit', function () {
    $findings = configAudit(
        [
            '/host/config/broken.php' => '<?php return [ this does not parse',
            '/host/config/fine.php' => "<?php\n\nreturn ['source' => \\App\\Gone\\Thing::class];\n",
        ],
        resolves: fn () => false,
    )->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->detail)->toContain('config/fine.php');
});

it('passes with a distinct check when the host ships no config files at all', function () {
    $audit = new ConfigClassStringAudit(appRoot: '/host', configFiles: fn () => []);

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
        ->and($findings[0]->finding->check)->toBe(ConfigClassStringAudit::CHECK.'.no-scope');
});

it('reads a real config tree off disk end-to-end and detects the historical defect shape', function () {
    $root = surgeon_tmp('config-class-string');

    // One import that resolves (a real class this package ships) and one that is stale.
    surgeon_write($root.'/config/streams.php', <<<'PHP'
        <?php

        use Rushing\Surgeon\Audit\Target;
        use Vendor\Retired\GoneSource;

        return [
            'sources' => [
                'live' => Target::class,
                'stale' => GoneSource::class,
            ],
        ];
        PHP);

    try {
        $findings = (new ConfigClassStringAudit($root))->suggestOperations();
        $fails = array_values(array_filter($findings, fn ($f) => $f->finding->status === DoctorStatus::Fail));

        expect($fails)->toHaveCount(1)
            ->and($fails[0]->finding->detail)->toContain('config/streams.php:9')
            ->and($fails[0]->finding->detail)->toContain('Vendor\\Retired\\GoneSource');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered as a built-in in the foundation package', function () {
    $classes = array_map(fn ($a) => $a::class, (new BuiltInAudits('/irrelevant'))->audits());

    expect($classes)->toContain(ConfigClassStringAudit::class);
});

/**
 * ⚠️ A NAMESPACE has the identical written shape to a stale class-string, and never resolves to a
 * class. This was the flagship's ONLY Fail — `'sdk_namespace' => 'Splicewire\Client\Requests'`, a
 * namespace with 55 classes under it — so the single finding standing between that host and a green
 * gate was the instrument asking the wrong question, not a defect in the host.
 */
it('excuses a string literal that names a populated namespace, and still fails a genuinely stale one', function () {
    $sources = [
        '/host/config/beam/client.php' => <<<'PHP'
            <?php

            return [
                'sdk_namespace' => 'Splicewire\Client\Requests',
                'model' => 'App\Models\Vanished',
            ];
            PHP,
    ];

    $findings = configAudit(
        $sources,
        resolves: fn () => false,
        isNamespace: fn (string $fqn) => $fqn === 'Splicewire\Client\Requests',
    )->suggestOperations();

    // Exactly one survivor: the namespace is excused, the stale class-string is not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->status)->toBe(DoctorStatus::Fail)
        ->and($findings[0]->finding->detail)->toContain('App\Models\Vanished')
        ->and($findings[0]->finding->detail)->not->toContain('Splicewire\Client\Requests');
});

it('does NOT excuse a ::class fetch on a namespace, because ::class declares the author meant a class', function () {
    // The excuse is scoped to the ambiguous form only. `Foo\Bar::class` where Foo\Bar is a namespace
    // is a real defect, and widening the excuse to cover it would have hidden the motivating bug.
    $findings = configAudit(
        ['/host/config/beam.php' => "<?php\n\nreturn ['source' => \Splicewire\Client\Requests::class];\n"],
        resolves: fn () => false,
        isNamespace: fn () => true,
    )->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->status)->toBe(DoctorStatus::Fail)
        ->and($findings[0]->finding->detail)->toContain('::class');
});

it('answers the namespace question from the root PSR-4 map on disk, not the loaded class table', function () {
    // The DEFAULT predicate, end to end. Asking `class_exists` about the classes under a namespace
    // reports "empty" for every namespace in the estate, because an audit run never autoloads them —
    // so the map on disk is the only instrument that can answer this.
    $root = surgeon_tmp('config-namespace-psr4');

    surgeon_write($root.'/vendor/composer/autoload_psr4.php', <<<'PHP'
        <?php

        return ['Splicewire\\Client\\' => [__DIR__.'/../../src/Client']];
        PHP);
    surgeon_write($root.'/src/Client/Requests/GetThing.php', "<?php\n\nnamespace Splicewire\Client\Requests;\n\nclass GetThing {}\n");
    surgeon_write($root.'/config/client.php', <<<'PHP'
        <?php

        return [
            'sdk_namespace' => 'Splicewire\Client\Requests',
            'absent_namespace' => 'Splicewire\Client\Nowhere',
        ];
        PHP);

    try {
        $findings = (new ConfigClassStringAudit($root))->suggestOperations();
        $fails = array_values(array_filter($findings, fn ($f) => $f->finding->status === DoctorStatus::Fail));

        // The populated namespace is excused off the real map; an unpopulated one still fails, so the
        // excuse is not "anything namespace-shaped".
        expect($fails)->toHaveCount(1)
            ->and($fails[0]->finding->detail)->toContain('Splicewire\Client\Nowhere')
            ->and($fails[0]->finding->detail)->not->toContain('Splicewire\Client\Requests');
    } finally {
        surgeon_rrmdir($root);
    }
});
