<?php

use Rushing\Surgeon\Lint\EslintAdapter;
use Rushing\Surgeon\Lint\LintAudit;
use Rushing\Surgeon\Lint\LintOperation;
use Rushing\Surgeon\Lint\LintOrchestrator;
use Rushing\Surgeon\Lint\LintResult;
use Rushing\Surgeon\Lint\LintRun;
use Rushing\Surgeon\Lint\LintStack;
use Rushing\Surgeon\Lint\LintStackRegistry;
use Rushing\Surgeon\Lint\PintAdapter;
use Rushing\Surgeon\Lint\StackRunner;
use Rushing\Surgeon\Lint\StackRunResult;

/**
 * A {@see StackRunner} that returns a canned {@see StackRunResult} for every command — so an adapter's
 * exit-code→{@see LintResult} mapping unit-tests without ever spawning Pint/eslint (the injectable-subprocess
 * seam ticket 10/11 also keep).
 */
function fake_runner(int $exitCode, string $output = '', bool $ran = true): StackRunner
{
    return new class($exitCode, $output, $ran) implements StackRunner
    {
        public array $calls = [];

        public function __construct(private int $exitCode, private string $output, private bool $ran) {}

        public function run(string $cwd, array $command): StackRunResult
        {
            $this->calls[] = ['cwd' => $cwd, 'command' => $command];

            return $this->ran ? new StackRunResult($this->exitCode, $this->output) : StackRunResult::notRun($this->output);
        }
    };
}

/** A minimal registered stack whose detect() we control — for engine tests independent of Pint/eslint. */
function stub_stack(string $name, bool $detects): LintStack
{
    return new class($name, $detects) implements LintStack
    {
        public function __construct(private string $name, private bool $detects) {}

        public function name(): string
        {
            return $this->name;
        }

        public function detect(string $root): bool
        {
            return $this->detects;
        }

        public function check(string $root, StackRunner $runner): LintResult
        {
            return LintResult::clean($this->name, $root);
        }

        public function fix(string $root, StackRunner $runner): LintResult
        {
            return LintResult::fixed($this->name, $root, 1);
        }
    };
}

// --- PintAdapter (sniff + check/fix mapping) -------------------------------------------------------

it('sniffs a Pint stack from a pint.json', function () {
    $root = surgeon_tmp('pint-sniff');
    surgeon_write($root.'/pint.json', '{}');

    expect((new PintAdapter)->detect($root))->toBeTrue();

    surgeon_rrmdir($root);
});

it('does not sniff Pint from a bare composer.json with no vendored binary or pint.json', function () {
    $root = surgeon_tmp('pint-nosniff');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');

    expect((new PintAdapter)->detect($root))->toBeFalse();

    surgeon_rrmdir($root);
});

it('maps a clean pint --test (exit 0) to a clean result', function () {
    $root = surgeon_tmp('pint-clean');
    surgeon_write($root.'/pint.json', '{}');

    $result = (new PintAdapter)->check($root, fake_runner(0, '  ... 12 files'));

    expect($result->isClean())->toBeTrue()
        ->and($result->stack)->toBe('pint');

    surgeon_rrmdir($root);
});

it('maps a drifted pint --test (non-zero) to fixable violations', function () {
    $root = surgeon_tmp('pint-drift');
    surgeon_write($root.'/pint.json', '{}');

    $result = (new PintAdapter)->check($root, fake_runner(1, '3 style issues'));

    expect($result->hasViolations())->toBeTrue()
        ->and($result->fixable)->toBeTrue()
        ->and($result->violations)->toBe(3);

    surgeon_rrmdir($root);
});

it('maps a pint fix run to a fixed result', function () {
    $root = surgeon_tmp('pint-fix');
    surgeon_write($root.'/pint.json', '{}');

    $result = (new PintAdapter)->fix($root, fake_runner(0, '2 files fixed'));

    expect($result->fixed)->toBeTrue()
        ->and($result->isClean())->toBeTrue();

    surgeon_rrmdir($root);
});

// ⚠️ CHANGED BY beam-facade 163, deliberately. This assertion used to read `isSkipped()`, and that was
// the defect being asserted: a crashed pint landed in the same enum case as "does not apply here", and
// LintRun::clean() folded the pair into a clean verdict and exit 0. The crash is still not drift — that
// half was always right — it is now UNRUNNABLE rather than SKIPPED.
it('reports a Pint run that crashed (PHP fatal / OOM) as unrunnable — neither drift nor a skip', function () {
    $root = surgeon_tmp('pint-crash');
    surgeon_write($root.'/pint.json', '{}');

    $result = (new PintAdapter)->check($root, fake_runner(255, 'PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted'));

    expect($result->couldNotRun())->toBeTrue()
        ->and($result->isSkipped())->toBeFalse()
        ->and($result->hasViolations())->toBeFalse()
        ->and($result->ran())->toBeFalse();

    surgeon_rrmdir($root);
});

