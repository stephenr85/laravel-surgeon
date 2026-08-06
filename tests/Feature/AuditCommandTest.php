<?php

use Illuminate\Contracts\Console\Kernel;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Surgeon\Operation\CallbackConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Tests\Fixtures\Operations\PlainAudit;
use Rushing\Surgeon\Tests\Fixtures\Operations\SuggestingAudit;

/** Bind surgeon's discovery port to a synthetic manifest — what a real host's adapter does. */
function bindManifest(array $registrations): void
{
    app()->instance(ConformanceManifest::class, new CallbackConformanceManifest(fn () => $registrations));
}

it('registers surgeon:audit as the conformance sweep', function () {
    expect(array_key_exists('surgeon:audit', app(Kernel::class)->all()))->toBeTrue();
});

it('reports cleanly when no conformance audits are registered (Null default)', function () {
    // No bindManifest() call → the SurgeonServiceProvider's NullConformanceManifest default is in force.
    $this->artisan('surgeon:audit')
        ->expectsOutputToContain('No conformance audits registered')
        ->assertExitCode(0);
});

it('sweeps the registered manifest and reports the fixable subset in the footer', function () {
    bindManifest([new DoctorRegistration('splicewire/laravel-beam', SuggestingAudit::class, gate: false)]);

    $this->artisan('surgeon:audit')
        ->expectsOutputToContain('conformance sweep')
        ->expectsOutputToContain('[fixable: relocation]')
        ->expectsOutputToContain('fixable via the')
        ->assertExitCode(0);
});

it('renders an advisory nomination naming the topology-derived owning package', function () {
    bindManifest([new DoctorRegistration('splicewire/laravel-beam', SuggestingAudit::class)]);

    $this->artisan('surgeon:audit')
        ->expectsOutputToContain('Advisory')
        ->expectsOutputToContain('consider contributing an operation to splicewire/laravel-beam')
        ->assertExitCode(0);
});

it('fails the exit code on a Fail from a gate registration', function () {
    bindManifest([new DoctorRegistration('pkg', SuggestingAudit::class, gate: true)]);

    $this->artisan('surgeon:audit')->assertExitCode(1);
});

it('dedupes the same audit registered by many packages', function () {
    bindManifest([
        new DoctorRegistration('beam', SuggestingAudit::class),
        new DoctorRegistration('satellite', SuggestingAudit::class),
        new DoctorRegistration('tower', PlainAudit::class),
    ]);

    // 2 distinct audits after dedupe (SuggestingAudit once + PlainAudit).
    $this->artisan('surgeon:audit')
        ->expectsOutputToContain('(2 audit(s))')
        ->assertExitCode(0);
});

it('emits the machine-readable sweep as JSON with --json', function () {
    bindManifest([new DoctorRegistration('pkg', SuggestingAudit::class)]);

    $this->artisan('surgeon:audit', ['--json' => true])->assertExitCode(0);
});
