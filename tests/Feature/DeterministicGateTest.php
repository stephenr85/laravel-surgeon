<?php

use Rushing\Surgeon\Lint\LintOrchestrator;
use Rushing\Surgeon\Lint\LintResult;
use Rushing\Surgeon\Lint\LintStack;
use Rushing\Surgeon\Lint\LintStackRegistry;
use Rushing\Surgeon\Lint\StackRunner;
use Rushing\Surgeon\Rewrite\DeterministicGate;

/** A stack whose check() outcome the gate tests control. */
function gate_stack(string $outcome): LintStack
{
    return new class($outcome) implements LintStack
    {
        public function __construct(private string $outcome) {}

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
            return match ($this->outcome) {
                'violations' => LintResult::violations('pint', $root, 2, fixable: true),
                'skip' => LintResult::skipped('pint', $root, 'binary absent'),
                default => LintResult::clean('pint', $root),
            };
        }

        public function fix(string $root, StackRunner $runner): LintResult
        {
            return LintResult::fixed('pint', $root, 1);
        }
    };
}

it('passes php -l on syntactically valid touched files', function () {
    $root = surgeon_tmp('gate-pass');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Good.php'], [$root]);

        expect($result->passed())->toBeTrue();
        $php = collect($result->stages)->firstWhere('stage', 'php -l');
        expect($php['status'])->toBe('pass');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('fails the gate when a touched file has broken syntax', function () {
    $root = surgeon_tmp('gate-fail');

    try {
        surgeon_write($root.'/Broken.php', "<?php\n\nclass Broken { public function x(  {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Broken.php'], [$root]);

        expect($result->passed())->toBeFalse()
            ->and($result->failures())->not->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('honestly skips the topology stage for a relocation rather than fabricating a pass', function () {
    $root = surgeon_tmp('gate-topo');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: true))
            ->run([$root.'/Good.php'], [$root]);

        $topo = collect($result->stages)->firstWhere('stage', 'topology cycle-guard');
        expect($topo['status'])->toBe('skip')
            ->and($result->passed())->toBeTrue();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('does not run stage-4 lint unless opted in (composable, not forced)', function () {
    $root = surgeon_tmp('gate-lint-off');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Good.php'], [$root]);

        expect(collect($result->stages)->firstWhere('stage', 'cross-stack lint'))->toBeNull();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('adds cross-stack lint as gate stage 4 in check-mode and passes when clean', function () {
    $root = surgeon_tmp('gate-lint-clean');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");
        $orchestrator = new LintOrchestrator(new LintStackRegistry([gate_stack('clean')]));

        $result = (new DeterministicGate(runComposer: false, runTopology: false, runLint: true, lint: $orchestrator))
            ->run([$root.'/Good.php'], [$root]);

        $lint = collect($result->stages)->firstWhere('stage', 'cross-stack lint');
        expect($lint['status'])->toBe('pass')
            ->and($result->passed())->toBeTrue();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('fails the gate on stage-4 lint violations (check-mode, never reformats)', function () {
    $root = surgeon_tmp('gate-lint-fail');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");
        $orchestrator = new LintOrchestrator(new LintStackRegistry([gate_stack('violations')]));

        $result = (new DeterministicGate(runComposer: false, runTopology: false, runLint: true, lint: $orchestrator))
            ->run([$root.'/Good.php'], [$root]);

        $lint = collect($result->stages)->firstWhere('stage', 'cross-stack lint');
        expect($lint['status'])->toBe('fail')
            ->and($result->passed())->toBeFalse();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('honestly skips stage-4 lint when no stack applies to the touched roots', function () {
    $root = surgeon_tmp('gate-lint-skip');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");
        // Empty registry → no stack applies.
        $orchestrator = new LintOrchestrator(new LintStackRegistry([]));

        $result = (new DeterministicGate(runComposer: false, runTopology: false, runLint: true, lint: $orchestrator))
            ->run([$root.'/Good.php'], [$root]);

        $lint = collect($result->stages)->firstWhere('stage', 'cross-stack lint');
        expect($lint['status'])->toBe('skip')
            ->and($result->passed())->toBeTrue();
    } finally {
        surgeon_rrmdir($root);
    }
});
