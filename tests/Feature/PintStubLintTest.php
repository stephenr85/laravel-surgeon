<?php

use Rushing\Surgeon\Lint\LintResult;
use Rushing\Surgeon\Lint\LintStackRegistry;
use Rushing\Surgeon\Lint\PintStubAdapter;
use Rushing\Surgeon\Lint\ProcessStackRunner;
use Rushing\Surgeon\Lint\StackRunner;
use Rushing\Surgeon\Lint\StackRunResult;

/**
 * `pint-stubs` — the lint stack that can see a `*.php.stub` (prove-the-beam-starter ticket 13).
 *
 * Two halves, and the second is the one that matters. The pure cases below fake the subprocess and pin the
 * discovery + operand + reporting logic. The last two cases run **the real Pint binary** against a real
 * temp tree, because the whole defect this stack exists to close is a tool's *traversal* silently omitting a
 * population: a mapping test over a canned verdict would have passed just as happily before this stack
 * existed. The mutation case is the acceptance criterion stated as a test — break a stub, watch it go red
 * naming that file, revert, watch it go green — so the gate cannot quietly stop gating.
 */
function stub_lint_runner(int $exitCode, string $output): StackRunner
{
    return new class($exitCode, $output) implements StackRunner
    {
        public array $calls = [];

        public function __construct(private int $exitCode, private string $output) {}

        public function run(string $cwd, array $command): StackRunResult
        {
            $this->calls[] = ['cwd' => $cwd, 'command' => $command];

            return new StackRunResult($this->exitCode, $this->output);
        }
    };
}

/** A throwaway package root shipping one stub, with a vendored pint the fake runner never executes. */
function stub_root(string $label, string $stub): string
{
    $root = surgeon_tmp($label);
    surgeon_write($root.'/composer.json', '{"name":"acme/pkg"}');
    surgeon_write($root.'/database/migrations/create_widgets_table.php.stub', $stub);
    // A vendored binary so detect() has something to find; the fake runner never executes it.
    surgeon_write($root.'/vendor/bin/pint', "#!/usr/bin/env php\n<?php\n");

    return $root;
}

/** A stub whose bytes Pint is content with. */
function clean_stub(): string
{
    return <<<'PHP'
        <?php

        return new class
        {
            public function up(): void
            {
                //
            }
        };

        PHP;
}

/** The same stub with the drift ticket 11 measured in the wild: import order and spacing. */
function drifting_stub(): string
{
    return <<<'PHP'
        <?php

        use Zebra\Thing;
        use Alpha\Thing;

        return new class
        {
            public function up() : void {
                $x=1;
            }
        };

        PHP;
}

// --- discovery -------------------------------------------------------------------------------------

it('applies only where a root actually ships a php.stub', function () {
    $withStubs = stub_root('stub-detect-yes', clean_stub());

    $withoutStubs = surgeon_tmp('stub-detect-no');
    surgeon_write($withoutStubs.'/composer.json', '{"name":"acme/pkg"}');
    surgeon_write($withoutStubs.'/vendor/bin/pint', "#!/usr/bin/env php\n<?php\n");
    surgeon_write($withoutStubs.'/src/Thing.php', "<?php\n");

    $adapter = new PintStubAdapter;

    expect($adapter->detect($withStubs))->toBeTrue()
        ->and($adapter->detect($withoutStubs))->toBeFalse();

    surgeon_rrmdir($withStubs);
    surgeon_rrmdir($withoutStubs);
});

it('does not walk vendor, node_modules or the scratch trees for stubs', function () {
    $root = stub_root('stub-scope', clean_stub());
    surgeon_write($root.'/vendor/acme/dep/database/migrations/vendored.php.stub', clean_stub());
    surgeon_write($root.'/node_modules/pkg/x.php.stub', clean_stub());
    surgeon_write($root.'/.scratch/notes/y.php.stub', clean_stub());

    $runner = stub_lint_runner(0, '{"tool":"pint","result":"passed"}');
    (new PintStubAdapter)->check($root, $runner);

    // The operands are everything after the binary and --test.
    $operands = array_slice($runner->calls[0]['command'], 2);

    expect($operands)->toBe(['database/migrations/create_widgets_table.php.stub']);

    surgeon_rrmdir($root);
});

it('passes each stub to pint as an explicit operand, because pint traversal cannot see the extension', function () {
    $root = stub_root('stub-operands', clean_stub());
    surgeon_write($root.'/database/migrations/shared/add_slug_to_widgets_table.php.stub', clean_stub());

    $runner = stub_lint_runner(0, '{"tool":"pint","result":"passed"}');
    (new PintStubAdapter)->check($root, $runner);

    expect($runner->calls[0]['command'])->toBe([
        $root.'/vendor/bin/pint',
        '--test',
        'database/migrations/create_widgets_table.php.stub',
        'database/migrations/shared/add_slug_to_widgets_table.php.stub',
    ]);

    surgeon_rrmdir($root);
});

