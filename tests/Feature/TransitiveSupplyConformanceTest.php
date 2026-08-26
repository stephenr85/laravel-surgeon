<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\RequireWithoutSupplyAudit;
use Rushing\Surgeon\Conformance\TransitiveSupplyAudit;

/**
 * beam-facade ticket 129 — the transitive supply check. Every case stands up a real disposable tree,
 * because the defect is a disagreement between a manifest's `repositories` list and a CLOSURE that can
 * only be walked by reading neighbouring checkouts off a filesystem.
 */

/**
 * Stand up a root plus a set of local package checkouts under `packages/<vendor>/<name>/`, path-supplied
 * to the root by a single `../packages/*​/*` glob — the estate's normal spelling.
 *
 * @param  array<string, array{require?: array<string,string>, require-dev?: array<string,string>, origin?: string}>  $packages
 * @param  array<string, mixed>  $rootManifest
 */
function transitiveRoot(string $label, array $rootManifest, array $packages, ?array $overlay = null): string
{
    $base = surgeon_tmp('transitive-'.$label);
    $root = $base.'/root';
    mkdir($root, 0755, true);

    foreach ($packages as $name => $spec) {
        $dir = $base.'/packages/'.$name;
        surgeon_write($dir.'/composer.json', json_encode([
            'name' => $name,
            'require' => $spec['require'] ?? [],
            'require-dev' => $spec['require-dev'] ?? [],
        ], JSON_PRETTY_PRINT));

        if (isset($spec['origin'])) {
            surgeon_write($dir.'/.git/config', "[remote \"origin\"]\n\turl = ".$spec['origin']."\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n");
        }
    }

    $rootManifest['repositories'] = array_merge(
        [['type' => 'path', 'url' => '../packages/*/*']],
        $rootManifest['repositories'] ?? [],
    );
    surgeon_write($root.'/composer.json', json_encode($rootManifest, JSON_PRETTY_PRINT));

    if ($overlay !== null) {
        surgeon_write($root.'/composer.local.json', json_encode($overlay, JSON_PRETTY_PRINT));
    }

    return $root;
}

/** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
function transitiveFindings(string $root, DoctorStatus $status): array
{
    return array_values(array_filter(
        (new TransitiveSupplyAudit($root))->suggestOperations(),
        fn ($f) => $f->finding->status === $status,
    ));
}

it('flags a private package reached only TRANSITIVELY, which the declaration audit reads green', function () {
    $root = transitiveRoot('transitive', ['name' => 'acme/host', 'require' => ['acme/direct' => '*']], [
        'acme/direct' => ['require' => ['acme/deep' => '*'], 'origin' => 'https://github.com/org/direct.git'],
        'acme/deep' => ['require' => [], 'origin' => 'https://github.com/org/deep.git'],
    ], overlay: null);

    try {
        // The root supplies the direct require and nothing else.
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true);
        $manifest['repositories'][] = ['type' => 'vcs', 'url' => 'https://github.com/org/direct.git'];
        file_put_contents($root.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $warnings = transitiveFindings($root, DoctorStatus::Warn);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->check)->toBe(TransitiveSupplyAudit::CHECK)
            ->and($warnings[0]->finding->detail)->toContain('acme/deep')
            ->and($warnings[0]->finding->detail)->not->toContain('acme/direct,')
            // The seed line is the repair, and its url comes from the checkout's real origin remote.
            ->and($warnings[0]->suggestion?->payload['seed'])
            ->toBe(['composer config repositories.deep vcs https://github.com/org/deep.git']);
    } finally {
        surgeon_rrmdir(dirname($root));
    }
});

it('does NOT follow a dependency\'s require-dev — the single difference between usable and unusable', function () {
    $root = transitiveRoot('devwalk', ['name' => 'acme/host', 'require' => ['acme/direct' => '*']], [
        // Composer never resolves a dependency's dev requires, so acme/toolkit is not in the closure.
        'acme/direct' => ['require' => [], 'require-dev' => ['acme/toolkit' => '*'], 'origin' => 'https://github.com/org/direct.git'],
        'acme/toolkit' => ['require' => [], 'origin' => 'https://github.com/org/toolkit.git'],
    ]);

    try {
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true);
        $manifest['repositories'][] = ['type' => 'vcs', 'url' => 'https://github.com/org/direct.git'];
        file_put_contents($root.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

        expect(transitiveFindings($root, DoctorStatus::Warn))->toBeEmpty();
    } finally {
        surgeon_rrmdir(dirname($root));
    }
});

it('honours the ROOT\'s own require-dev, which composer does resolve', function () {
    $root = transitiveRoot('rootdev', ['name' => 'acme/host', 'require-dev' => ['acme/tool' => '*']], [
        'acme/tool' => ['require' => [], 'origin' => 'https://github.com/org/tool.git'],
    ]);

    try {
        $warnings = transitiveFindings($root, DoctorStatus::Warn);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->finding->detail)->toContain('acme/tool');
    } finally {
        surgeon_rrmdir(dirname($root));
    }
});

it('reads a name-keyed `repositories` object, not just a list', function () {
    $root = transitiveRoot('objectkeyed', [
        'name' => 'acme/host',
        'require' => ['acme/direct' => '*'],
        'repositories' => [],
    ], [
        'acme/direct' => ['require' => [], 'origin' => 'https://github.com/org/direct.git'],
    ]);

    try {
        // Re-shape `repositories` into the object form composer also accepts.
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true);
        $manifest['repositories'] = [
            'locals' => ['type' => 'path', 'url' => '../packages/*/*'],
            'direct' => ['type' => 'vcs', 'url' => 'https://github.com/org/direct.git'],
        ];
        file_put_contents($root.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Object-keyed on BOTH halves: the path repo still builds the index, the vcs entry still supplies.
        expect(transitiveFindings($root, DoctorStatus::Warn))->toBeEmpty();
    } finally {
        surgeon_rrmdir(dirname($root));
    }
});

