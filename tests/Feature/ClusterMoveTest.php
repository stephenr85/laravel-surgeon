<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\MovingSet;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Rewrite\Psr4Resolver;
use Rushing\Surgeon\Rewrite\RewritePlanner;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * ATOMIC CLUSTER-MOVE — the dogfooding gap: a cohesive set of symbols that relocate TOGETHER must not
 * false-positive on the cycle-guard. Tracing each member in isolation flags every sibling reference as
 * an upward edge (the source package still owns the sibling at audit time); but because ALL siblings
 * land in the destination together, the post-move graph is destination-internal and legal.
 *
 * The fixture models the exact shape: TWO packages where the destination already reaches the source —
 *
 *  - `acme/app` (the DESTINATION) `require`s `acme/low`;
 *  - `acme/low` (the SOURCE) holds a cohesive cluster `Acme\Low\Cluster\{Alpha, Beta}` that reference
 *    each other, PLUS a non-member `Acme\Low\Outsider` that references the cluster.
 *
 * Moving the cluster UP into `acme/app` is the false-positive trigger: a lone trace of `Alpha` would
 * flag its reference to `Beta` (low → app looks upward) even though Beta moves too. The moving-set
 * reframes cycle-risk against the post-move package of both endpoints.
 *
 * @return array{app: string, low: string}
 */
