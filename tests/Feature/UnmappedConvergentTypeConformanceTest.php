<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\UnmappedConvergentTypeAudit;

/**
 * beam-facade ticket 183a — a column type declared in a convergent migration stub that
 * `ColumnTypeEquivalence` has no mapping for, and would therefore report `unverified`.
 *
 * The equivalence map is reached through injectable readers rather than the real class, because
 * `rushing/laravel-schema-convergence` is deliberately NOT a dependency of surgeon: testing the
 * mapped/unmapped branches against the real class would mean adding a `require-dev` — a supply change
 * to prove a guard. The injected reader here stands in for the map; a separate case covers the
 * production path where neither is available.
 */

/** A host root with an installed package shipping the given migration files. */
function convergentRoot(string $label, array $files, array $hostFiles = []): string
{
    $root = surgeon_tmp('convergent-type-'.$label);

    surgeon_write($root.'/vendor/composer/installed.json', json_encode([
        'packages' => [['name' => 'acme/widgets', 'install-path' => '../acme/widgets']],
    ], JSON_PRETTY_PRINT));

    foreach ($files as $path => $contents) {
        surgeon_write($root.'/vendor/acme/widgets/database/migrations/'.$path, $contents);
    }

    foreach ($hostFiles as $path => $contents) {
        surgeon_write($root.'/database/migrations/'.$path, $contents);
    }

    return $root;
}

/** The stub shape the estate ships: an anonymous migration declaring through `ConvergentTable`. */
function convergentStub(string $body): string
{
    return <<<PHP
    <?php

    use Illuminate\\Database\\Schema\\Blueprint;
    use Rushing\\SchemaConvergence\\ConvergentTable;

    return new class extends Migration
    {
        public function up(): void
        {
            ConvergentTable::named('widgets')
                ->define(function (Blueprint \$table) {
                    {$body}
                })
                ->assert();
        }
    };

    PHP;
}

/** A map that knows only what it is told — stands in for `ColumnTypeEquivalence`. */
function fakeMap(array $known): array
{
    return [
        fn (string $type) => in_array($type, $known, true),
        fn () => $known,
    ];
}

/** @return array<string, \Rushing\Doctor\Finding> check => finding */
function convergentFindings(string $root, ?array $map = null): array
{
    [$isMapped, $mappedTypes] = $map ?? [null, null];

    $keyed = [];
    foreach ((new UnmappedConvergentTypeAudit($root, 'database/migrations', $isMapped, $mappedTypes))->suggestOperations() as $fixable) {
        $keyed[$fixable->finding->check] = $fixable->finding;
    }

    return $keyed;
}

