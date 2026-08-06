<?php

use Rushing\Surgeon\Audit\PackageGraph;

it('resolves the owning package of a root and of an FQN by longest PSR-4 prefix', function () {
    $roots = [dirname(__DIR__).'/Fixtures/graph/app', dirname(__DIR__).'/Fixtures/graph/low'];
    $graph = PackageGraph::fromRoots($roots);

    expect($graph->packageForRoot($roots[0]))->toBe('acme/app')
        ->and($graph->packageForRoot($roots[1]))->toBe('acme/low')
        ->and($graph->packageForFqn('Acme\\App\\Thing'))->toBe('acme/app')
        ->and($graph->packageForFqn('Acme\\Low\\Nested\\Deep'))->toBe('acme/low')
        ->and($graph->packageForFqn('Unknown\\Thing'))->toBeNull();
});

it('answers reachability directionally over require edges', function () {
    $roots = [dirname(__DIR__).'/Fixtures/graph/app', dirname(__DIR__).'/Fixtures/graph/low'];
    $graph = PackageGraph::fromRoots($roots);

    // app requires low; low requires nothing.
    expect($graph->reaches('acme/app', 'acme/low'))->toBeTrue()
        ->and($graph->reaches('acme/low', 'acme/app'))->toBeFalse()
        ->and($graph->reaches('acme/app', 'acme/app'))->toBeTrue();
});