it('names the mode on every run — including, especially, when it finds nothing', function () {
    $clean = transitiveRoot('mode-absent', ['name' => 'acme/host', 'require' => []], [
        'acme/direct' => ['require' => []],
    ]);
    $overlaid = transitiveRoot('mode-present', ['name' => 'acme/host', 'require' => []], [
        'acme/direct' => ['require' => []],
    ], overlay: ['require' => []]);

    try {
        $absent = (new TransitiveSupplyAudit($clean))->suggestOperations();
        $present = (new TransitiveSupplyAudit($overlaid))->suggestOperations();

        expect($absent[0]->finding->status)->toBe(DoctorStatus::Pass)
            ->and($absent[0]->finding->detail)->toContain('MODE: overlay-absent')
            ->and($absent[0]->finding->detail)->toContain('SHIPPABLE closure')
            ->and($present[0]->finding->detail)->toContain('MODE: overlay-present (composer.local.json)')
            ->and($present[0]->finding->detail)->toContain('CO-DEV closure');
    } finally {
        surgeon_rrmdir(dirname($clean));
        surgeon_rrmdir(dirname($overlaid));
    }
});

it('states that it is an approximation in BOTH directions, on clean runs and on findings alike', function () {
    $clean = transitiveRoot('caveat-clean', ['name' => 'acme/host', 'require' => []], [
        'acme/direct' => ['require' => []],
    ]);
    $dirty = transitiveRoot('caveat-dirty', ['name' => 'acme/host', 'require' => ['acme/direct' => '*']], [
        'acme/direct' => ['require' => [], 'origin' => 'https://github.com/org/direct.git'],
    ]);

    try {
        $cleanDetail = (new TransitiveSupplyAudit($clean))->suggestOperations()[0]->finding->detail;
        $dirtyDetail = transitiveFindings($dirty, DoctorStatus::Warn)[0]->finding->detail;

        foreach ([$cleanDetail, $dirtyDetail] as $detail) {
            expect($detail)->toContain('wrong in both directions')
                ->and($detail)->toContain('over-reports')
                ->and($detail)->toContain('under-reports')
                ->and($detail)->toContain('does NOT mean this root resolves');
        }
    } finally {
        surgeon_rrmdir(dirname($clean));
        surgeon_rrmdir(dirname($dirty));
    }
});

it('leaves the declaration audit standing but strips its resolvability reading', function () {
    expect(RequireWithoutSupplyAudit::notChecked())
        ->toContain('TRANSITIVE closure')
        ->toContain('does NOT mean this root resolves')
        ->toContain(TransitiveSupplyAudit::CHECK);
});

it('ships on the built-in channel', function () {
    $classes = array_map(fn ($audit) => $audit::class, (new BuiltInAudits(__DIR__))->audits());

    expect($classes)->toContain(TransitiveSupplyAudit::class);
});
