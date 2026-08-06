<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Rewrite\DestinationDepAdvisor;
use Rushing\Surgeon\Rewrite\GitRoot;
use Rushing\Surgeon\Rewrite\Psr4Resolver;
use Rushing\Surgeon\Rewrite\RewritePlanner;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * The ticket-14 fixture: TWO separate git repos.
 *
 *  - a "source app" root (`App\ => app/`) holding `App\Scratch\Widget` + a `Consumer` that imports it;
 *  - a "destination package" root (`Vendor\Pkg\ => src/`) the class is promoted INTO — with a
 *    `composer.json` that requires nothing, so a moved import can dangle (the Tier-3 advisory case).
 *
 * @return array{app: string, pkg: string}
 */
function surgeon_two_repos(string $label): array
{
    $app = surgeon_tmp('xrepo-app-'.$label);
    surgeon_write($app.'/composer.json', json_encode([
        'name' => 'acme/app',
        'require' => ['laravel/mcp' => '^0.8'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($app.'/app/Scratch/Widget.php', <<<'PHP'
        <?php

        namespace App\Scratch;

        use Laravel\Mcp\Server;

        class Widget
        {
            public function server(): string
            {
                return Server::class;
            }
        }

        PHP);
    surgeon_write($app.'/app/Consumer.php', <<<'PHP'
        <?php

        namespace App;

        use App\Scratch\Widget;

        class Consumer
        {
            public function __construct(private Widget $widget) {}

            public function name(): string
            {
                return Widget::class;
            }
        }

        PHP);
    surgeon_git_init($app);

    $pkg = surgeon_tmp('xrepo-pkg-'.$label);
    surgeon_write($pkg.'/composer.json', json_encode([
        'name' => 'vendor/pkg',
        'require' => new stdClass, // requires NOTHING — the moved Laravel\Mcp import will dangle.
        'autoload' => ['psr-4' => ['Vendor\\Pkg\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($pkg.'/src/.gitkeep', '');
    surgeon_git_init($pkg);

    return ['app' => $app, 'pkg' => $pkg];
}

it('detects same-repo vs different-repo by walking to each .git (GitRoot)', function () {
    $repos = surgeon_two_repos('detect');

    try {
        $srcFile = $repos['app'].'/app/Scratch/Widget.php';
        $sibling = $repos['app'].'/app/Consumer.php';
        $destDir = $repos['pkg'].'/src/Widget.php';

        // Two files in the SAME repo resolve to one git root.
        expect(GitRoot::sameRepo($srcFile, $sibling))->toBeTrue()
            ->and(GitRoot::of($srcFile))->toBe(GitRoot::of($sibling));

        // A file in the app repo and a (not-yet-existing) path in the package repo are DIFFERENT repos.
        expect(GitRoot::sameRepo($srcFile, $destDir))->toBeFalse()
            ->and(GitRoot::of($srcFile))->not->toBe(GitRoot::of($destDir));

        // A path outside any repo has no git root.
        expect(GitRoot::of('/definitely/not/a/repo/x.php'))->toBeNull();
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('plans a cross-repo relocation: destination resolves into the OTHER root, mode is cross-repo', function () {
    $repos = surgeon_two_repos('plan');

    try {
        $report = (new AuditEngine)->audit(
            [$repos['app'], $repos['pkg']],
            Target::relocatingTo('App\\Scratch\\Widget', 'Vendor\\Pkg\\Widget'),
        );
        $plan = (new RewritePlanner(new Psr4Resolver([$repos['app'], $repos['pkg']])))->plan($report);

        expect($plan->move)->not->toBeNull()
            ->and($plan->move->crossRepo)->toBeTrue()
            ->and($plan->move->fromRelative)->toBe('app/Scratch/Widget.php')
            ->and($plan->move->toRelative)->toBe('src/Widget.php')
            ->and($plan->move->sourceRepo)->toBe(GitRoot::of($repos['app']))
            ->and($plan->move->destinationRepo)->toBe(GitRoot::of($repos['pkg']));
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('applies a cross-repo relocation: lands in the package, gone from the app, refs repointed, then rolls both back exactly', function () {
    $repos = surgeon_two_repos('apply');

    try {
        $before = [
            'widget' => file_get_contents($repos['app'].'/app/Scratch/Widget.php'),
            'consumer' => file_get_contents($repos['app'].'/app/Consumer.php'),
        ];

        $report = (new AuditEngine)->audit(
            [$repos['app'], $repos['pkg']],
            Target::relocatingTo('App\\Scratch\\Widget', 'Vendor\\Pkg\\Widget'),
        );
        $plan = (new RewritePlanner(new Psr4Resolver([$repos['app'], $repos['pkg']])))->plan($report);

        $applier = new SpliceApplier;
        $manifest = $applier->apply($plan);

        // The file landed at the destination PSR-4 path in the OTHER repo, with the rewritten namespace.
        expect(is_file($repos['pkg'].'/src/Widget.php'))->toBeTrue()
            ->and(file_get_contents($repos['pkg'].'/src/Widget.php'))->toContain('namespace Vendor\\Pkg;')
            ->and(is_file($repos['app'].'/app/Scratch/Widget.php'))->toBeFalse();

        // The app-side reference is repointed to the new home.
        $consumer = file_get_contents($repos['app'].'/app/Consumer.php');
        expect($consumer)->toContain('use Vendor\\Pkg\\Widget;')
            ->and($consumer)->not->toContain('App\\Scratch\\Widget');

        // The moved file at its new home still parses.
        $lint = Process::run([PHP_BINARY, '-l', $repos['pkg'].'/src/Widget.php']);
        expect($lint->successful())->toBeTrue();

        // The manifest spans BOTH repos.
        expect($manifest->move['crossRepo'])->toBeTrue()
            ->and($manifest->gateRoots([$repos['app']]))->toContain(GitRoot::of($repos['pkg']));

        // --- Rollback restores BOTH repos to exactly the pre-move state. ---
        $applier->rollback($manifest);

        expect(is_file($repos['pkg'].'/src/Widget.php'))->toBeFalse()
            ->and(is_file($repos['app'].'/app/Scratch/Widget.php'))->toBeTrue()
            ->and(file_get_contents($repos['app'].'/app/Scratch/Widget.php'))->toBe($before['widget'])
            ->and(file_get_contents($repos['app'].'/app/Consumer.php'))->toBe($before['consumer']);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('surfaces a dangling destination dependency as a Tier-3 advisory (does not edit composer)', function () {
    $repos = surgeon_two_repos('advisory');

    try {
        // Simulate the moved file already at its destination (imports Laravel\Mcp, which vendor/pkg
        // does not require).
        surgeon_write($repos['pkg'].'/src/Widget.php', <<<'PHP'
            <?php

            namespace Vendor\Pkg;

            use Laravel\Mcp\Server;

            class Widget
            {
                public function server(): string
                {
                    return Server::class;
                }
            }

            PHP);

        $advisories = (new DestinationDepAdvisor)->advise($repos['pkg'].'/src/Widget.php', $repos['pkg']);

        expect($advisories)->toHaveCount(1)
            ->and($advisories[0])->toContain('Laravel')
            ->and($advisories[0])->toContain('vendor/pkg does not require');

        // composer.json is untouched — surgeon flags, never edits (the deterministic/agentic seam).
        expect(file_get_contents($repos['pkg'].'/composer.json'))->not->toContain('laravel/mcp');
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('does NOT flag a dependency the destination already requires', function () {
    $repos = surgeon_two_repos('nodangle');

    try {
        // The destination now requires laravel/mcp — the same moved import must NOT advise.
        surgeon_write($repos['pkg'].'/composer.json', json_encode([
            'name' => 'vendor/pkg',
            'require' => ['laravel/mcp' => '^0.8'],
            'autoload' => ['psr-4' => ['Vendor\\Pkg\\' => 'src/']],
        ], JSON_PRETTY_PRINT));
        surgeon_write($repos['pkg'].'/src/Widget.php', "<?php\n\nnamespace Vendor\\Pkg;\n\nuse Laravel\\Mcp\\Server;\n\nclass Widget { public \$s = Server::class; }\n");

        expect((new DestinationDepAdvisor)->advise($repos['pkg'].'/src/Widget.php', $repos['pkg']))->toBe([]);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('resolves a required package real PSR-4 root from installed.json (not the Studly-of-vendor guess)', function () {
    $repos = surgeon_two_repos('installed-psr4');

    try {
        // guzzlehttp/guzzle autoloads GuzzleHttp\ — Studly(guzzlehttp) would wrongly guess "Guzzlehttp"
        // and FALSE-flag the import. Reading composer's installed.json resolves the real root, so a
        // GuzzleHttp\Client import against a required guzzlehttp/guzzle must NOT advise.
        surgeon_write($repos['pkg'].'/composer.json', json_encode([
            'name' => 'vendor/pkg',
            'require' => ['guzzlehttp/guzzle' => '^7.0'],
            'autoload' => ['psr-4' => ['Vendor\\Pkg\\' => 'src/']],
        ], JSON_PRETTY_PRINT));
        surgeon_write($repos['pkg'].'/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'guzzlehttp/guzzle', 'autoload' => ['psr-4' => ['GuzzleHttp\\' => 'src/']]],
            ],
        ], JSON_PRETTY_PRINT));
        surgeon_write($repos['pkg'].'/src/Widget.php', "<?php\n\nnamespace Vendor\\Pkg;\n\nuse GuzzleHttp\\Client;\n\nclass Widget { public \$c = Client::class; }\n");

        expect((new DestinationDepAdvisor)->advise($repos['pkg'].'/src/Widget.php', $repos['pkg']))->toBe([]);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('falls back to the installed package composer.json when installed.json is absent', function () {
    $repos = surgeon_two_repos('vendor-composer');

    try {
        // No installed.json — the advisor reads vendor/<name>/composer.json directly for the real root.
        surgeon_write($repos['pkg'].'/composer.json', json_encode([
            'name' => 'vendor/pkg',
            'require' => ['guzzlehttp/guzzle' => '^7.0'],
            'autoload' => ['psr-4' => ['Vendor\\Pkg\\' => 'src/']],
        ], JSON_PRETTY_PRINT));
        surgeon_write($repos['pkg'].'/vendor/guzzlehttp/guzzle/composer.json', json_encode([
            'name' => 'guzzlehttp/guzzle',
            'autoload' => ['psr-4' => ['GuzzleHttp\\' => 'src/']],
        ], JSON_PRETTY_PRINT));
        surgeon_write($repos['pkg'].'/src/Widget.php', "<?php\n\nnamespace Vendor\\Pkg;\n\nuse GuzzleHttp\\Client;\n\nclass Widget { public \$c = Client::class; }\n");

        expect((new DestinationDepAdvisor)->advise($repos['pkg'].'/src/Widget.php', $repos['pkg']))->toBe([]);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});

it('runs the full surgeon:move across both repos under --apply (end-to-end command)', function () {
    $repos = surgeon_two_repos('cmd');

    try {
        $report = (new AuditEngine)->audit(
            [$repos['app'], $repos['pkg']],
            Target::relocatingTo('App\\Scratch\\Widget', 'Vendor\\Pkg\\Widget'),
        );
        // Finding-set + manifest live OUTSIDE either repo so the clean-tree gate sees pristine trees.
        $scratch = surgeon_tmp('xrepo-cmd-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$repos['app'], $repos['pkg']],
            '--manifest' => $scratch.'/manifest.json',
            '--no-composer' => true,
        ])->assertExitCode(Command::SUCCESS);

        expect(is_file($repos['pkg'].'/src/Widget.php'))->toBeTrue()
            ->and(is_file($repos['app'].'/app/Scratch/Widget.php'))->toBeFalse()
            ->and(file_get_contents($repos['app'].'/app/Consumer.php'))->toContain('use Vendor\\Pkg\\Widget;');

        $sidecar = json_decode(file_get_contents($scratch.'/manifest.json'), true);
        expect($sidecar['move']['to'])->toBe('src/Widget.php');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repos['app']);
        surgeon_rrmdir($repos['pkg']);
    }
});
