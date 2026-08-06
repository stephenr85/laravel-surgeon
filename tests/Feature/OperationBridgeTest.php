<?php

use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Operation\CallbackConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceSweep;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\NullConformanceManifest;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\RelocationOperation;
use Rushing\Surgeon\Rewrite\RewritePlan;
use Rushing\Surgeon\Tests\Fixtures\Operations\PlainAudit;
use Rushing\Surgeon\Tests\Fixtures\Operations\SuggestingAudit;

/** Resolve fixture audits with a plain `new` — the sweep is container-agnostic behind its resolver. */
function sweep(): ConformanceSweep
{
    return new ConformanceSweep(fn (string $class) => new $class);
}

/** @param list<DoctorRegistration> $regs */
function manifestOf(array $regs): CallbackConformanceManifest
{
    return new CallbackConformanceManifest(fn () => $regs);
}

it('discovers audits from the manifest and reports the fixable subset', function () {
    $report = sweep()->run(manifestOf([
        new DoctorRegistration('splicewire/laravel-beam', SuggestingAudit::class, gate: true),
    ]));

    expect($report->auditCount())->toBe(1)
        ->and($report->findingCount())->toBe(3)   // fix + advisory + plain pass
        ->and($report->fixableCount())->toBe(1)   // only the relocation suggestion is a real fix
        ->and($report->advisoryCount())->toBe(1); // the repeated-shape nomination
});

it('prefers the SuggestsOperations channel over DoctorAudit::run when an audit implements both', function () {
    $report = sweep()->run(manifestOf([
        new DoctorRegistration('pkg', SuggestingAudit::class),
    ]));

    // The paired channel carries suggestions; had it fallen back to run() the fixable count would be 0.
    expect($report->fixable())->toHaveCount(1)
        ->and($report->fixable()[0]->suggestion->kind)->toBe('relocation')
        ->and($report->fixable()[0]->suggestion->payload)->toBe(['old' => 'App\\Models\\Order', 'new' => 'App\\Particles\\Order']);
});

it('wraps a plain DoctorAudit finding with a null suggestion (not fixable, not advisory)', function () {
    $report = sweep()->run(manifestOf([
        new DoctorRegistration('pkg', PlainAudit::class),
    ]));

    expect($report->findingCount())->toBe(2)
        ->and($report->fixableCount())->toBe(0)
        ->and($report->advisoryCount())->toBe(0)
        ->and($report->all()[0]->suggestion)->toBeNull();
});

it('dedupes registrations by audit class-string — the same audit runs once', function () {
    $report = sweep()->run(manifestOf([
        new DoctorRegistration('beam', SuggestingAudit::class),
        new DoctorRegistration('satellite', SuggestingAudit::class), // same audit, required transitively
        new DoctorRegistration('tower', SuggestingAudit::class),
    ]));

    expect($report->auditCount())->toBe(1)
        ->and($report->findingCount())->toBe(3);
});

it('turns the exit red only on a Fail from a gate registration', function () {
    $gate = sweep()->run(manifestOf([new DoctorRegistration('pkg', SuggestingAudit::class, gate: true)]));
    $advisory = sweep()->run(manifestOf([new DoctorRegistration('pkg', SuggestingAudit::class, gate: false)]));

    expect($gate->hasGateFailure())->toBeTrue()      // SuggestingAudit emits a Fail finding
        ->and($advisory->hasGateFailure())->toBeFalse();
});

it('discovers nothing from the NullConformanceManifest default', function () {
    $report = sweep()->run(new NullConformanceManifest);

    expect($report->auditCount())->toBe(0)
        ->and($report->findingCount())->toBe(0);
});

it('models an advisory suggestion with no applicable operation and an owning package', function () {
    $advisory = OperationSuggestion::advisory('looks deterministic', 'splicewire/laravel-beam');

    expect($advisory->isAdvisory())->toBeTrue()
        ->and($advisory->kind)->toBe(OperationSuggestion::ADVISORY)
        ->and($advisory->owningPackage)->toBe('splicewire/laravel-beam');

    $pair = new FixableFinding(Finding::warn('c', 'd'), $advisory);
    expect($pair->isAdvisory())->toBeTrue()->and($pair->isFixable())->toBeFalse();
});

it('exposes relocation as the first concrete Operation', function () {
    $op = new RelocationOperation(['/tmp/root']);

    expect($op->kind())->toBe('relocation')
        ->and($op->isWriter())->toBeTrue()
        ->and($op->describe())->toContain('Relocate');
});

it('plans a relocation audit report into a RewritePlan through the operation', function () {
    $target = Target::relocatingTo('App\\Old\\Widget', 'App\\New\\Widget');
    $report = new AuditReport($target, ['/tmp/root'], []); // no references → empty, well-formed plan

    $plan = (new RelocationOperation(['/tmp/root']))->plan($report);

    expect($plan)->toBeInstanceOf(RewritePlan::class)
        ->and($plan->target->newFqn)->toBe('App\\New\\Widget');
});
