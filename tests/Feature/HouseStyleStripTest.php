<?php

use Rushing\Surgeon\HouseStyle\HouseStyleAudit;
use Rushing\Surgeon\HouseStyle\HouseStyleStripOperation;

/**
 * Determinism proof for the house-style strip: a fixture carrying all three forbidden constructs
 * (`declare(strict_types=1);`, `final readonly class`, a `public readonly int $x` promoted param, and a
 * `final public function bar()`) plans + applies to source that has all three removed and NOTHING else
 * changed — the byte-for-byte preservation property the token-aware planner + shared splicer guarantee.
 */
function house_style_fixture(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain;

use App\Contracts\Bar;

final readonly class Foo extends Bar
{
    public int $count = 0;

    public function __construct(
        public readonly int $x,
        protected readonly Bar $bar,
    ) {}

    final public function bar(): int
    {
        return $this->x;
    }

    public function readonly(): bool
    {
        return $this->readonly;
    }
}
PHP;
}

it('strips all three forbidden constructs and changes nothing else (deterministic)', function () {
    $op = new HouseStyleStripOperation;
    $source = house_style_fixture();

    $plan = $op->planSource($source);
    $result = $op->applyToSource($source, $plan);

    // All three constructs gone.
    expect($result)->not->toContain('declare(strict_types=1);')
        ->and($result)->not->toContain('final ')
        ->and($result)->not->toContain('readonly int')
        ->and($result)->not->toContain('readonly Bar');

    // The specific `final readonly class` → `class` collapse (no residual double space).
    expect($result)->toContain('class Foo extends Bar')
        ->and($result)->not->toContain('final readonly')
        ->and($result)->not->toContain('readonly class');

    // Promoted params keep visibility + type, lose only readonly.
    expect($result)->toContain('public int $x')
        ->and($result)->toContain('protected Bar $bar');

    // Method-level final gone, method body/signature intact.
    expect($result)->toContain('public function bar(): int')
        ->and($result)->not->toContain('final public function');

    // Everything NOT a forbidden construct is byte-identical — the strip only deleted the four spans.
    expect($result)->toContain('namespace App\Domain;')
        ->and($result)->toContain('use App\Contracts\Bar;')
        ->and($result)->toContain('public int $count = 0;')
        ->and($result)->toContain('return $this->x;')
        ->and($result)->toContain('return $this->readonly;');

    // The method NAMED readonly is NOT touched (the keyword-token modifier guard) — no over-strip.
    expect($result)->toContain('public function readonly(): bool');

    // Result is still valid PHP.
    $tmp = tempnam(sys_get_temp_dir(), 'hstyle').'.php';
    file_put_contents($tmp, $result);
    $lint = shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);
    expect($lint)->toContain('No syntax errors');
});

it('reconstructs the exact expected source (byte-for-byte deletion, nothing shifted)', function () {
    $op = new HouseStyleStripOperation;
    $source = house_style_fixture();

    $result = $op->applyToSource($source, $op->planSource($source));

    $expected = <<<'PHP'
<?php

namespace App\Domain;

use App\Contracts\Bar;

class Foo extends Bar
{
    public int $count = 0;

    public function __construct(
        public int $x,
        protected Bar $bar,
    ) {}

    public function bar(): int
    {
        return $this->x;
    }

    public function readonly(): bool
    {
        return $this->readonly;
    }
}
PHP;

    expect($result)->toBe($expected);
});

it('leaves declare(ticks=1) alone — only strict_types is stripped', function () {
    $op = new HouseStyleStripOperation;
    $source = "<?php\n\ndeclare(ticks=1);\n\nfunction f() {}\n";

    $result = $op->applyToSource($source, $op->planSource($source));

    expect($result)->toBe($source);
});

it('is idempotent — a second pass over already-stripped source is a no-op', function () {
    $op = new HouseStyleStripOperation;
    $once = $op->applyToSource(house_style_fixture(), $op->planSource(house_style_fixture()));

    $plan = $op->planSource($once);

    expect($plan->isEmpty())->toBeTrue()
        ->and($op->applyToSource($once, $plan))->toBe($once);
});

it('audit emits one fixable finding per offending file with a house-style-strip suggestion', function () {
    $dir = surgeon_tmp('house-style-audit');

    try {
        surgeon_write($dir.'/Offending.php', house_style_fixture());
        surgeon_write($dir.'/Clean.php', "<?php\n\nnamespace App;\n\nclass Clean {}\n");

        $findings = (new HouseStyleAudit([$dir]))->suggestOperations();
        $fixable = array_values(array_filter($findings, fn ($f) => $f->isFixable()));

        expect($fixable)->toHaveCount(1)
            ->and($fixable[0]->finding->check)->toBe('house-style.forbidden-modifier')
            ->and($fixable[0]->suggestion->kind)->toBe('house-style-strip')
            ->and($fixable[0]->suggestion->payload['file'])->toBe($dir.'/Offending.php');
    } finally {
        surgeon_rrmdir($dir);
    }
});
