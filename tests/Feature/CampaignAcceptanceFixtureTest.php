<?php

use Rushing\Surgeon\Audit\TargetKind;
use Rushing\Surgeon\Replay\CampaignFixture;

/**
 * Acceptance fixtures for two real campaign SHAPES beyond FR (the pure consumer repoint proven by
 * ReplayHarnessTest). These ship the shipped `{old,new}` pairs + real commit range of two historical
 * splicewire-app campaigns so the harness can be replayed against that app's git history live
 * (`surgeon:replay --fixture=tests/Fixtures/campaigns/<x>.campaign.json --repo=<app>`).
 *
 * The REPLAY itself is a live command (it needs the splicewire-app repo, which a package test cannot
 * reach), so these tests assert the durable, portable property: each shipped fixture parses into a
 * well-formed set of relocation targets. The measured live recall is recorded in the doc block below
 * and in the app-side finding `.scratch/refactor-tooling/for-instances/04-campaign-acceptance.md`.
 *
 *   HTTP (consumer repoint, tower->beam-accounts auth types): 100% recall, 0 miss / 0 over-reach.
 *   AGT  (Assistant->Agent rename): ~53% recall by design — the misses are moved-TO files (absent in
 *        the before-tree, the known FR boundary) + the rename's NON-FQN cascade (route aliases,
 *        config prose, table/relationship-name churn). Expected Tier-3 advisory territory, not a defect.
 */
function surgeon_campaign_fixture(string $name): CampaignFixture
{
    return CampaignFixture::fromJsonFile(__DIR__.'/../Fixtures/campaigns/'.$name.'.campaign.json');
}

it('parses the HTTP consumer-repoint campaign fixture into relocation targets', function () {
    $fixture = surgeon_campaign_fixture('http');

    expect($fixture->name)->toBe('HTTP')
        ->and($fixture->beforeRef)->not->toBe('')
        ->and($fixture->afterRef)->not->toBe('')
        ->and($fixture->pairs)->toHaveCount(2);

    $targets = $fixture->targets();

    expect($targets)->toHaveCount(2)
        ->and($targets[0]->kind)->toBe(TargetKind::Symbol)
        ->and($targets[0]->value)->toBe('Splicewire\\Tower\\Enums\\TokenProvenance')
        ->and($targets[0]->newFqn)->toBe('Splicewire\\Beam\\Accounts\\Enums\\TokenProvenance')
        ->and($targets[0]->relocates())->toBeTrue();
});

it('parses the AGT rename campaign fixture: every pair is a relocation atom with a distinct new FQN', function () {
    $fixture = surgeon_campaign_fixture('agt');

    expect($fixture->name)->toBe('AGT')
        ->and($fixture->pairs)->toHaveCount(15);

    foreach ($fixture->targets() as $target) {
        expect($target->kind)->toBe(TargetKind::Symbol)
            ->and($target->relocates())->toBeTrue()
            ->and($target->value)->not->toBe($target->newFqn)
            ->and($target->value)->toContain('Assistant')
            ->and($target->newFqn)->toContain('Agent');
    }
});

it('both shipped campaign fixtures default to a php-scoped, scratch/vendor-excluded ground truth', function () {
    foreach (['http', 'agt'] as $name) {
        $fixture = surgeon_campaign_fixture($name);

        expect($fixture->countsPath('app/Providers/AppServiceProvider.php'))->toBeTrue()
            ->and($fixture->countsPath('.scratch/notes.md'))->toBeFalse()
            ->and($fixture->countsPath('vendor/acme/src/Thing.php'))->toBeFalse()
            ->and($fixture->countsPath('composer.lock'))->toBeFalse();
    }
});