it('reports a pint that could not spawn as unrunnable — the two all-SKIP roots in this estate', function () {
    $root = surgeon_tmp('pint-nospawn');
    // A pint.json with no vendor/bin/pint: binary() falls back to a bare `pint` because "a pint.json
    // anchors intent", and there is none on PATH. Both laravel-circuit-spine and laravel-knowledge-spine
    // are in exactly this state, and both are pint-only.
    surgeon_write($root.'/pint.json', '{}');

    $result = (new PintAdapter)->check($root, fake_runner(0, 'could not spawn pint', ran: false));

    expect($result->couldNotRun())->toBeTrue()
        ->and($result->isSkipped())->toBeFalse();

    surgeon_rrmdir($root);
});

it('reads Pint\'s structured JSON verdict as authoritative over the exit code', function () {
    $root = surgeon_tmp('pint-json-verdict');
    surgeon_write($root.'/pint.json', '{}');

    // A wrapper that exits 0 even on a JSON "fail" verdict (the app\'s custom reporter shape).
    $result = (new PintAdapter)->check($root, fake_runner(0, '{"tool":"pint","result":"fail","files":[{"path":"a.php"},{"path":"b.php"}]}'));

    expect($result->hasViolations())->toBeTrue()
        ->and($result->fixable)->toBeTrue()
        ->and($result->violations)->toBe(2);

    surgeon_rrmdir($root);
});

it('reads a Pint JSON passed verdict as clean even when the exit code would say otherwise', function () {
    $root = surgeon_tmp('pint-json-pass');
    surgeon_write($root.'/pint.json', '{}');

    $result = (new PintAdapter)->check($root, fake_runner(1, '{"tool":"pint","result":"passed"}'));

    expect($result->isClean())->toBeTrue();

    surgeon_rrmdir($root);
});

it('skips Pint honestly when the binary is absent', function () {
    $root = surgeon_tmp('pint-nobin');
    // composer.json but no vendor/bin/pint and no pint.json → binary() is null → detect false; but even
    // if forced to run, check() must skip, not fail.
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');

    $result = (new PintAdapter)->check($root, fake_runner(0));

    expect($result->isSkipped())->toBeTrue();

    surgeon_rrmdir($root);
});

// --- EslintAdapter (JS proof-of-two: sniff + fixable/advisory seam) --------------------------------

it('sniffs eslint from a package.json + flat config', function () {
    $root = surgeon_tmp('eslint-sniff');
    surgeon_write($root.'/package.json', '{"name":"app"}');
    surgeon_write($root.'/eslint.config.js', 'export default [];');

    expect((new EslintAdapter)->detect($root))->toBeTrue();

    surgeon_rrmdir($root);
});

it('does NOT sniff eslint from a package.json alone (the no-lint-script mis-fire)', function () {
    $root = surgeon_tmp('eslint-nosniff');
    surgeon_write($root.'/package.json', '{"name":"app","scripts":{"build":"vite"}}');

    expect((new EslintAdapter)->detect($root))->toBeFalse();

    surgeon_rrmdir($root);
});

it('sniffs eslint from a package.json eslint devDep', function () {
    $root = surgeon_tmp('eslint-devdep');
    surgeon_write($root.'/package.json', '{"name":"app","devDependencies":{"eslint":"^9"}}');

    expect((new EslintAdapter)->detect($root))->toBeTrue();

    surgeon_rrmdir($root);
});

it('maps eslint check drift (exit 1) to fixable violations', function () {
    $root = surgeon_tmp('eslint-drift');
    surgeon_write($root.'/package.json', '{"name":"app"}');
    surgeon_write($root.'/eslint.config.js', 'export default [];');
    surgeon_write($root.'/node_modules/.bin/eslint', '#!/bin/sh');

    $result = (new EslintAdapter)->check($root, fake_runner(1, '5 problems (3 errors, 2 warnings)'));

    expect($result->hasViolations())->toBeTrue()
        ->and($result->fixable)->toBeTrue()
        ->and($result->violations)->toBe(5);

    surgeon_rrmdir($root);
});

