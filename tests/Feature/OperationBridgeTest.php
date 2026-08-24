<?php

use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorStatus;
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
use Rushing\Surgeon\Tests\Fixtures\Operations\ThrowingAudit;
use Rushing\Surgeon\Tests\Fixtures\Operations\ThrowingSuggestingAudit;

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

// ── Ticket 56 — a throwing audit is a finding, never the end of the sweep ─────────────────────────

it('turns a throwing audit into a finding and keeps sweeping the rest', function () {
    $report = sweep()->run(manifestOf([
        new DoctorRegistration('pkg', ThrowingAudit::class),
        new DoctorRegistration('other', PlainAudit::class),
    ]));

    // Both audits are in the report — before ticket 56 the throw escaped `run()` and PlainAudit's two
    // already-collected findings died with the command.
    expect($report->auditCount())->toBe(2)
        ->and($report->findingCount())->toBe(3);

    $errored = $report->results[0]->fixables[0]->finding;

    expect($errored->status)->toBe(DoctorStatus::Warn)
        ->and($errored->check)->toBe(ConformanceSweep::CHECK_ERRORED)
        ->and($errored->detail)->toContain(ThrowingAudit::class)
        ->and($errored->detail)->toContain('RuntimeException')
        ->and($errored->detail)->toContain("Unknown column 'deleted_at'")
        ->and($errored->detail)->not->toContain('the second line nobody needs'); // one line, bounded
});

it('reports a throwing GATE audit as Fail, because an unverified gate is not a passed gate', function () {
    $gate = sweep()->run(manifestOf([new DoctorRegistration('pkg', ThrowingAudit::class, gate: true)]));
    $advisory = sweep()->run(manifestOf([new DoctorRegistration('pkg', ThrowingAudit::class, gate: false)]));

    expect($gate->all()[0]->finding->status)->toBe(DoctorStatus::Fail)
        ->and($gate->hasGateFailure())->toBeTrue()
        // …and an advisory one never reddens the exit code on the strength of not knowing.
        ->and($advisory->all()[0]->finding->status)->toBe(DoctorStatus::Warn)
        ->and($advisory->hasGateFailure())->toBeFalse();
});

it('catches a Throwable from the paired suggestion channel too, not only DoctorAudit::run', function () {
    $report = sweep()->run(manifestOf([
        new DoctorRegistration('pkg', ThrowingSuggestingAudit::class),
    ]));

    expect($report->findingCount())->toBe(1)
        ->and($report->all()[0]->finding->detail)->toContain('Error: Call to undefined method');
});

it('turns a registration that cannot even be resolved into a finding of its own', function () {
    $sweep = new ConformanceSweep(function (string $class) {
        throw new RuntimeException("Target class [{$class}] does not exist.");
    });

    $report = $sweep->run(manifestOf([
        new DoctorRegistration('pkg', 'Vendor\\Retired\\GoneAudit', gate: true),
    ]));

    $finding = $report->all()[0]->finding;

    expect($report->auditCount())->toBe(1)
        ->and($finding->check)->toBe(ConformanceSweep::CHECK_ERRORED.'.resolve')
        ->and($finding->status)->toBe(DoctorStatus::Fail)
        ->and($finding->detail)->toContain('could not be resolved')
        ->and($finding->detail)->toContain('Vendor\\Retired\\GoneAudit');
});

it('never reports a throwing audit as silence — the error finding is the whole point', function () {
    $report = sweep()->run(manifestOf([new DoctorRegistration('pkg', ThrowingAudit::class)]));

    // A suppressed exception with no finding would be strictly worse than the crash: the report would
    // read green *because* an audit failed.
    expect($report->findingCount())->toBe(1)
        ->and($report->all()[0]->finding->status)->not->toBe(DoctorStatus::Pass);
});

it('surfaces a throwing built-in audit without losing the host manifest behind it', function () {
    $report = sweep()->run(
        manifestOf([new DoctorRegistration('pkg', PlainAudit::class)]),
        [new ThrowingSuggestingAudit],
    );

    expect($report->auditCount())->toBe(2)
        ->and($report->results[0]->package)->toBe('rushing/laravel-surgeon')
        ->and($report->results[0]->fixables[0]->finding->status)->toBe(DoctorStatus::Warn)
        ->and($report->results[1]->fixables)->toHaveCount(2);
});

it('runs a built-in advisory by default — nothing it finds reddens the exit code (ticket 88)', function () {
    // The channel's contract: an always-on audit fires in every repo whether or not that repo asked
    // for it, so it never gates on its own. PlainAudit emits a Warn, which is a finding an operator
    // reads and not a state that stops a build.
    $report = sweep()->run(manifestOf([]), [new PlainAudit]);

    expect($report->results[0]->gate)->toBeFalse()
        ->and($report->hasGateFailure(DoctorStatus::Warn))->toBeFalse();
});

it('lets a HOST opt a built-in into gating by registering its class-string (ticket 88)', function () {
    // The escape hatch. Same audit, same findings — the host's manifest is what promotes them into
    // the exit code, and the promotion honours --floor like any other gate result.
    $report = sweep()->run(
        manifestOf([new DoctorRegistration('host', PlainAudit::class, gate: true)]),
        [new PlainAudit],
    );

    expect($report->results[0]->gate)->toBeTrue()
        ->and($report->hasGateFailure(DoctorStatus::Warn))->toBeTrue()
        ->and($report->hasGateFailure())->toBeFalse(); // still a Warn — the default Fail floor holds
});

it('carries the opt-in FLAG only — the built-in instance runs, the registration is never resolved', function () {
    // Why this matters: every built-in takes a required `string $appRoot`, so resolving the
    // registration through the container throws an unresolvable-primitive error, which the sweep
    // would faithfully report as a Fail on a gate registration. The host would have opted into a red
    // exit code that reports nothing. So the resolver must not be reached at all on a dedupe hit.
    $resolved = [];
    $sweep = new ConformanceSweep(function (string $class) use (&$resolved) {
        $resolved[] = $class;

        return new $class;
    });

    $report = $sweep->run(
        manifestOf([new DoctorRegistration('host', PlainAudit::class, gate: true)]),
        [new PlainAudit],
    );

    expect($resolved)->toBe([])                      // never made
        ->and($report->auditCount())->toBe(1)        // one result, not two — the dedupe still holds
        ->and($report->results[0]->package)->toBe('rushing/laravel-surgeon')
        ->and($report->all())->toHaveCount(2);       // the built-in's own findings, not a re-run
});

it('leaves a built-in advisory when the host registers it WITHOUT a gate (ticket 88)', function () {
    // A registration is consent to gate, not merely acquaintance — `gate: false` changes nothing, which
    // is what keeps the dedupe's existing "registered by many packages" behaviour intact.
    $report = sweep()->run(
        manifestOf([new DoctorRegistration('host', PlainAudit::class, gate: false)]),
        [new PlainAudit],
    );

    expect($report->results[0]->gate)->toBeFalse()
        ->and($report->hasGateFailure(DoctorStatus::Warn))->toBeFalse();
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
