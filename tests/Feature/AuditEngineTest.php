<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\Tier;

function fixtures(string $sub = ''): string
{
    return dirname(__DIR__).'/Fixtures'.($sub === '' ? '' : '/'.$sub);
}

/** @return array<string,int> category value => count */
function categoryCounts(array $references): array
{
    $counts = [];
    foreach ($references as $ref) {
        $counts[$ref->category->value] = ($counts[$ref->category->value] ?? 0) + 1;
    }

    return $counts;
}

it('enumerates and classifies every touch-point of a symbol, by category', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));

    expect(categoryCounts($report->references()))->toEqual([
        ReferenceCategory::NamespaceDeclaration->value => 1,
        ReferenceCategory::UseImport->value => 2,       // Consumer + Documented
        ReferenceCategory::TypeHint->value => 1,
        ReferenceCategory::ClassConstFetch->value => 1,
        ReferenceCategory::MorphMapEntry->value => 1,
        ReferenceCategory::ProviderRegistration->value => 1,
        ReferenceCategory::RuntimeApp->value => 1,
        ReferenceCategory::MigrationReference->value => 2,
        ReferenceCategory::TestFixtureReference->value => 3, // use + return-type + `new Widget`
        ReferenceCategory::RouteConfigReference->value => 1,
        ReferenceCategory::DocblockTagReference->value => 4,   // 3 {@see} + orphan @var, all in Documented
        ReferenceCategory::DocblockProseReference->value => 2, // Documented's sentence + Sibling's same-namespace mention
    ]);
});

it('groups touch-points across the deterministic/agentic tier seam', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));

    $byTier = array_map('count', $report->byTier());

    expect($byTier[Tier::One->value])->toBe(12)  // ns-decl + 2 use + typehint + ::class + 3 test refs + 4 docblock tags
        ->and($byTier[Tier::Two->value])->toBe(6)   // morph + runtime-app + 2 migration refs + 2 prose
        ->and($byTier[Tier::Three->value])->toBe(2); // provider registration + route/config
});

it('lands the five originally-missed docblock forms as four tag refs at tier one and one prose ref at tier two', function () {
    // The original rename shape: three {@see}, one orphan @var above a plain assignment, one prose
    // sentence — all in one file (Documented.php). Four splice automatically; the fifth is reported.
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));

    $inDocumented = array_values(array_filter(
        $report->references(),
        fn ($r) => str_ends_with($r->relativePath, 'src/Documented.php'),
    ));

    $tags = array_values(array_filter($inDocumented, fn ($r) => $r->category === ReferenceCategory::DocblockTagReference));
    $prose = array_values(array_filter($inDocumented, fn ($r) => $r->category === ReferenceCategory::DocblockProseReference));

    expect($tags)->toHaveCount(4)
        ->and(collect($tags)->every(fn ($r) => $r->tier === Tier::One))->toBeTrue()
        ->and($prose)->toHaveCount(1)
        ->and($prose[0]->tier)->toBe(Tier::Two);

    // The orphan @var above the plain assignment is among the tags — located by the fixture's own
    // `@var` line, so the assertion holds regardless of parser comment attachment.
    $source = file_get_contents(fixtures('estate/src/Documented.php'));
    $varLine = 1 + substr_count($source, "\n", 0, strpos($source, '@var'));
    expect(collect($tags)->contains(fn ($r) => $r->line === $varLine && $r->snippet === 'Widget'))->toBeTrue();
});

it('admits a bare docblock short name only through the import table or the current namespace', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));

    $prose = array_values(array_filter(
        $report->references(),
        fn ($r) => $r->category === ReferenceCategory::DocblockProseReference,
    ));

    // Sibling.php never imports Widget — its bare mention resolves as a same-namespace sibling.
    expect(collect($prose)->contains(fn ($r) => str_ends_with($r->relativePath, 'src/Sibling.php')))->toBeTrue();

    // EstateProvider.php's comment says the bare word "Widget" too — but that file neither imports
    // the target nor shares its namespace, so the mention resolves elsewhere and never enters.
    expect(collect($report->references())->contains(
        fn ($r) => str_ends_with($r->relativePath, 'src/EstateProvider.php')
            && ($r->category === ReferenceCategory::DocblockProseReference || $r->category === ReferenceCategory::DocblockTagReference),
    ))->toBeFalse();
});