it('leaves eslint --fix residue advisory (non-auto-fixable — the deterministic/agentic seam)', function () {
    $root = surgeon_tmp('eslint-residue');
    surgeon_write($root.'/package.json', '{"name":"app"}');
    surgeon_write($root.'/eslint.config.js', 'export default [];');
    surgeon_write($root.'/node_modules/.bin/eslint', '#!/bin/sh');

    $result = (new EslintAdapter)->fix($root, fake_runner(1, '2 problems'));

    expect($result->hasViolations())->toBeTrue()
        ->and($result->fixable)->toBeFalse(); // residue stays agent territory

    surgeon_rrmdir($root);
});

// ⚠️ CHANGED BY beam-facade 163 — same reason as the pint crash above. Still not a fixable violation.
it('treats an eslint config/crash (exit 2) as unrunnable, not a fixable violation and not a skip', function () {
    $root = surgeon_tmp('eslint-crash');
    surgeon_write($root.'/package.json', '{"name":"app"}');
    surgeon_write($root.'/eslint.config.js', 'export default [];');
    surgeon_write($root.'/node_modules/.bin/eslint', '#!/bin/sh');

    $result = (new EslintAdapter)->check($root, fake_runner(2, 'Cannot read config'));

    expect($result->couldNotRun())->toBeTrue()
        ->and($result->hasViolations())->toBeFalse()
        ->and($result->isSkipped())->toBeFalse();

    surgeon_rrmdir($root);
});

// --- LintStackRegistry (sniff-with-override discovery) ---------------------------------------------

it('selects sniffed stacks by default', function () {
    $root = surgeon_tmp('reg-sniff');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $registry = new LintStackRegistry([stub_stack('pint', true), stub_stack('eslint', false)]);

    $names = array_map(fn ($s) => $s->name(), $registry->stacksFor($root));

    expect($names)->toBe(['pint']);

    surgeon_rrmdir($root);
});

it('suppresses all lint when extra.surgeon.lint is false', function () {
    $root = surgeon_tmp('reg-off');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'extra' => ['surgeon' => ['lint' => false]]]));
    $registry = new LintStackRegistry([stub_stack('pint', true)]);

    expect($registry->stacksFor($root))->toBe([]);

    surgeon_rrmdir($root);
});

it('honours an explicit stacks allow-list, ignoring the sniff', function () {
    $root = surgeon_tmp('reg-only');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'extra' => ['surgeon' => ['lint' => ['stacks' => ['eslint']]]]]));
    $registry = new LintStackRegistry([stub_stack('pint', true), stub_stack('eslint', false)]);

    $names = array_map(fn ($s) => $s->name(), $registry->stacksFor($root));

    expect($names)->toBe(['eslint']); // eslint didn't sniff, but the allow-list forces it

    surgeon_rrmdir($root);
});

it('subtracts an except-listed stack from the sniff (fix a mis-fire)', function () {
    $root = surgeon_tmp('reg-except');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'extra' => ['surgeon' => ['lint' => ['except' => ['eslint']]]]]));
    $registry = new LintStackRegistry([stub_stack('pint', true), stub_stack('eslint', true)]);

    $names = array_map(fn ($s) => $s->name(), $registry->stacksFor($root));

    expect($names)->toBe(['pint']);

    surgeon_rrmdir($root);
});

it('force-adds a stack the sniff missed', function () {
    $root = surgeon_tmp('reg-add');
    surgeon_write($root.'/composer.json', json_encode(['name' => 'acme/app', 'extra' => ['surgeon' => ['lint' => ['add' => ['eslint']]]]]));
    $registry = new LintStackRegistry([stub_stack('pint', true), stub_stack('eslint', false)]);

    $names = array_map(fn ($s) => $s->name(), $registry->stacksFor($root));

    expect($names)->toBe(['pint', 'eslint']);

    surgeon_rrmdir($root);
});

it('ships Pint + eslint as the default proof-of-two', function () {
    $names = array_map(fn ($s) => $s->name(), LintStackRegistry::withDefaults()->all());

    expect($names)->toBe(['pint', 'eslint']);
});

// --- LintOrchestrator (check vs fix, discovery, root dedupe) ---------------------------------------

it('runs check across the discovered stacks and aggregates a LintRun', function () {
    $root = surgeon_tmp('orch-check');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $registry = new LintStackRegistry([stub_stack('pint', true)]);

    $run = (new LintOrchestrator($registry))->check([$root]);

    expect($run->mode)->toBe('check')
        ->and($run->clean())->toBeTrue()
        ->and($run->results)->toHaveCount(1);

    surgeon_rrmdir($root);
});