function surgeon_cluster_repos(string $label): array
{
    $app = surgeon_tmp('cluster-app-'.$label);
    surgeon_write($app.'/composer.json', json_encode([
        'name' => 'acme/app',
        'require' => ['acme/low' => '*'], // app already reaches low — moving UP would look upward.
        'autoload' => ['psr-4' => ['Acme\\App\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($app.'/src/.gitkeep', '');

    $low = surgeon_tmp('cluster-low-'.$label);
    surgeon_write($low.'/composer.json', json_encode([
        'name' => 'acme/low',
        'autoload' => ['psr-4' => ['Acme\\Low\\' => 'src/']],
    ], JSON_PRETTY_PRINT));

    // The cohesive cluster: Alpha ↔ Beta reference each other (the intra-set edges).
    surgeon_write($low.'/src/Cluster/Alpha.php', <<<'PHP'
        <?php

        namespace Acme\Low\Cluster;

        use Acme\Low\Cluster\Beta;

        class Alpha
        {
            public function pair(): Beta
            {
                return new Beta;
            }
        }

        PHP);
    surgeon_write($low.'/src/Cluster/Beta.php', <<<'PHP'
        <?php

        namespace Acme\Low\Cluster;

        use Acme\Low\Cluster\Alpha;

        class Beta
        {
            public function pair(): Alpha
            {
                return new Alpha;
            }
        }

        PHP);

    // A non-member that STAYS in acme/low but references a cluster member — the outside-in edge that
    // must still be tier-checked (it becomes low → app, an upward composer edge).
    surgeon_write($low.'/src/Outsider.php', <<<'PHP'
        <?php

        namespace Acme\Low;

        use Acme\Low\Cluster\Alpha;

        class Outsider
        {
            public function use(Alpha $alpha): void {}
        }

        PHP);

    return ['app' => $app, 'low' => $low];
}

it('does NOT flag intra-cluster references as cycle-risk, but DOES flag an outside referrer (post-move graph)', function () {
    $repos = surgeon_cluster_repos('audit');

    try {
        $roots = [$repos['app'], $repos['low']];
        $graph = PackageGraph::fromRoots($roots);

        // The whole cohesive cluster moves together into acme/app.
        $movingSet = MovingSet::of(
            symbols: ['Acme\\Low\\Cluster\\Alpha', 'Acme\\Low\\Cluster\\Beta'],
            namespaces: [],
            destination: 'Acme\\App\\Cluster',
        );

        $report = (new AuditEngine)->audit($roots, $movingSet->toTarget(), $graph, $movingSet);

        // Partition the found references by whether they live in a moving member's own file.
        $intraCluster = [];   // referrer is Alpha/Beta (itself moving)
        $outsideIn = [];      // referrer is Outsider (stays put)
        foreach ($report->references() as $ref) {
            if (str_contains($ref->relativePath, 'Cluster/')) {
                $intraCluster[] = $ref;
            } elseif (str_contains($ref->relativePath, 'Outsider')) {
                $outsideIn[] = $ref;
            }
        }

        // (a) Intra-cluster references (Alpha→Beta, Beta→Alpha) are ordinary repoints — NEVER cycle-risk.
        expect($intraCluster)->not->toBeEmpty();
        foreach ($intraCluster as $ref) {
            expect($ref->hasCycleRisk())->toBeFalse();
        }

        // (b) The outside referrer (Outsider, staying in acme/low) referencing a moving member IS still
        // tier-checked — it becomes a genuine low → app upward edge.
        expect($outsideIn)->not->toBeEmpty()
            ->and(collect($outsideIn)->contains(fn ($r) => $r->hasCycleRisk()))->toBeTrue();

        // The report as a whole therefore still surfaces the real cycle risk (the outside edge), while
        // the atomic-cluster edges are clean.
        expect($report->cycleRisks())->not->toBeEmpty();
        foreach ($report->cycleRisks() as $risk) {
            expect($risk->relativePath)->toContain('Outsider');
        }

        // CONTROL: without the moving-set context (tracing Alpha in ISOLATION, the pre-fix behaviour),
        // Alpha's reference to Beta IS flagged cycle-risk — proving the gap was real and the moving-set
        // is what closes it.
        $lone = (new AuditEngine)->audit(
            $roots,
            Target::relocatingTo('Acme\\Low\\Cluster\\Alpha', 'Acme\\App\\Cluster\\Alpha'),
            $graph,
        );
        $betaRefInAlpha = collect($lone->references())
            ->first(fn ($r) => str_contains($r->relativePath, 'Beta.php'));
        expect($betaRefInAlpha)->not->toBeNull()
            ->and($betaRefInAlpha->hasCycleRisk())->toBeTrue();
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['low']);
    }
});

it('plans the whole cluster as normal repoints + one physical move PER member (no skips for intra-set)', function () {
    $repos = surgeon_cluster_repos('plan');

    try {
        $roots = [$repos['app'], $repos['low']];
        $graph = PackageGraph::fromRoots($roots);
        $movingSet = MovingSet::of(['Acme\\Low\\Cluster\\Alpha', 'Acme\\Low\\Cluster\\Beta'], [], 'Acme\\App\\Cluster');

        $report = (new AuditEngine)->audit($roots, $movingSet->toTarget(), $graph, $movingSet);
        $plan = (new RewritePlanner(new Psr4Resolver($roots)))->plan($report);

        expect($plan->isUnsupported())->toBeFalse()
            ->and($plan->moves)->toHaveCount(2); // Alpha.php AND Beta.php both relocate.

        // Both members land under acme/app's src/Cluster.
        $destinations = array_map(fn ($m) => $m->toRelative, $plan->moves);
        sort($destinations);
        expect($destinations)->toBe(['src/Cluster/Alpha.php', 'src/Cluster/Beta.php']);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['low']);
    }
});

it('applies the atomic cluster: relocates ALL members and repoints references cleanly', function () {
    $repos = surgeon_cluster_repos('apply');

    try {
        $roots = [$repos['app'], $repos['low']];
        $graph = PackageGraph::fromRoots($roots);
        $movingSet = MovingSet::of(['Acme\\Low\\Cluster\\Alpha', 'Acme\\Low\\Cluster\\Beta'], [], 'Acme\\App\\Cluster');

        $report = (new AuditEngine)->audit($roots, $movingSet->toTarget(), $graph, $movingSet);
        $plan = (new RewritePlanner(new Psr4Resolver($roots)))->plan($report);

        $manifest = (new SpliceApplier)->apply($plan);

        // BOTH members landed in acme/app with the rewritten namespace, and are gone from acme/low.
        expect(is_file($repos['app'].'/src/Cluster/Alpha.php'))->toBeTrue()
            ->and(is_file($repos['app'].'/src/Cluster/Beta.php'))->toBeTrue()
            ->and(is_file($repos['low'].'/src/Cluster/Alpha.php'))->toBeFalse()
            ->and(is_file($repos['low'].'/src/Cluster/Beta.php'))->toBeFalse();

        $alpha = file_get_contents($repos['app'].'/src/Cluster/Alpha.php');
        expect($alpha)->toContain('namespace Acme\\App\\Cluster;')
            ->and($alpha)->toContain('use Acme\\App\\Cluster\\Beta;')  // intra-cluster edge repointed
            ->and($alpha)->not->toContain('Acme\\Low\\Cluster\\Beta');

        // The outside referrer, still in acme/low, is repointed at the new home.
        $outsider = file_get_contents($repos['low'].'/src/Outsider.php');
        expect($outsider)->toContain('use Acme\\App\\Cluster\\Alpha;')
            ->and($outsider)->not->toContain('Acme\\Low\\Cluster\\Alpha');

        // Both relocated files still parse.
        foreach (['Alpha', 'Beta'] as $member) {
            $lint = Process::run([PHP_BINARY, '-l', $repos['app'].'/src/Cluster/'.$member.'.php']);
            expect($lint->successful())->toBeTrue();
        }

        // Rollback restores both repos exactly.
        (new SpliceApplier)->rollback($manifest);
        expect(is_file($repos['low'].'/src/Cluster/Alpha.php'))->toBeTrue()
            ->and(is_file($repos['low'].'/src/Cluster/Beta.php'))->toBeTrue()
            ->and(is_file($repos['app'].'/src/Cluster/Alpha.php'))->toBeFalse()
            ->and(is_file($repos['app'].'/src/Cluster/Beta.php'))->toBeFalse();
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['low']);
    }
});

it('runs the full surgeon:move for a cluster end-to-end (repeatable --symbol + shared --to)', function () {
    $repos = surgeon_cluster_repos('cmd');

    try {
        $roots = [$repos['app'], $repos['low']];

        // Trace the cluster to a finding-set (the resolved operation set the mover consumes).
        $scratch = surgeon_tmp('cluster-cmd-scratch');
        $from = $scratch.'/finding-set.json';

        $this->artisan('surgeon:trace', [
            '--symbol' => ['Acme\\Low\\Cluster\\Alpha', 'Acme\\Low\\Cluster\\Beta'],
            '--to' => 'Acme\\App\\Cluster',
            '--root' => $roots,
            '--out' => $from,
        ]); // exit code carries cycle-risk (the Outsider edge) — not asserted here.

        expect(is_file($from))->toBeTrue();

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => $roots,
            '--manifest' => $scratch.'/manifest.json',
            '--no-composer' => true,
            '--allow-dirty' => true, // temp trees are not git repos here.
        ])->assertExitCode(Command::SUCCESS);

        expect(is_file($repos['app'].'/src/Cluster/Alpha.php'))->toBeTrue()
            ->and(is_file($repos['app'].'/src/Cluster/Beta.php'))->toBeTrue()
            ->and(is_file($repos['low'].'/src/Cluster/Alpha.php'))->toBeFalse()
            ->and(file_get_contents($repos['low'].'/src/Outsider.php'))->toContain('use Acme\\App\\Cluster\\Alpha;');

        // The sidecar records BOTH physical moves.
        $sidecar = json_decode(file_get_contents($scratch.'/manifest.json'), true);
        expect($sidecar['moves'])->toHaveCount(2);

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['low']);
    }
});
