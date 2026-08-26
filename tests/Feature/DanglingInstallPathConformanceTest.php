<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\DanglingInstallPathAudit;
use Rushing\Surgeon\Operation\ConformanceSweep;
use Rushing\Surgeon\Operation\NullConformanceManifest;

/**
 * beam-facade tickets 74/92 — a `vendor/` entry that resolves nowhere, in its two halves.
 *
 * **Built against fixtures, never a root, and that is a finding rather than a convenience.** The
 * specimen is extinct: re-measured 2026-08-23 across the whole estate, **106 roots with a `vendor/`,
 * 1256 `vendor/*​/*` symlinks, 0 dangling.** 57 deleted the 93 orphans; the 7 in-lock danglers it
 * ruled undeletable were cleared as a side effect of 73 supplying those roots, because `composer
 * update` became runnable and installed state regenerated. A session that goes looking for a live
 * instance to verify against will find 1256 healthy symlinks and nothing to see.
 *
 * Every case therefore stands up a real disposable tree with a real `vendor/composer/installed.json`
 * and, where the half needs one, a real symlink — the defect is a disagreement between composer's
 * record and the filesystem, and only a filesystem can hold both.
 */

/**
 * A host root with an installed set and, optionally, `vendor/<name>` entries on disk.
 *
 * @param  array<string, array{present?: bool, link?: string}>  $packages  name => whether a real dir is
 *                                                                         created, or a symlink target to
 *                                                                         point a `vendor/<name>` link at
 * @param  list<string>  $orphans  `vendor/<vendor>/<name>` symlinks created but NOT recorded in installed.json
 */
function installPathRoot(string $label, array $packages, array $orphans = []): string
{
    $root = surgeon_tmp('install-path-'.$label);
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/host-app'], JSON_PRETTY_PRINT));

    $installed = [];
    foreach ($packages as $name => $spec) {
        $installed[] = [
            'name' => $name,
            'install-path' => '../'.$name, // composer records this relative to vendor/composer/
            'dist' => ['type' => 'path'],
        ];

        if ($spec['present'] ?? true) {
            surgeon_write($root.'/vendor/'.$name.'/composer.json', json_encode(['name' => $name], JSON_PRETTY_PRINT));
        }

        if (isset($spec['link'])) {
            @mkdir(dirname($root.'/vendor/'.$name), 0777, true);
            @symlink($spec['link'], $root.'/vendor/'.$name);
        }
    }

    foreach ($orphans as $name) {
        @mkdir(dirname($root.'/vendor/'.$name), 0777, true);
        @symlink($root.'/nowhere/'.$name, $root.'/vendor/'.$name);
    }

    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_PRETTY_PRINT));

    return $root;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function installPathFindings(string $root, DoctorStatus $status): array
{
    return array_values(array_filter(
        (new DanglingInstallPathAudit($root))->suggestOperations(),
        fn ($f) => $f->finding->status === $status,
    ));
}