it('records the exact byte span of each name so a rewriter can splice without reprinting', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));

    foreach ($report->references() as $ref) {
        $source = file_get_contents($ref->file);
        $spliced = substr($source, $ref->startPos, $ref->endPos - $ref->startPos + 1);
        // The span reproduces the recorded snippet byte-for-byte — the splice contract.
        expect($spliced)->toBe($ref->snippet);
        // Every span names either the symbol or its enclosing namespace (the ns-decl touch-point).
        expect(str_contains($spliced, 'Widget') || str_contains($spliced, 'Vendor\\Old'))->toBeTrue();
    }
});

it('matches a namespace prefix target against every symbol under it', function () {
    // Widget lives under Vendor\Old; a namespace target must catch it.
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::namespace('Vendor\\Old'));

    expect($report->isEmpty())->toBeFalse()
        ->and(collect($report->references())->pluck('matchedFqn')->unique()->all())
        ->toContain('Vendor\\Old\\Widget');
});

it('stamps the canonical destination on each reference for a relocation', function () {
    $target = Target::relocatingTo('Vendor\\Old\\Widget', 'Vendor\\New\\Widget');
    $report = (new AuditEngine)->audit([fixtures('estate')], $target);

    $consumerUse = collect($report->references())
        ->first(fn ($r) => $r->category === ReferenceCategory::UseImport);

    expect($consumerUse->newFqn)->toBe('Vendor\\New\\Widget');
});

it('flags a reference whose relocation would close an upward composer edge', function () {
    $roots = [fixtures('graph/app'), fixtures('graph/low')];
    $graph = PackageGraph::fromRoots($roots);

    // Thing is referenced in acme/low; moving it UP into acme/app needs low → app, but app already
    // requires low. Cycle risk.
    $target = Target::relocatingTo('Acme\\Shared\\Thing', 'Acme\\App\\Thing');
    $report = (new AuditEngine)->audit($roots, $target, $graph);

    expect($report->cycleRisks())->not->toBeEmpty();
    foreach ($report->cycleRisks() as $risk) {
        expect($risk->package)->toBe('acme/low')
            ->and($risk->cycleRisk)->toContain('upward composer edge');
    }
});

it('does not flag a downward relocation as a cycle risk', function () {
    $roots = [fixtures('graph/app'), fixtures('graph/low')];
    $graph = PackageGraph::fromRoots($roots);

    // Widget is referenced in acme/app; moving it DOWN into acme/low is safe (app already requires low).
    $target = Target::relocatingTo('Acme\\Shared\\Widget', 'Acme\\Low\\Widget');
    $report = (new AuditEngine)->audit($roots, $target, $graph);

    expect($report->count())->toBeGreaterThan(0)
        ->and($report->cycleRisks())->toBeEmpty();
});

it('expands a directory target to the symbols declared under it (the inbound-reference hunt)', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::directory(fixtures('estate/src')));

    // Should find references to Widget (declared under src) from across the estate.
    expect($report->isEmpty())->toBeFalse()
        ->and(collect($report->references())->pluck('matchedFqn')->unique()->all())
        ->toContain('Vendor\\Old\\Widget');
});

it('serialises to a machine-readable, span-annotated finding-set', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));
    $array = $report->toArray();

    expect($array['summary']['total'])->toBe(20)
        ->and($array['summary']['by_tier'])->toHaveKeys([Tier::One->value, Tier::Two->value, Tier::Three->value])
        ->and($array['references'][0])->toHaveKeys(['category', 'tier', 'file', 'line', 'matched', 'span', 'snippet']);
});

it('projects the result into doctor Finding vocabulary (Warn per tier, Fail per cycle risk)', function () {
    $clean = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Old\\Widget'));
    $findings = $clean->toDoctorFindings();

    expect(collect($findings)->every(fn ($f) => $f instanceof Finding))->toBeTrue()
        ->and(collect($findings)->contains(fn ($f) => $f->status === DoctorStatus::Warn))->toBeTrue();

    $roots = [fixtures('graph/app'), fixtures('graph/low')];
    $risky = (new AuditEngine)->audit($roots, Target::relocatingTo('Acme\\Shared\\Thing', 'Acme\\App\\Thing'), PackageGraph::fromRoots($roots));

    expect(collect($risky->toDoctorFindings())->contains(fn ($f) => $f->status === DoctorStatus::Fail))->toBeTrue();
});

it('returns a Pass finding when the hunt is clean', function () {
    $report = (new AuditEngine)->audit([fixtures('estate')], Target::symbol('Vendor\\Nonexistent\\Ghost'));

    expect($report->isEmpty())->toBeTrue()
        ->and($report->toDoctorFindings()[0]->status)->toBe(DoctorStatus::Pass);
});
