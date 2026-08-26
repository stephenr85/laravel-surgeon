<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\PublishedMigrationDriftAudit;

/**
 * b3 — beam-facade ticket 116. A published migration copy under a host's `database/migrations/**` whose
 * content no longer matches the package file it was published from. Every case runs against a real
 * disposable tree with a real `vendor/composer/installed.json`, because the whole audit is a
 * content-comparison between two files on disk resolved through composer's own install record.
 */

/**
 * Stand up a host root: an `installed.json` naming each package plus that package's shipped migrations,
 * and the host's own published set.
 *
 * @param  array<string, array<string, string>>  $packages  composer name => (relative migration path => contents)
 * @param  array<string, string>  $published  relative path under database/migrations => contents
 */
function driftRoot(string $label, array $packages, array $published): string
{
    $root = surgeon_tmp('migration-drift-'.$label);

    $installed = [];
    foreach ($packages as $name => $migrations) {
        $installed[] = ['name' => $name, 'install-path' => '../'.$name];
        foreach ($migrations as $path => $contents) {
            surgeon_write($root.'/vendor/'.$name.'/database/migrations/'.$path, $contents);
        }
    }
    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_PRETTY_PRINT));

    foreach ($published as $path => $contents) {
        surgeon_write($root.'/database/migrations/'.$path, $contents);
    }

    return $root;
}

/** The stub the estate actually ships: an anonymous class in a timestamp-less `.php.stub`. */
function driftStub(string $body): string
{
    return <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            {$body}
        }
    };

    PHP;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function driftFindings(string $appRoot): array
{
    return (new PublishedMigrationDriftAudit($appRoot))->suggestOperations();
}

it('is silent when the published copy is byte-identical to the stub it came from', function () {
    $stub = driftStub("Schema::create('widgets', fn (Blueprint \$table) => \$table->id());");

    $root = driftRoot('identical', [
        'acme/widgets' => ['create_widgets_table.php.stub' => $stub],
    ], [
        '2026_01_02_030405_create_widgets_table.php' => $stub,
    ]);

    try {
        $findings = driftFindings($root);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(PublishedMigrationDriftAudit::CHECK.'.none')
            ->and($findings[0]->finding->detail)->toContain('All 1 published migration copies');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('warns advisory-only when the published copy has drifted, naming the source and the divergence', function () {
    $root = driftRoot('drifted', [
        'acme/widgets' => [
            'create_widgets_table.php.stub' => driftStub("ConvergentTable::named('widgets')->create();"),
        ],
    ], [
        '2026_01_02_030405_create_widgets_table.php' => driftStub("Schema::create('widgets', fn (Blueprint \$table) => \$table->id());"),
    ]);

    try {
        $findings = driftFindings($root);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Warn)
            ->and($findings[0]->finding->check)->toBe(PublishedMigrationDriftAudit::CHECK.'.drifted')
            ->and($findings[0]->finding->detail)
            ->toContain('2026_01_02_030405_create_widgets_table.php')
            ->toContain('acme/widgets/database/migrations/create_widgets_table.php.stub')
            ->toContain('first difference at line');

        // Reports, never repairs — the repair is agentic, so it nominates no operation.
        expect($findings[0]->isAdvisory())->toBeTrue()
            ->and($findings[0]->isFixable())->toBeFalse()
            ->and($findings[0]->suggestion->owningPackage)->toBe('acme/widgets');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('ignores a host migration no installed package ships', function () {
    $root = driftRoot('host-own', [
        'acme/widgets' => ['create_widgets_table.php.stub' => driftStub('//')],
    ], [
        '2026_01_02_030405_create_widgets_table.php' => driftStub('//'),
        '2026_02_02_030405_create_host_only_table.php' => driftStub("Schema::create('host_only', fn () => null);"),
    ]);

    try {
        expect(driftFindings($root)[0]->finding->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('matches across a publish-destination move and names the relocation as part of the drift', function () {
    // The estate's most common real drift: a package collapsed its flat + `tenant/` twins into one
    // `shared/` file, and the host still runs the copy at the old location. package-tools' own re-find
    // globs the DECLARED directory only, so a directory-scoped match would go silent here.
    $root = driftRoot('moved', [
        'acme/widgets' => [
            'shared/create_widgets_table.php.stub' => driftStub("ConvergentTable::named('widgets')->create();"),
        ],
    ], [
        'tenant/2026_01_02_030405_create_widgets_table.php' => driftStub("Schema::create('widgets', fn () => null);"),
    ]);

    try {
        $findings = driftFindings($root);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Warn)
            ->and($findings[0]->finding->detail)
            ->toContain('The source now publishes into `shared/`, this copy is at `tenant/`.');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('names every package shipping the stem when provenance is ambiguous', function () {
    $root = driftRoot('ambiguous', [
        'acme/widgets' => ['create_flags_table.php.stub' => driftStub('// acme')],
        'other/flags' => ['create_flags_table.php' => driftStub('// other')],
    ], [
        '2026_01_02_030405_create_flags_table.php' => driftStub('// neither'),
    ]);

    try {
        $findings = driftFindings($root);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->detail)
            ->toContain('acme/widgets/database/migrations/create_flags_table.php.stub')
            ->toContain('other/flags/database/migrations/create_flags_table.php');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is silent when the copy matches ANY package shipping that stem', function () {
    $shared = driftStub('// the one that was actually published');

    $root = driftRoot('ambiguous-match', [
        'acme/widgets' => ['create_flags_table.php.stub' => driftStub('// acme')],
        'other/flags' => ['create_flags_table.php' => $shared],
    ], [
        '2026_01_02_030405_create_flags_table.php' => $shared,
    ]);

    try {
        expect(driftFindings($root)[0]->finding->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('treats line endings and a missing trailing newline as publishing noise, not drift', function () {
    $stub = driftStub('//');

    $root = driftRoot('noise', [
        'acme/widgets' => ['create_widgets_table.php.stub' => $stub],
    ], [
        '2026_01_02_030405_create_widgets_table.php' => rtrim(str_replace("\n", "\r\n", $stub)),
    ]);

    try {
        expect(driftFindings($root)[0]->finding->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes with a scope note when the host has no published migrations directory', function () {
    $root = surgeon_tmp('migration-drift-no-scope');

    try {
        $findings = driftFindings($root);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($findings[0]->finding->check)->toBe(PublishedMigrationDriftAudit::CHECK.'.no-scope');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered in the built-in set so surgeon:audit runs it', function () {
    $audits = (new BuiltInAudits('/nonexistent-root'))->audits();

    expect(array_filter($audits, fn ($a) => $a instanceof PublishedMigrationDriftAudit))->toHaveCount(1);
});