// --- reporting -------------------------------------------------------------------------------------

it('names the drifting stubs in the detail, since the file fails at a different repo entirely', function () {
    $root = stub_root('stub-detail', clean_stub());

    $verdict = json_encode([
        'tool' => 'pint',
        'result' => 'fail',
        'files' => [
            ['path' => 'database/migrations/create_teams_table.php.stub', 'fixers' => ['ordered_imports']],
            ['path' => 'database/migrations/create_memberships_table.php.stub', 'fixers' => ['ordered_imports']],
        ],
    ]);

    $result = (new PintStubAdapter)->check($root, stub_lint_runner(1, (string) $verdict));

    expect($result->stack)->toBe('pint-stubs')
        ->and($result->hasViolations())->toBeTrue()
        ->and($result->violations)->toBe(2)
        ->and($result->fixable)->toBeTrue()
        ->and($result->detail)->toContain('create_teams_table.php.stub')
        ->and($result->detail)->toContain('create_memberships_table.php.stub');

    surgeon_rrmdir($root);
});

it('reports a crashed pint as unrunnable rather than as a clean stub population', function () {
    $root = stub_root('stub-crash', clean_stub());

    $result = (new PintStubAdapter)->check($root, stub_lint_runner(255, 'PHP Fatal error: Allowed memory size exhausted'));

    expect($result->outcome)->toBe(LintResult::UNRUNNABLE)
        ->and($result->ran())->toBeFalse();

    surgeon_rrmdir($root);
});

it('ships pint-stubs among the default stacks, so pnpm surgeon reaches it with no per-package wiring', function () {
    $names = array_map(fn ($s) => $s->name(), LintStackRegistry::withDefaults()->all());

    expect($names)->toContain('pint-stubs');
});

// --- the real binary: the traversal blind spot, and the mutation -----------------------------------

it('proves the blind spot it exists to close: real pint over the directory sees nothing', function () {
    $binary = dirname(__DIR__, 2).'/vendor/bin/pint';
    if (! is_file($binary)) {
        $this->markTestSkipped('no vendored pint binary at this root');
    }

    $root = surgeon_tmp('stub-blindspot');
    surgeon_write($root.'/composer.json', '{"name":"acme/pkg"}');
    surgeon_write($root.'/database/migrations/create_widgets_table.php.stub', drifting_stub());

    $runner = new ProcessStackRunner;

    // Pint's own traversal of the directory holding a drifting stub: clean, because the walk keys on *.php.
    $traversal = $runner->run($root, [$binary, '--test', 'database/migrations']);
    expect($traversal->exitCode)->toBe(0);

    // The same bytes named as an operand: drift.
    $named = $runner->run($root, [$binary, '--test', 'database/migrations/create_widgets_table.php.stub']);
    expect($named->exitCode)->not->toBe(0)
        ->and($named->output)->toContain('create_widgets_table.php.stub');

    surgeon_rrmdir($root);
});

it('goes red on a broken stub naming that file, and green again when it is reverted', function () {
    $binary = dirname(__DIR__, 2).'/vendor/bin/pint';
    if (! is_file($binary)) {
        $this->markTestSkipped('no vendored pint binary at this root');
    }

    $root = surgeon_tmp('stub-mutation');
    surgeon_write($root.'/composer.json', '{"name":"acme/pkg"}');
    // A shim, not a copy: composer's bin proxy resolves the phar through its own __DIR__, so a copied
    // proxy cannot find it. Including the real proxy by absolute path keeps that resolution intact.
    surgeon_write($root.'/vendor/bin/pint', "#!/usr/bin/env php\n<?php\n\nreturn include ".var_export($binary, true).";\n");
    chmod($root.'/vendor/bin/pint', 0755);

    $stub = $root.'/database/migrations/create_widgets_table.php.stub';
    surgeon_write($stub, clean_stub());

    $adapter = new PintStubAdapter;
    $runner = new ProcessStackRunner;

    // Green.
    expect($adapter->check($root, $runner)->isClean())->toBeTrue();

    // Break it.
    file_put_contents($stub, drifting_stub());
    $red = $adapter->check($root, $runner);
    expect($red->hasViolations())->toBeTrue()
        ->and($red->detail)->toContain('create_widgets_table.php.stub');

    // Revert.
    file_put_contents($stub, clean_stub());
    expect($adapter->check($root, $runner)->isClean())->toBeTrue();

    surgeon_rrmdir($root);
});