it('fails for a package composer believes is installed whose install path does not exist', function () {
    $root = installPathRoot('missing', [
        'acme/present' => [],
        'acme/gone' => ['present' => false],
    ]);

    try {
        $failures = installPathFindings($root, DoctorStatus::Fail);

        expect($failures)->toHaveCount(1)
            ->and($failures[0]->finding->check)->toBe(DanglingInstallPathAudit::CHECK)
            ->and($failures[0]->finding->detail)->toContain('acme/gone is recorded in vendor/composer/installed.json')
            // The repair is REGENERATION, never a deletion — 57's seven cleared exactly this way.
            ->and($failures[0]->finding->detail)->toContain('The repair is REGENERATION, not deletion')
            ->and($failures[0]->suggestion?->payload)->toMatchArray([
                'command' => 'composer update acme/gone -W',
                'package' => 'acme/gone',
            ]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reports the missing path collapsed, not with composer\'s `..` segments still in it', function () {
    // composer records install-path relative to vendor/composer/, and realpath() cannot collapse a
    // path that is not there — so the naive read hands a human `…/vendor/composer/../acme/gone`.
    $root = installPathRoot('collapsed', ['acme/gone' => ['present' => false]]);

    try {
        $detail = installPathFindings($root, DoctorStatus::Fail)[0]->finding->detail;

        expect($detail)->toContain('/vendor/acme/gone')
            ->and($detail)->not->toContain('/vendor/composer/../');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('warns — never fails — for a dangling symlink absent from installed.json, and states the deletion criterion', function () {
    $root = installPathRoot('orphan', ['acme/present' => []], ['acme/orphan']);

    try {
        $warnings = installPathFindings($root, DoctorStatus::Warn);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->check)->toBe(DanglingInstallPathAudit::CHECK.'.orphan')
            ->and($warnings[0]->finding->detail)->toContain('vendor/acme/orphan is a symlink to')
            ->and($warnings[0]->finding->detail)->toContain('absent from this root\'s vendor/composer/installed.json')
            // 57's criterion, in the finding rather than in a docblock, so a reader can act in one command.
            ->and($warnings[0]->finding->detail)->toContain('still a symlink, still dangling, and still absent')
            ->and($warnings[0]->finding->detail)->toContain('re-verified at delete time')
            ->and($warnings[0]->finding->detail)->toContain('Never delete by pattern')
            ->and(installPathFindings($root, DoctorStatus::Fail))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('nominates nothing deterministic for either half — 74 refused a filesystem-deleting OverlayVerb', function () {
    $root = installPathRoot('advisory', ['acme/gone' => ['present' => false]], ['acme/orphan']);

    try {
        $actionable = array_filter(
            (new DanglingInstallPathAudit($root))->suggestOperations(),
            fn ($f) => $f->suggestion !== null && $f->suggestion->kind !== Rushing\Surgeon\Operation\OperationSuggestion::ADVISORY,
        );

        expect($actionable)->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reports a dangling symlink composer DOES know about once, as the Fail half, not twice', function () {
    // The same directory is both a recorded install path and a broken symlink. Two findings for one
    // defect would let the Warn half's "inert" diagnosis sit next to the Fail half's "this root
    // cannot boot" and contradict it.
    $root = installPathRoot('known-link', [
        'acme/linked' => ['present' => false, 'link' => '/definitely/not/here'],
    ]);

    try {
        expect(installPathFindings($root, DoctorStatus::Fail))->toHaveCount(1)
            ->and(installPathFindings($root, DoctorStatus::Warn))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('ignores vendor/bin and vendor/composer — neither is a package slot', function () {
    $root = installPathRoot('slots', ['acme/present' => []]);
    @mkdir($root.'/vendor/bin', 0777, true);
    @symlink($root.'/nowhere/script', $root.'/vendor/bin/dead-shim');

    try {
        expect(installPathFindings($root, DoctorStatus::Warn))->toBeEmpty()
            ->and(installPathFindings($root, DoctorStatus::Fail))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is silent when everything resolves, and says so with both denominators', function () {
    $root = installPathRoot('clean', ['acme/present' => []]);

    try {
        $findings = (new DanglingInstallPathAudit($root))->suggestOperations();

        expect(installPathFindings($root, DoctorStatus::Fail))->toBeEmpty()
            ->and(installPathFindings($root, DoctorStatus::Warn))->toBeEmpty()
            ->and($findings[0]->finding->check)->toBe(DanglingInstallPathAudit::CHECK.'.clean')
            ->and($findings[0]->finding->detail)->toContain('All 1 installed package(s) resolve');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('states the running-root-only population and why it differs from dangling-path-repo, in the report', function () {
    // The asymmetry ships as a finding rather than a docblock: the person who needs it is reading a
    // sweep, and without it the next reader reads a non-walking audit as an oversight.
    $root = installPathRoot('scope', ['acme/present' => []]);

    try {
        $scope = array_values(array_filter(
            (new DanglingInstallPathAudit($root))->suggestOperations(),
            fn ($f) => $f->finding->check === DanglingInstallPathAudit::CHECK.'.scope',
        ));

        expect($scope)->toHaveCount(1)
            ->and($scope[0]->finding->detail)->toContain('THIS root only')
            ->and($scope[0]->finding->detail)->toContain('dangling-path-repo')
            ->and($scope[0]->finding->detail)->toContain('points at a PACKAGE')
            ->and($scope[0]->finding->detail)->toContain('are HOSTS');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes with no scope when there is no vendor/ — an audit that cannot see has not found nothing', function () {
    $root = surgeon_tmp('install-path-empty');

    try {
        $findings = (new DanglingInstallPathAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->check)->toBe(DanglingInstallPathAudit::CHECK.'.no-scope')
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered as a built-in, so surgeon:audit runs it in every host', function () {
    $classes = array_map(fn ($a) => $a::class, (new BuiltInAudits('/irrelevant'))->audits());

    expect($classes)->toContain(DanglingInstallPathAudit::class);
});

it('does NOT redden the exit code on the built-in channel, which is ticket 88\'s gap asserted rather than worked around', function () {
    // Ticket 88, third specimen and the first built already knowing. The Fail half renders red and
    // the sweep still exits 0 — the built-in channel is advisory BY CONTRACT, not by omission.
    //
    // 92's premise about the mechanism is stale and worth correcting here rather than silently:
    // it cited `ConformanceSweep.php:90` as HARDCODING `gate: false` for every built-in. That is no
    // longer true — the sweep now reads a per-audit opt-in off the host's own doctor manifest
    // (`gateOptIns()`), so a host CAN make this audit gate by registering the class with
    // `gate: true`. What is asserted here is the default a host gets without doing that, which is
    // what 88 is about and what 74 chose to inherit rather than special-case.
    $root = installPathRoot('gate', ['acme/gone' => ['present' => false]]);

    try {
        $report = (new ConformanceSweep(fn (string $a) => new $a))
            ->run(new NullConformanceManifest, [new DanglingInstallPathAudit($root)]);

        expect($report->results[0]->fixables[0]->finding->status)->toBe(DoctorStatus::Fail)
            ->and($report->results[0]->gate)->toBeFalse()
            ->and($report->hasGateFailure())->toBeFalse();
    } finally {
        surgeon_rrmdir($root);
    }
});
