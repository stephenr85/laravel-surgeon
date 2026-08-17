<?php

use Rushing\Surgeon\Rewrite\FindingSetLoader;

/**
 * A finding-set records each reference's path RELATIVE to its root. Across multiple roots those
 * relative paths are not unique — two sibling packages routinely both have
 * `src/Entitlements/EntitlementGate.php` — so resolving by probing the root list in order silently
 * binds the plan to whichever root happens to be listed first.
 *
 * That is not a theoretical case: it is how the ExternalCapability → GatedCapability move failed. The
 * plan was built against `laravel-beam`'s copy of a file it had actually audited in
 * `laravel-beam-commerce`. The splice drift-guard refused to write, so nothing was corrupted — but the
 * refusal came after the whole move was planned against the wrong file, and reads as a stale plan
 * rather than the root collision it was.
 *
 * Each reference carries its OWN root. That is the authoritative answer and these pin that it is used.
 */
function surgeon_collision_set(string $label, string $firstRootHasFile): array
{
    $a = surgeon_tmp('collide-a-'.$label);
    $b = surgeon_tmp('collide-b-'.$label);

    // The SAME relative path in both roots, with different contents.
    if ($firstRootHasFile) {
        surgeon_write($a.'/src/Gate.php', "<?php\n\nnamespace A;\n\nclass Gate {} // DECOY\n");
    }
    surgeon_write($b.'/src/Gate.php', "<?php\n\nnamespace B;\n\nclass Gate {} // REAL\n");

    return [$a, $b];
}

it('resolves a reference to its own recorded root, not the first root that happens to match', function () {
    [$a, $b] = surgeon_collision_set('own-root', firstRootHasFile: true);

    $set = surgeon_tmp('collide-set-own-root').'/set.json';
    surgeon_write($set, json_encode([
        'target' => ['kind' => 'symbol', 'value' => 'B\\Gate', 'new' => 'B\\RenamedGate'],
        // Root A is listed FIRST and also contains src/Gate.php — the collision.
        'roots' => [$a, $b],
        'references' => [[
            'category' => 'namespace-decl',
            'root' => $b,                 // ← the audit saw B's copy
            'file' => 'src/Gate.php',
            'line' => 3,
            'matched' => 'B',
            'span' => [17, 17],
            'snippet' => 'namespace B;',
            'new' => 'B',
        ]],
    ]));

    $loaded = FindingSetLoader::load($set);
    $refs = $loaded['report']->references;

    expect($refs)->toHaveCount(1)
        ->and($refs[0]->file)->toBe($b.'/src/Gate.php')
        ->and($refs[0]->root)->toBe($b)
        ->and(file_get_contents($refs[0]->file))->toContain('REAL')
        ->and(file_get_contents($refs[0]->file))->not->toContain('DECOY');
});

/**
 * Probing survives for finding-sets written before `root` was recorded per reference, and for a tree
 * relocated wholesale between trace and move — so an absent/stale root must not become a hard failure.
 */
it('falls back to probing the root list when the reference records no root', function () {
    [$a, $b] = surgeon_collision_set('no-root', firstRootHasFile: false);

    $set = surgeon_tmp('collide-set-no-root').'/set.json';
    surgeon_write($set, json_encode([
        'target' => ['kind' => 'symbol', 'value' => 'B\\Gate', 'new' => 'B\\RenamedGate'],
        'roots' => [$a, $b],
        'references' => [[
            'category' => 'namespace-decl',
            'file' => 'src/Gate.php',     // no 'root' key at all
            'line' => 3,
            'matched' => 'B',
            'span' => [17, 17],
            'snippet' => 'namespace B;',
            'new' => 'B',
        ]],
    ]));

    $refs = FindingSetLoader::load($set)['report']->references;

    expect($refs)->toHaveCount(1)
        ->and($refs[0]->file)->toBe($b.'/src/Gate.php');
});

it('falls back to probing when the recorded root no longer exists', function () {
    [$a, $b] = surgeon_collision_set('stale-root', firstRootHasFile: false);

    $set = surgeon_tmp('collide-set-stale-root').'/set.json';
    surgeon_write($set, json_encode([
        'target' => ['kind' => 'symbol', 'value' => 'B\\Gate', 'new' => 'B\\RenamedGate'],
        'roots' => [$a, $b],
        'references' => [[
            'category' => 'namespace-decl',
            'root' => '/nonexistent/moved/away',
            'file' => 'src/Gate.php',
            'line' => 3,
            'matched' => 'B',
            'span' => [17, 17],
            'snippet' => 'namespace B;',
            'new' => 'B',
        ]],
    ]));

    $refs = FindingSetLoader::load($set)['report']->references;

    expect($refs)->toHaveCount(1)
        ->and($refs[0]->file)->toBe($b.'/src/Gate.php');
});
