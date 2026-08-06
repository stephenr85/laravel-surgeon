<?php

use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\TargetKind;
use Rushing\Surgeon\Replay\CampaignFixture;

it('builds a fixture from an array and derives one relocation target per pair', function () {
    $fixture = CampaignFixture::fromArray([
        'name' => 'FR',
        'beforeRef' => 'abc',
        'afterRef' => 'def',
        'pairs' => [
            ['old' => 'Splicewire\\Tower\\Models\\Fragment', 'new' => 'Splicewire\\Knowledge\\Fragments\\Models\\Fragment'],
            ['old' => 'Splicewire\\Tower\\Models\\Embedding', 'new' => 'Splicewire\\Knowledge\\Knowing\\Models\\Embedding'],
        ],
    ]);

    $targets = $fixture->targets();

    expect($targets)->toHaveCount(2)
        ->and($targets[0])->toBeInstanceOf(Target::class)
        ->and($targets[0]->kind)->toBe(TargetKind::Symbol)
        ->and($targets[0]->value)->toBe('Splicewire\\Tower\\Models\\Fragment')
        ->and($targets[0]->newFqn)->toBe('Splicewire\\Knowledge\\Fragments\\Models\\Fragment')
        ->and($targets[0]->relocates())->toBeTrue();
});

it('throws when a required key is missing', function () {
    CampaignFixture::fromArray(['name' => 'X', 'beforeRef' => 'a', 'afterRef' => 'b']);
})->throws(InvalidArgumentException::class, 'pairs');

it('throws when a pair lacks old or new', function () {
    CampaignFixture::fromArray([
        'name' => 'X', 'beforeRef' => 'a', 'afterRef' => 'b',
        'pairs' => [['old' => 'A\\B']],
    ]);
})->throws(InvalidArgumentException::class);

it('scopes the ground-truth set by include/exclude globs', function () {
    $fixture = CampaignFixture::fromArray([
        'name' => 'X', 'beforeRef' => 'a', 'afterRef' => 'b',
        'pairs' => [['old' => 'A\\B', 'new' => 'C\\D']],
    ]);

    // default includes *.php, excludes .scratch/*, vendor/*, node_modules/*, composer.lock
    expect($fixture->countsPath('app/Providers/AppServiceProvider.php'))->toBeTrue()
        ->and($fixture->countsPath('.scratch/notes/FR.md'))->toBeFalse()
        ->and($fixture->countsPath('vendor/acme/src/Thing.php'))->toBeFalse()
        ->and($fixture->countsPath('composer.lock'))->toBeFalse()
        ->and($fixture->countsPath('README.md'))->toBeFalse();
});

it('honours custom include/exclude globs', function () {
    $fixture = CampaignFixture::fromArray([
        'name' => 'X', 'beforeRef' => 'a', 'afterRef' => 'b',
        'pairs' => [['old' => 'A\\B', 'new' => 'C\\D']],
        'includeGlobs' => ['*.php', '*.stub'],
        'excludeGlobs' => ['tests/*'],
    ]);

    expect($fixture->countsPath('src/Thing.stub'))->toBeTrue()
        ->and($fixture->countsPath('tests/ThingTest.php'))->toBeFalse()
        ->and($fixture->countsPath('composer.lock'))->toBeFalse(); // not in include set
});