it('fails the run when a stack reports violations in check-mode', function () {
    $root = surgeon_tmp('orch-drift');
    surgeon_write($root.'/pint.json', '{}');
    $registry = new LintStackRegistry([new PintAdapter]);
    // Force the binary path: a pint.json makes binary() return 'pint', so check runs the fake runner.
    $orchestrator = new LintOrchestrator($registry, fake_runner(1, '4 style issues'));

    $run = $orchestrator->check([$root]);

    expect($run->clean())->toBeFalse()
        ->and($run->violations())->toHaveCount(1);

    surgeon_rrmdir($root);
});

it('discovers which stacks apply per root without running them', function () {
    $root = surgeon_tmp('orch-discover');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $registry = new LintStackRegistry([stub_stack('pint', true), stub_stack('eslint', false)]);

    $map = (new LintOrchestrator($registry))->discover([$root]);

    expect($map[$root])->toBe(['pint']);

    surgeon_rrmdir($root);
});

it('deduplicates roots so a stack runs once per root', function () {
    $root = surgeon_tmp('orch-dedupe');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $registry = new LintStackRegistry([stub_stack('pint', true)]);

    $run = (new LintOrchestrator($registry))->check([$root, $root.'/', $root]);

    expect($run->results)->toHaveCount(1);

    surgeon_rrmdir($root);
});

it('counts reformatted files across a fix run', function () {
    $root = surgeon_tmp('orch-fixcount');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $registry = new LintStackRegistry([stub_stack('pint', true)]);

    $run = (new LintOrchestrator($registry))->fix([$root]);

    expect($run->mode)->toBe('fix')
        ->and($run->fixedCount())->toBe(1);

    surgeon_rrmdir($root);
});

// --- LintAudit (result → FixableFinding mapping through the 07/13 bridge) --------------------------

it('passes when there are no roots to lint', function () {
    $findings = (new LintAudit([]))->suggestOperations();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->finding->check)->toBe('lint.no-roots');
});

it('passes when no stack applies to the root', function () {
    $root = surgeon_tmp('audit-nostack');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}'); // nothing sniffs
    $orchestrator = new LintOrchestrator(new LintStackRegistry([stub_stack('pint', false)]));

    $findings = (new LintAudit([$root], $orchestrator))->suggestOperations();

    expect($findings[0]->finding->check)->toBe('lint.no-stacks');

    surgeon_rrmdir($root);
});

it('warns and nominates surgeon:lint --fix for deterministically fixable drift', function () {
    $root = surgeon_tmp('audit-drift');
    surgeon_write($root.'/pint.json', '{}');
    $orchestrator = new LintOrchestrator(new LintStackRegistry([new PintAdapter]), fake_runner(1, '2 style issues'));

    $findings = (new LintAudit([$root], $orchestrator))->suggestOperations();

    expect($findings[0]->finding->check)->toBe('lint.drift')
        ->and($findings[0]->finding->status->value)->toBe('warn')
        ->and($findings[0]->isFixable())->toBeTrue()
        ->and($findings[0]->suggestion->kind)->toBe('lint')
        ->and($findings[0]->suggestion->payload['stack'])->toBe('pint');

    surgeon_rrmdir($root);
});

it('emits an advisory (no fix) for non-deterministic eslint residue in check-mode', function () {
    $root = surgeon_tmp('audit-advisory');
    surgeon_write($root.'/package.json', '{"name":"app"}');
    surgeon_write($root.'/eslint.config.js', 'export default [];');
    surgeon_write($root.'/node_modules/.bin/eslint', '#!/bin/sh');
    // A stack that in check-mode reports non-fixable violations directly.
    $stack = new class implements LintStack
    {
        public function name(): string
        {
            return 'eslint';
        }

        public function detect(string $root): bool
        {
            return true;
        }

        public function check(string $root, StackRunner $runner): LintResult
        {
            return LintResult::violations('eslint', $root, 3, fixable: false, detail: 'non-auto-fixable');
        }

        public function fix(string $root, StackRunner $runner): LintResult
        {
            return LintResult::violations('eslint', $root, 3, fixable: false);
        }
    };
    $orchestrator = new LintOrchestrator(new LintStackRegistry([$stack]));

    $findings = (new LintAudit([$root], $orchestrator))->suggestOperations();

    expect($findings[0]->finding->check)->toBe('lint.drift-advisory')
        ->and($findings[0]->isFixable())->toBeFalse()
        ->and($findings[0]->isAdvisory())->toBeTrue();

    surgeon_rrmdir($root);
});

