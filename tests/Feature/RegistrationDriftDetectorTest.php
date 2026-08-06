<?php

use Rushing\Surgeon\Audit\RegistrationDriftDetector;
use Rushing\Surgeon\Tests\Fixtures\Registration\Classes\AlreadyAttributed;
use Rushing\Surgeon\Tests\Fixtures\Registration\Classes\NeedsMigration;
use Rushing\Surgeon\Tests\Fixtures\Registration\RegistrationMarker;

$path = __DIR__.'/../Fixtures/Registration/classes';

it('buckets manually-registered classes by whether the attribute is present', function () use ($path) {
    $drift = (new RegistrationDriftDetector)->detect(
        [$path],
        RegistrationMarker::class,
        [AlreadyAttributed::class, NeedsMigration::class],
    );

    expect($drift->isClean())->toBeFalse()
        ->and($drift->needsAttribute)->toBe([NeedsMigration::class])
        ->and($drift->redundantManualEntry)->toBe([AlreadyAttributed::class]);
});

it('flags a redundant manual entry even though the attribute is already present', function () use ($path) {
    $drift = (new RegistrationDriftDetector)->detect(
        [$path],
        RegistrationMarker::class,
        [AlreadyAttributed::class],
    );

    // Attributed-but-still-manually-registered is drift too (a stale entry to delete) — clean
    // requires nothing left to convert AND no leftover manual entries.
    expect($drift->isClean())->toBeFalse()
        ->and($drift->redundantManualEntry)->toBe([AlreadyAttributed::class])
        ->and($drift->needsAttribute)->toBe([]);
});

it('reports clean when nothing is manually registered', function () use ($path) {
    $drift = (new RegistrationDriftDetector)->detect([$path], RegistrationMarker::class, []);

    expect($drift->isClean())->toBeTrue()
        ->and($drift->toFindings('registration-attribute')[0]->detail)
        ->toContain('no drift');
});

it('renders one warn finding per drifted class', function () use ($path) {
    $drift = (new RegistrationDriftDetector)->detect(
        [$path],
        RegistrationMarker::class,
        [AlreadyAttributed::class, NeedsMigration::class],
    );

    $findings = $drift->toFindings('registration-attribute');

    expect($findings)->toHaveCount(2)
        ->and(collect($findings)->pluck('status.value'))->each->toBe('warn');
});
