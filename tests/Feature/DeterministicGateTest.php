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

it('does not run stage-5 lint unless opted in (composable, not forced)', function () {
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

it('adds cross-stack lint as gate stage 5 in check-mode and passes when clean', function () {
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

it('fails the gate on stage-5 lint violations (check-mode, never reformats)', function () {
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

it('honestly skips stage-5 lint when no stack applies to the touched roots', function () {
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

/*
|--------------------------------------------------------------------------
| Stage 4 — dangling siblings
|--------------------------------------------------------------------------
|
| The regression these pin is the real one from `rushing/php-popcorn`
| (registry-kernel ticket 33 D6/C1): a namespace relocated ONE SYMBOL AT A TIME
| re-binds the unqualified sibling references left behind, `php -l` passes on
| every file because an unresolvable class name is a runtime failure, and the
| writer reports "applied: N splice(s)". The break reached a commit.
|
*/

it('fails the gate when a move leaves a same-namespace reference unresolvable', function () {
    $root = surgeon_tmp('gate-dangling');

    try {
        // The post-move-#1 state: this file's namespace line has been rewritten to Ladders, but
        // StrategyResult is still declared over in Strategy — so the bare sibling reference on
        // line 5 now names Acme\Ladders\StrategyResult, which nothing declares.
        surgeon_write($root.'/Ladders/Rung.php', <<<'PHP'
            <?php

            namespace Acme\Ladders;

            interface Rung
            {
                public function attempt(array $input): ?StrategyResult;
            }
            PHP);
        surgeon_write($root.'/Strategy/StrategyResult.php', "<?php\n\nnamespace Acme\Strategy;\n\nclass StrategyResult {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Ladders/Rung.php'], [$root]);

        expect($result->passed())->toBeFalse();

        $php = collect($result->stages)->firstWhere('stage', 'php -l');
        expect($php['status'])->toBe('pass'); // the whole point: syntax was never the problem

        $stage = collect($result->stages)->firstWhere('stage', 'dangling siblings');
        expect($stage['status'])->toBe('fail')
            ->and($stage['detail'])->toContain('Acme\Ladders\StrategyResult')
            ->and($stage['detail'])->toContain('Rung.php:7')
            ->and($stage['detail'])->toContain('atomic cluster');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes when the sibling moved with it — the atomic-cluster form', function () {
    $root = surgeon_tmp('gate-cluster');

    try {
        surgeon_write($root.'/Ladders/Rung.php', <<<'PHP'
            <?php

            namespace Acme\Ladders;

            interface Rung
            {
                public function attempt(array $input): ?RungResult;
            }
            PHP);
        surgeon_write($root.'/Ladders/RungResult.php', "<?php\n\nnamespace Acme\Ladders;\n\nclass RungResult {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Ladders/Rung.php', $root.'/Ladders/RungResult.php'], [$root]);

        expect($result->passed())->toBeTrue();
        $stage = collect($result->stages)->firstWhere('stage', 'dangling siblings');
        expect($stage['status'])->toBe('pass');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('does not flag imported or fully-qualified names — only same-namespace ones', function () {
    $root = surgeon_tmp('gate-imports');

    try {
        // Both of these name classes that do not exist anywhere. Neither is reachable by a
        // namespace-line rewrite, so neither is this stage's business — a gate that fails on
        // breakage the write-op did not cause is one people pass --no-verify to.
        surgeon_write($root.'/Ladders/Thing.php', <<<'PHP'
            <?php

            namespace Acme\Ladders;

            use Vendor\Absent\Imported;

            class Thing
            {
                public function a(): ?Imported { return null; }

                public function b(): ?\Totally\Absent\Qualified { return null; }
            }
            PHP);

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Ladders/Thing.php'], [$root]);

        expect($result->passed())->toBeTrue();
        expect(collect($result->stages)->firstWhere('stage', 'dangling siblings')['status'])->toBe('pass');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('does not flag a self-reference or a child-namespace name', function () {
    $root = surgeon_tmp('gate-self');

    try {
        surgeon_write($root.'/Ladders/Ladder.php', <<<'PHP'
            <?php

            namespace Acme\Ladders;

            class Ladder
            {
                public static function make(): static { return new Ladder; }

                public function child(): ?Rungs\Deep { return null; }
            }
            PHP);

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Ladders/Ladder.php'], [$root]);

        expect($result->passed())->toBeTrue();
    } finally {
        surgeon_rrmdir($root);
    }
});