it('reports a skipped stack honestly as a pass, never a failure', function () {
    $root = surgeon_tmp('audit-skip');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $stack = new class implements LintStack
    {
        public function name(): string
        {
            return 'pint';
        }

        public function detect(string $root): bool
        {
            return true;
        }

        public function check(string $root, StackRunner $runner): LintResult
        {
            return LintResult::skipped('pint', $root, 'binary absent');
        }

        public function fix(string $root, StackRunner $runner): LintResult
        {
            return LintResult::skipped('pint', $root, 'binary absent');
        }
    };
    $orchestrator = new LintOrchestrator(new LintStackRegistry([$stack]));

    $findings = (new LintAudit([$root], $orchestrator))->suggestOperations();

    expect($findings[0]->finding->check)->toBe('lint.skipped')
        ->and($findings[0]->finding->status->value)->toBe('pass');

    surgeon_rrmdir($root);
});

// --- LintOperation (identity) ----------------------------------------------------------------------

it('exposes lint as a writer operation with a stack+root suggestion payload', function () {
    $op = new LintOperation;

    expect($op->kind())->toBe('lint')
        ->and($op->isWriter())->toBeTrue()
        ->and($op->describe())->toContain('reformat');

    $suggestion = $op->suggestFor('pint', '/tmp/x');
    expect($suggestion->kind)->toBe('lint')
        ->and($suggestion->payload)->toBe(['stack' => 'pint', 'root' => '/tmp/x']);
});

// --- beam-facade 163: a SKIP must not fold into a clean verdict -----------------------------------

it('refuses a clean verdict when an applicable stack could not execute', function () {
    $run = new LintRun('check', [LintResult::unrunnable('pint', '/root', 'pint could not lint: OOM')]);

    expect($run->clean())->toBeFalse()
        ->and($run->unrunnable())->toHaveCount(1)
        ->and($run->ran())->toHaveCount(0)
        ->and($run->ranSummary())->toBe('0 of 1 applicable stack(s) ran');
});

it('still passes a genuine skip, because nothing was asked of it', function () {
    $run = new LintRun('check', [LintResult::skipped('eslint', '/root', 'no eslint binary')]);

    expect($run->clean())->toBeTrue()
        // A not-applicable stack is in neither half of the ran summary.
        ->and($run->ranSummary())->toBe('0 of 0 applicable stack(s) ran');
});

it('does not let one crashed stack hide behind its clean neighbours — the --root=all-overlay fold', function () {
    $run = new LintRun('check', [
        LintResult::clean('pint', '/a', 'no drift'),
        LintResult::clean('pint', '/b', 'no drift'),
        LintResult::unrunnable('pint', '/c', 'pint could not lint: OOM'),
    ]);

    expect($run->clean())->toBeFalse()
        ->and($run->ranSummary())->toBe('2 of 3 applicable stack(s) ran');
});

it('warns rather than passes when the audit channel meets a stack that could not execute', function () {
    $root = surgeon_tmp('audit-unrunnable');
    surgeon_write($root.'/composer.json', '{"name":"acme/app"}');
    $stack = new class implements LintStack
    {
        public function name(): string
        {
            return 'pint';
        }

        public function detect(string $root): bool
        {
            return true;
        }

        public function check(string $root, StackRunner $runner): LintResult
        {
            return LintResult::unrunnable('pint', $root, 'pint could not lint: OOM');
        }

        public function fix(string $root, StackRunner $runner): LintResult
        {
            return LintResult::unrunnable('pint', $root, 'pint could not lint: OOM');
        }
    };

    $findings = (new LintAudit([$root], new LintOrchestrator(new LintStackRegistry([$stack]))))->suggestOperations();

    // Warn, not Fail: severity is the RUNNER's, and this audit rides the advisory conformance channel.
    // The same event on `surgeon:lint` check-mode — a declared gate — is a non-zero exit.
    expect($findings[0]->finding->check)->toBe('lint.unrunnable')
        ->and($findings[0]->finding->status->value)->toBe('warn')
        ->and($findings[0]->finding->detail)->toContain('NOTHING was checked');

    surgeon_rrmdir($root);
});

it('reports the unrunnable and ran counts in the machine-readable run', function () {
    $run = new LintRun('check', [
        LintResult::clean('pint', '/a'),
        LintResult::unrunnable('eslint', '/a', 'config crash'),
        LintResult::skipped('pint', '/b', 'not applicable'),
    ]);

    expect($run->toArray()['counts'])->toBe([
        'results' => 3,
        'violations' => 0,
        'skipped' => 1,
        'unrunnable' => 1,
        'ran' => 1,
    ])->and($run->toArray()['clean'])->toBeFalse();
});
