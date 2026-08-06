<?php

use Rushing\Surgeon\Replay\CampaignFixture;
use Rushing\Surgeon\Replay\GitRepo;
use Rushing\Surgeon\Replay\ReplayHarness;
use Rushing\Surgeon\Replay\ReplayReport;

/** Run a git subprocess in $repo (argv-array, no shell), throwing on failure. */
function surgeon_git(string $repo, array $args): void
{
    $process = proc_open(
        array_merge(['git', '-C', $repo], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('git '.implode(' ', $args).' failed: '.$err);
    }
}

/**
 * Stand up a two-commit repo that models a real FQN-repoint campaign: a `before` tree where two
 * consumers import `App\Old\Widget`, and an `after` tree that repoints them to `App\New\Widget`,
 * physically moves the class file, and churns `composer.lock` + `.scratch/` (the out-of-scope noise
 * a real campaign commit carries). Returns the repo path.
 */
function surgeon_campaign_repo(string $label): string
{
    $repo = surgeon_tmp($label);

    surgeon_write($repo.'/src/Old/Widget.php', "<?php\n\nnamespace App\\Old;\n\nclass Widget {}\n");
    surgeon_write($repo.'/src/Consumer.php', "<?php\n\nnamespace App;\n\nuse App\\Old\\Widget;\n\nclass Consumer\n{\n    public function w(): string\n    {\n        return Widget::class;\n    }\n}\n");
    surgeon_write($repo.'/src/AlsoUses.php', "<?php\n\nnamespace App;\n\nuse App\\Old\\Widget;\n\nclass AlsoUses\n{\n    public Widget \$w;\n}\n");
    surgeon_write($repo.'/src/Unrelated.php', "<?php\n\nnamespace App;\n\nclass Unrelated {}\n");
    surgeon_write($repo.'/composer.lock', "{}\n");
    surgeon_write($repo.'/.scratch/notes.md', "before\n");

    surgeon_git($repo, ['init', '-q']);
    surgeon_git($repo, ['config', 'user.email', 'surgeon@test.local']);
    surgeon_git($repo, ['config', 'user.name', 'surgeon-test']);
    surgeon_git($repo, ['add', '-A']);
    surgeon_git($repo, ['commit', '-q', '-m', 'before']);

    surgeon_write($repo.'/src/Consumer.php', "<?php\n\nnamespace App;\n\nuse App\\New\\Widget;\n\nclass Consumer\n{\n    public function w(): string\n    {\n        return Widget::class;\n    }\n}\n");
    surgeon_write($repo.'/src/AlsoUses.php', "<?php\n\nnamespace App;\n\nuse App\\New\\Widget;\n\nclass AlsoUses\n{\n    public Widget \$w;\n}\n");
    unlink($repo.'/src/Old/Widget.php');
    surgeon_write($repo.'/src/New/Widget.php', "<?php\n\nnamespace App\\New;\n\nclass Widget {}\n");
    surgeon_write($repo.'/composer.lock', "{\"changed\":1}\n");
    surgeon_write($repo.'/.scratch/notes.md', "after\n");

    surgeon_git($repo, ['add', '-A']);
    surgeon_git($repo, ['commit', '-q', '-m', 'after']);

    return $repo;
}

function surgeon_fr_fixture(): CampaignFixture
{
    return CampaignFixture::fromArray([
        'name' => 'Synthetic',
        'beforeRef' => 'HEAD~1',
        'afterRef' => 'HEAD',
        'pairs' => [['old' => 'App\\Old\\Widget', 'new' => 'App\\New\\Widget']],
    ]);
}

it('golden-replays a synthetic campaign: enumerates every consumer the campaign touched, no misses among them', function () {
    $repo = surgeon_campaign_repo('replay-consumers');

    try {
        $report = (new ReplayHarness)->replay(surgeon_fr_fixture(), $repo);

        expect($report)->toBeInstanceOf(ReplayReport::class)
            ->and($report->matched)->toContain('src/Consumer.php')->toContain('src/AlsoUses.php')
            ->and($report->missed)->not->toContain('src/Consumer.php')
            ->and($report->missed)->not->toContain('src/AlsoUses.php');
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('enumerates the moved-from file (its own declaration is a real touch-point) and never over-reaches into an unrelated file', function () {
    $repo = surgeon_campaign_repo('replay-declaration');

    try {
        $report = (new ReplayHarness)->replay(surgeon_fr_fixture(), $repo);

        // the moved-from declaration is a legitimate touch-point the audit sees in the pre-move tree
        expect($report->matched)->toContain('src/Old/Widget.php')
            // Unrelated.php references nothing that moved — it must appear nowhere
            ->and($report->overreached)->not->toContain('src/Unrelated.php')
            ->and($report->matched)->not->toContain('src/Unrelated.php')
            ->and($report->overreached)->toBe([]);
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('reports the destination file as a miss — the pre-move audit cannot enumerate a file the move creates', function () {
    $repo = surgeon_campaign_repo('replay-destination');

    try {
        $report = (new ReplayHarness)->replay(surgeon_fr_fixture(), $repo);

        // src/New/Widget.php exists only in the after-tree; the audit runs on the before-tree, so it
        // is a *known-boundary* miss (the physical-move step owns creating it, not the audit).
        expect($report->missed)->toBe(['src/New/Widget.php'])
            ->and($report->isClean())->toBeFalse();
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('scopes both sides to the fixture globs: composer.lock and .scratch churn never count', function () {
    $repo = surgeon_campaign_repo('replay-scope');

    try {
        $report = (new ReplayHarness)->replay(surgeon_fr_fixture(), $repo);

        expect($report->actualFiles)->not->toContain('composer.lock')
            ->and($report->actualFiles)->not->toContain('.scratch/notes.md')
            ->and($report->toolFiles)->not->toContain('composer.lock')
            ->and($report->toolFiles)->not->toContain('.scratch/notes.md');
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('resolves the fixture refs to full commit hashes for provenance', function () {
    $repo = surgeon_campaign_repo('replay-refs');

    try {
        $report = (new ReplayHarness)->replay(surgeon_fr_fixture(), $repo);
        $git = new GitRepo($repo);

        expect($report->beforeRef)->toBe($git->resolve('HEAD~1'))
            ->and($report->afterRef)->toBe($git->resolve('HEAD'))
            ->and($report->beforeRef)->toHaveLength(40);
    } finally {
        surgeon_rrmdir($repo);
    }
});

it('projects a miss-bearing replay into a doctor Fail and a clean one into a Pass', function () {
    $repo = surgeon_campaign_repo('replay-findings');

    try {
        $report = (new ReplayHarness)->replay(surgeon_fr_fixture(), $repo);
        $findings = $report->toDoctorFindings();

        // the synthetic move creates a destination file → one miss → a Fail finding
        expect($findings)->not->toBeEmpty()
            ->and($findings[0]->status->value)->toBe('fail');
    } finally {
        surgeon_rrmdir($repo);
    }
});
