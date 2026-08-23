<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\UnsatisfiedNeighbourRequireAudit;
use Rushing\Surgeon\Operation\ConformanceSweep;
use Rushing\Surgeon\Operation\NullConformanceManifest;

/**
 * Ticket 69 — a package installed from a local `path` source requiring something this root's installed
 * set does not carry. Every case runs against a real disposable tree with a real
 * `vendor/composer/installed.json`, because the defect is a disagreement between composer's installed
 * record and a neighbour's manifest ON DISK, and only a filesystem can hold both.
 */

/**
 * Stand up a host root: an `installed.json` describing each package, and — for a path-installed one —
 * its checkout under `vendor/<name>/` carrying the composer.json the audit actually reads.
 *
 * @param  array<string, array{source?: string, require?: array<string,string>, manifest?: array<string,mixed>, installed?: array<string,mixed>, autoload?: bool}>  $packages
 */
function neighbourRoot(string $label, array $packages): string
{
    $root = surgeon_tmp('neighbour-'.$label);
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/host-app'], JSON_PRETTY_PRINT));

    $installed = [];
    foreach ($packages as $name => $spec) {
        $source = $spec['source'] ?? 'composer';
        $entry = [
            'name' => $name,
            'install-path' => '../'.$name, // relative to vendor/composer/
            'dist' => ['type' => $source],
        ] + ($spec['installed'] ?? []);

        if (($spec['autoload'] ?? true) === true) {
            $entry['autoload'] = ['psr-4' => ['Acme\\'.str_replace('-', '', ucfirst(basename($name))).'\\' => 'src/']];
        }

        $installed[] = $entry;

        // The package's own manifest on disk — the live source the host runs against.
        $manifest = $spec['manifest'] ?? ['name' => $name, 'require' => $spec['require'] ?? []];
        surgeon_write($root.'/vendor/'.$name.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    surgeon_write($root.'/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_PRETTY_PRINT));

    return $root;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function neighbourFailures(string $root): array
{
    return array_values(array_filter(
        (new UnsatisfiedNeighbourRequireAudit($root))->suggestOperations(),
        fn ($f) => $f->finding->status === DoctorStatus::Fail,
    ));
}

it('fails for a path-installed package whose require is absent, naming the package, the miss and the command', function () {
    $root = neighbourRoot('missing', [
        'acme/linked' => ['source' => 'path', 'require' => ['php' => '^8.3', 'acme/absent' => '^1.0']],
    ]);

    try {
        $failures = neighbourFailures($root);

        expect($failures)->toHaveCount(1)
            ->and($failures[0]->finding->check)->toBe(UnsatisfiedNeighbourRequireAudit::CHECK)
            ->and($failures[0]->finding->detail)->toContain('acme/linked is installed here from a local path source')
            ->and($failures[0]->finding->detail)->toContain('requires acme/absent')
            // Exactly one repair, and the payload carries it as a command rather than a nomination.
            ->and($failures[0]->suggestion?->payload)->toMatchArray([
                'command' => 'composer update acme/linked -W',
                'package' => 'acme/linked',
                'missing' => 'acme/absent',
            ]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is silent when the require is present in the installed set', function () {
    $root = neighbourRoot('present', [
        'acme/linked' => ['source' => 'path', 'require' => ['acme/present' => '^1.0']],
        'acme/present' => [],
    ]);

    try {
        expect(neighbourFailures($root))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('accepts a require satisfied by a replace or a provide, which is composer\'s own resolution rule', function () {
    $root = neighbourRoot('replaced', [
        'acme/linked' => ['source' => 'path', 'require' => ['acme/replaced' => '^1.0', 'acme/provided' => '^1.0']],
        'acme/umbrella' => ['installed' => ['replace' => ['acme/replaced' => 'self.version'], 'provide' => ['acme/provided' => '1.0']]],
    ]);

    try {
        expect(neighbourFailures($root))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('ignores platform requires — the runtime, extensions and composer\'s own APIs are not packages', function () {
    $root = neighbourRoot('platform', [
        'acme/linked' => ['source' => 'path', 'require' => [
            'php' => '^8.3',
            'ext-json' => '*',
            'lib-openssl' => '*',
            'composer-plugin-api' => '^2.0',
        ]],
    ]);

    try {
        expect(neighbourFailures($root))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('reads the neighbour\'s LIVE manifest, not the lock-time copy in installed.json — the stale copy is the defect', function () {
    // installed.json records the require block as it stood when composer last resolved: empty. The
    // checkout has moved on. Reading composer's copy would report the state that cannot be wrong.
    $root = neighbourRoot('stale', [
        'acme/linked' => [
            'source' => 'path',
            'require' => ['acme/absent' => '^1.0'],
            'installed' => ['require' => []],
        ],
    ]);

    try {
        expect(neighbourFailures($root))->toHaveCount(1);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('ignores a neighbour\'s require-dev — a dependency\'s dev requires are not installed for a dependency', function () {
    $root = neighbourRoot('dev', [
        'acme/linked' => ['source' => 'path', 'manifest' => [
            'name' => 'acme/linked',
            'require' => [],
            'require-dev' => ['acme/absent' => '^1.0'],
        ]],
    ]);

    try {
        expect(neighbourFailures($root))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('asks the question only of path-installed packages — a resolved artifact\'s requires are composer\'s business', function () {
    $root = neighbourRoot('resolved', [
        'acme/registry' => ['require' => ['acme/absent' => '^1.0']],
    ]);

    try {
        $findings = (new UnsatisfiedNeighbourRequireAudit($root))->suggestOperations();

        expect(neighbourFailures($root))->toBeEmpty()
            ->and($findings[0]->finding->check)->toBe(UnsatisfiedNeighbourRequireAudit::CHECK.'.no-scope')
            ->and($findings[0]->finding->detail)->toContain('came from a local path source');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('counts a package with no psr-4 autoload as present — a files-autoload package is installed too', function () {
    // The regression the InstalledPackages widening exists for: dropping root-less packages would read
    // every symfony/polyfill-* as absent and manufacture a finding against a root that has it.
    $root = neighbourRoot('rootless', [
        'acme/linked' => ['source' => 'path', 'require' => ['acme/polyfill' => '^1.0']],
        'acme/polyfill' => ['autoload' => false],
    ]);

    try {
        expect(neighbourFailures($root))->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes with no scope when nothing is installed — an audit that cannot see is not an audit that found nothing', function () {
    $root = surgeon_tmp('neighbour-empty');

    try {
        $findings = (new UnsatisfiedNeighbourRequireAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->check)->toBe(UnsatisfiedNeighbourRequireAudit::CHECK.'.no-scope')
            ->and($findings[0]->finding->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('is registered as a built-in, so surgeon:audit runs it in every host', function () {
    $classes = array_map(fn ($a) => $a::class, (new BuiltInAudits('/irrelevant'))->audits());

    expect($classes)->toContain(UnsatisfiedNeighbourRequireAudit::class);
});

it('states Fail severity, which the built-in channel renders without reddening the exit code', function () {
    // The audit's own severity is Fail — there is one repair and the untreated state is a fatal
    // class-not-found. Whether that gates is the CHANNEL's call, and the built-in channel is
    // uniformly non-gating (ConformanceSweep hardcodes gate: false for every surgeon-native audit,
    // DanglingPathRepoAudit's live-brick Fail included). Asserted as it is, not as it ought to be.
    $root = neighbourRoot('gate', [
        'acme/linked' => ['source' => 'path', 'require' => ['acme/absent' => '^1.0']],
    ]);

    try {
        $report = (new ConformanceSweep(fn (string $a) => new $a))
            ->run(new NullConformanceManifest, [new UnsatisfiedNeighbourRequireAudit($root)]);

        expect($report->results[0]->fixables[0]->finding->status)->toBe(DoctorStatus::Fail)
            ->and($report->results[0]->gate)->toBeFalse()
            ->and($report->hasGateFailure())->toBeFalse();
    } finally {
        surgeon_rrmdir($root);
    }
});
