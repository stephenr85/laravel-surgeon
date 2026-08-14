<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\Target;

/**
 * The preview's rendering of a physical move, for the case that reads as a lie: a CROSS-REPO move
 * whose two ends share the same root-relative path.
 *
 * `fromRelative`/`toRelative` are root-relative, so promoting `Vendor\A\Models\Thing` (in package A,
 * at `src/Models/Thing.php`) to `Vendor\B\Models\Thing` (in package B, also at `src/Models/Thing.php`)
 * printed as:
 *
 *     src/Models/Thing.php  →  src/Models/Thing.php
 *
 * — indistinguishable from a no-op, on the one operation where a reviewer most needs to know which
 * tree each side lives in. The move itself was always correct; only the preview lied, which is worse
 * than an outright failure because it invites a human to "fix" the tool by hand.
 *
 * The existing ticket-14 fixtures all move `app/Scratch/Widget.php` → `src/Widget.php`, whose ends
 * differ, which is why this went unnoticed. This fixture makes the paths collide on purpose.
 *
 * @return array{a: string, b: string}
 */
function surgeon_twin_path_repos(string $label): array
{
    $a = surgeon_tmp('twinpath-a-'.$label);
    surgeon_write($a.'/composer.json', json_encode([
        'name' => 'vendor/a',
        'autoload' => ['psr-4' => ['Vendor\\A\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($a.'/src/Models/Thing.php', <<<'PHP'
        <?php

        namespace Vendor\A\Models;

        class Thing {}

        PHP);
    surgeon_write($a.'/src/Consumer.php', <<<'PHP'
        <?php

        namespace Vendor\A;

        use Vendor\A\Models\Thing;

        class Consumer
        {
            public function name(): string
            {
                return Thing::class;
            }
        }

        PHP);
    surgeon_git_init($a);

    $b = surgeon_tmp('twinpath-b-'.$label);
    surgeon_write($b.'/composer.json', json_encode([
        'name' => 'vendor/b',
        'autoload' => ['psr-4' => ['Vendor\\B\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($b.'/src/.gitkeep', '');
    surgeon_git_init($b);

    return ['a' => $a, 'b' => $b];
}

it('renders a same-relative-path cross-repo move repo-qualified, not as a no-op', function () {
    $repos = surgeon_twin_path_repos('render');

    try {
        $report = (new AuditEngine)->audit(
            [$repos['a'], $repos['b']],
            Target::relocatingTo('Vendor\\A\\Models\\Thing', 'Vendor\\B\\Models\\Thing'),
        );
        $scratch = surgeon_tmp('twinpath-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Preview only — the rendering is what is under test. Assert on the captured output rather
        // than chained expectsOutputToContain(), so the failure message shows the actual line.
        Artisan::call('surgeon:move', ['--from' => $from, '--root' => [$repos['a'], $repos['b']]]);
        $output = Artisan::output();

        $line = collect(explode("\n", $output))->first(fn ($l) => str_contains($l, '→') && str_contains($l, 'Thing.php'));

        expect($line)->not->toBeNull()
            ->and($line)->toContain('[cross-repo]')
            ->and($line)->toContain(basename($repos['a']).'/src/Models/Thing.php')
            ->and($line)->toContain(basename($repos['b']).'/src/Models/Thing.php');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repos['a']);
        surgeon_rrmdir($repos['b']);
    }
});

it('still resolves the destination into the other repo when the paths collide', function () {
    // The move itself was never broken — pin that, so a future reader does not mistake the render fix
    // for a resolution fix and go looking for a bug that was not there.
    $repos = surgeon_twin_path_repos('resolve');

    try {
        $report = (new AuditEngine)->audit(
            [$repos['a'], $repos['b']],
            Target::relocatingTo('Vendor\\A\\Models\\Thing', 'Vendor\\B\\Models\\Thing'),
        );
        $scratch = surgeon_tmp('twinpath-apply-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$repos['a'], $repos['b']],
            '--manifest' => $scratch.'/manifest.json',
            '--no-composer' => true,
        ])->assertExitCode(Command::SUCCESS);

        expect(is_file($repos['b'].'/src/Models/Thing.php'))->toBeTrue()
            ->and(is_file($repos['a'].'/src/Models/Thing.php'))->toBeFalse()
            ->and(file_get_contents($repos['b'].'/src/Models/Thing.php'))->toContain('namespace Vendor\\B\\Models;')
            ->and(file_get_contents($repos['a'].'/src/Consumer.php'))->toContain('use Vendor\\B\\Models\\Thing;');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repos['a']);
        surgeon_rrmdir($repos['b']);
    }
});

it('leaves a same-repo move rendered bare, with no repo prefix or flag', function () {
    // The disambiguation is scoped to the case that needs it — a same-repo move's relative paths are
    // unambiguous by construction, and prefixing every one would be noise.
    $repo = surgeon_tmp('samerepo-render');
    surgeon_write($repo.'/composer.json', json_encode([
        'name' => 'vendor/solo',
        'autoload' => ['psr-4' => ['Vendor\\Solo\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($repo.'/src/Models/Thing.php', "<?php\n\nnamespace Vendor\\Solo\\Models;\n\nclass Thing {}\n");
    surgeon_git_init($repo);

    try {
        $report = (new AuditEngine)->audit(
            [$repo],
            Target::relocatingTo('Vendor\\Solo\\Models\\Thing', 'Vendor\\Solo\\Domain\\Thing'),
        );
        $scratch = surgeon_tmp('samerepo-render-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Artisan::call('surgeon:move', ['--from' => $from, '--root' => [$repo]]);
        $output = Artisan::output();

        $line = collect(explode("\n", $output))->first(fn ($l) => str_contains($l, '→') && str_contains($l, 'Thing.php'));

        expect($line)->not->toBeNull()
            ->and($line)->not->toContain('[cross-repo]')
            ->and($line)->not->toContain(basename($repo).'/src/');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repo);
    }
});