it('passes with no scope when nothing installed here declares through ConvergentTable', function () {
    $root = convergentRoot('no-scope', [
        '2026_01_01_000000_create_widgets_table.php' => "<?php\n\nSchema::create('widgets', fn (\$t) => \$t->id());\n",
    ]);

    try {
        $findings = convergentFindings($root, fakeMap(['bigInteger']));

        expect($findings)->toHaveCount(1)
            ->and($findings[UnmappedConvergentTypeAudit::CHECK.'.no-scope']->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reports the map as UNREAD rather than clean when convergent stubs exist and it cannot be loaded', function () {
    $root = convergentRoot('unavailable', [
        'create_widgets_table.php.stub' => convergentStub("\$table->geometry('shape');"),
    ]);

    try {
        // No injected map, and rushing/laravel-schema-convergence is not installed in surgeon's own
        // vendor — this is the production shape of the unavailable branch, not a simulation of it.
        $findings = convergentFindings($root);

        expect($findings)->toHaveCount(1)
            ->and($findings[UnmappedConvergentTypeAudit::CHECK.'.detection-unavailable']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[UnmappedConvergentTypeAudit::CHECK.'.detection-unavailable']->detail)->toContain('UNCHECKED');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('warns for a declared type the map has no entry for, and names the method that declared it', function () {
    $root = convergentRoot('unmapped', [
        'create_widgets_table.php.stub' => convergentStub("\$table->geometry('shape');\n\$table->string('name');"),
    ]);

    try {
        $findings = convergentFindings($root, fakeMap(['string']));

        expect($findings[UnmappedConvergentTypeAudit::CHECK.'.unmapped']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[UnmappedConvergentTypeAudit::CHECK.'.unmapped']->detail)
            ->toContain('`geometry`')
            ->toContain('$table->geometry()')
            ->toContain('UNVERIFIED');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes when every declared type is mapped', function () {
    $root = convergentRoot('covered', [
        'create_widgets_table.php.stub' => convergentStub("\$table->string('name');\n\$table->json('meta');"),
    ]);

    try {
        $findings = convergentFindings($root, fakeMap(['string', 'json']));

        expect($findings[UnmappedConvergentTypeAudit::CHECK.'.covered']->status)->toBe(DoctorStatus::Pass)
            ->and($findings)->not->toHaveKey(UnmappedConvergentTypeAudit::CHECK.'.unmapped');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('does not score a structural call as a type — an index declares no column', function () {
    $root = convergentRoot('structural', [
        'create_widgets_table.php.stub' => convergentStub(
            "\$table->string('name');\n\$table->index(['name'], 'widgets_name_index');\n\$table->unique('name');\n\$table->foreign('other_id')->references('id');"
        ),
    ]);

    try {
        $findings = convergentFindings($root, fakeMap(['string']));

        expect($findings[UnmappedConvergentTypeAudit::CHECK.'.covered']->status)->toBe(DoctorStatus::Pass)
            ->and($findings)->not->toHaveKey(UnmappedConvergentTypeAudit::CHECK.'.unmapped')
            ->and($findings)->not->toHaveKey(UnmappedConvergentTypeAudit::CHECK.'.unclassified');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('expands a convenience method into the types it really declares, so morphs is never reported as a type', function () {
    $root = convergentRoot('expansion', [
        'create_widgets_table.php.stub' => convergentStub("\$table->id();\n\$table->nullableUuidMorphs('owner');\n\$table->timestamps();"),
    ]);

    try {
        // The map knows exactly what those three methods expand to and nothing else. If the audit scored
        // the METHOD names, `id`, `nullableUuidMorphs` and `timestamps` would all report unmapped.
        $findings = convergentFindings($root, fakeMap(['bigInteger', 'string', 'uuid', 'timestamp']));

        expect($findings[UnmappedConvergentTypeAudit::CHECK.'.covered']->status)->toBe(DoctorStatus::Pass)
            ->and($findings)->not->toHaveKey(UnmappedConvergentTypeAudit::CHECK.'.unmapped');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reports a method it cannot classify as unclassified, never as an unmapped type', function () {
    $root = convergentRoot('unclassified', [
        'create_widgets_table.php.stub' => convergentStub("\$table->string('name');\n\$table->acmeCustomBlueprintMacro('thing');"),
    ]);

    try {
        $findings = convergentFindings($root, fakeMap(['string']));

        expect($findings[UnmappedConvergentTypeAudit::CHECK.'.unclassified']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[UnmappedConvergentTypeAudit::CHECK.'.unclassified']->detail)
            ->toContain('acmeCustomBlueprintMacro')
            ->and($findings)->not->toHaveKey(UnmappedConvergentTypeAudit::CHECK.'.unmapped');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reads the host own migrations as well as installed packages', function () {
    $root = convergentRoot('host', [], [
        '2026_01_01_000000_create_host_table.php' => convergentStub("\$table->geometry('shape');"),
    ]);

    try {
        $findings = convergentFindings($root, fakeMap([]));

        expect($findings[UnmappedConvergentTypeAudit::CHECK.'.unmapped']->detail)->toContain('geometry');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('always carries a scope note stating that nothing was executed and no database was read', function () {
    $root = convergentRoot('scope', [
        'create_widgets_table.php.stub' => convergentStub("\$table->string('name');"),
    ]);

    try {
        expect(convergentFindings($root, fakeMap(['string']))[UnmappedConvergentTypeAudit::CHECK.'.scope']->detail)
            ->toContain('no declaration was executed and no database was read')
            ->toContain('183b');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('never emits a Fail — an incomplete map is not a broken repo', function () {
    $root = convergentRoot('advisory', [
        'create_widgets_table.php.stub' => convergentStub("\$table->geometry('shape');\n\$table->acmeMacro('x');"),
    ]);

    try {
        $statuses = array_map(fn ($f) => $f->status, convergentFindings($root, fakeMap([])));

        expect($statuses)->not->toContain(DoctorStatus::Fail);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered in the built-in set so surgeon:audit runs it', function () {
    $audits = (new BuiltInAudits('/nonexistent-root'))->audits();

    expect(array_filter($audits, fn ($a) => $a instanceof UnmappedConvergentTypeAudit))->toHaveCount(1);
});
