<?php

use Illuminate\Support\Facades\Artisan;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\Target;

/**
 * The Tier-2/Tier-3 handoff notice on `surgeon:move`.
 *
 * The command is a Tier-1 writer by contract, and that is correct — Tier 2 is guided, Tier 3 is
 * advisory, and the audit never promotes across that seam. What was wrong is that the command said
 * NOTHING about the references it left behind: it reported "applied: N splice(s)", listed the Tier-1
 * refs it had to skip and the destination composer-dep advisory, and stayed silent on every reference
 * correctly classified out of its reach.
 *
 * The cost of that silence is specific. A Tier-3 `route-config-reference` is a class-string in a
 * config seam; a stale one does not fail at boot, it fails the first time the seam is exercised —
 * which during a real beam-tenancy → beam-core model relocation meant two config bindings that would
 * have broken tenant provisioning, silently, long after the "successful" move.
 */
function surgeon_guided_ref_repo(string $label): string
{
    $repo = surgeon_tmp('guided-'.$label);
    surgeon_write($repo.'/composer.json', json_encode([
        'name' => 'vendor/guided',
        'autoload' => ['psr-4' => ['Vendor\\Guided\\' => 'src/']],
    ], JSON_PRETTY_PRINT));

    surgeon_write($repo.'/src/Models/Thing.php', <<<'PHP'
        <?php

        namespace Vendor\Guided\Models;

        class Thing {}

        PHP);

    // Tier 1: an ordinary import the writer WILL splice.
    surgeon_write($repo.'/src/Consumer.php', <<<'PHP'
        <?php

        namespace Vendor\Guided;

        use Vendor\Guided\Models\Thing;

        class Consumer
        {
            public function name(): string
            {
                return Thing::class;
            }
        }

        PHP);

    // Tier 3: the class-string in a config seam — the case that motivated this notice.
    surgeon_write($repo.'/config/things.php', <<<'PHP'
        <?php

        return [
            'model' => Vendor\Guided\Models\Thing::class,
        ];

        PHP);

    surgeon_git_init($repo);

    return $repo;
}

it('names the Tier-2/3 references it will not touch, in preview', function () {
    $repo = surgeon_guided_ref_repo('preview');

    try {
        $report = (new AuditEngine)->audit(
            [$repo],
            Target::relocatingTo('Vendor\\Guided\\Models\\Thing', 'Vendor\\Guided\\Domain\\Thing'),
        );

        // Guard the fixture: if the audit stops classifying the config hit above Tier 1, this test is
        // asserting nothing and should fail loudly rather than pass vacuously.
        $aboveTierOne = collect($report->references())->reject(fn ($r) => $r->tier->value === 'tier-1');
        expect($aboveTierOne)->not->toBeEmpty();

        $scratch = surgeon_tmp('guided-preview-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Artisan::call('surgeon:move', ['--from' => $from, '--root' => [$repo]]);
        $output = Artisan::output();

        expect($output)->toContain('Not touched by this command')
            ->and($output)->toContain('reference(s) above Tier 1')
            ->and($output)->toContain('config/things.php');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('repeats the notice after --apply, where it matters most', function () {
    // Preview is advisory; apply is the moment someone believes the move is done. The notice has to
    // survive to the success path or it does not do its job.
    $repo = surgeon_guided_ref_repo('apply');

    try {
        $report = (new AuditEngine)->audit(
            [$repo],
            Target::relocatingTo('Vendor\\Guided\\Models\\Thing', 'Vendor\\Guided\\Domain\\Thing'),
        );
        $scratch = surgeon_tmp('guided-apply-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Artisan::call('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$repo],
            '--manifest' => $scratch.'/manifest.json',
            '--no-composer' => true,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('surgeon:move applied')
            ->and($output)->toContain('Not touched by this command')
            ->and($output)->toContain('config/things.php');

        // And the config really is untouched — the notice describes reality, it does not paper over a
        // half-edit.
        expect(file_get_contents($repo.'/config/things.php'))->toContain('Vendor\\Guided\\Models\\Thing::class');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('stays silent when every reference is Tier 1', function () {
    // No notice on a clean mechanical move — the point is to surface real remaining work, not to add
    // a banner to every run.
    $repo = surgeon_tmp('guided-none');
    surgeon_write($repo.'/composer.json', json_encode([
        'name' => 'vendor/clean',
        'autoload' => ['psr-4' => ['Vendor\\Clean\\' => 'src/']],
    ], JSON_PRETTY_PRINT));
    surgeon_write($repo.'/src/Models/Thing.php', "<?php\n\nnamespace Vendor\\Clean\\Models;\n\nclass Thing {}\n");
    surgeon_write($repo.'/src/Consumer.php', <<<'PHP'
        <?php

        namespace Vendor\Clean;

        use Vendor\Clean\Models\Thing;

        class Consumer
        {
            public function name(): string
            {
                return Thing::class;
            }
        }

        PHP);
    surgeon_git_init($repo);

    try {
        $report = (new AuditEngine)->audit(
            [$repo],
            Target::relocatingTo('Vendor\\Clean\\Models\\Thing', 'Vendor\\Clean\\Domain\\Thing'),
        );
        $scratch = surgeon_tmp('guided-none-scratch');
        $from = $scratch.'/finding-set.json';
        file_put_contents($from, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Artisan::call('surgeon:move', ['--from' => $from, '--root' => [$repo]]);

        expect(Artisan::output())->not->toContain('Not touched by this command');

        surgeon_rrmdir($scratch);
    } finally {
        surgeon_rrmdir($repo);
    }
});
