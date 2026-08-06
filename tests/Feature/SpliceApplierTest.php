<?php

use Illuminate\Support\Facades\Process;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Rewrite\PlannedEdit;
use Rushing\Surgeon\Rewrite\Psr4Resolver;
use Rushing\Surgeon\Rewrite\RewritePlanner;
use Rushing\Surgeon\Rewrite\SpliceApplier;

function edit(string $source, string $find, int $nth, string $replacement): PlannedEdit
{
    $pos = -1;
    for ($i = 0; $i < $nth; $i++) {
        $pos = strpos($source, $find, $pos + 1);
    }

    return new PlannedEdit('f.php', 'f.php', 1, $pos, $pos + strlen($find) - 1, $find, $replacement, ReferenceCategory::NameReference);
}

it('applies multiple splices in one file correctly regardless of edit order (descending-offset invariant)', function () {
    $src = '<?php $a = Foo; $b = Foo;';

    // First occurrence grows longer; if this splice ran before the later one, the later offset would
    // shift and corrupt. The applier sorts descending, so both land correctly whatever order in.
    $e1 = edit($src, 'Foo', 1, 'Longername');
    $e2 = edit($src, 'Foo', 2, 'Z');

    expect(SpliceApplier::spliceSource($src, [$e1, $e2]))->toBe('<?php $a = Longername; $b = Z;')
        ->and(SpliceApplier::spliceSource($src, [$e2, $e1]))->toBe('<?php $a = Longername; $b = Z;');
});

it('refuses to splice when the live bytes no longer match the recorded text (drift)', function () {
    $src = '<?php $a = Foo;';
    $stale = new PlannedEdit('f.php', 'f.php', 1, 10, 12, 'Bar', 'Z', ReferenceCategory::NameReference);

    expect(fn () => SpliceApplier::spliceSource($src, [$stale]))
        ->toThrow(RuntimeException::class, 'drift');
});

it('applies a relocation to disk: repoints references, moves the file, leaves valid PHP', function () {
    $root = surgeon_psr4_root('apply');

    try {
        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('App\\Old\\Widget', 'App\\New\\Widget'));
        $plan = (new RewritePlanner(new Psr4Resolver([$root])))->plan($report);

        (new SpliceApplier)->apply($plan);

        $consumer = file_get_contents($root.'/src/Consumer.php');
        expect($consumer)->toContain('use App\\New\\Widget;')
            ->and($consumer)->not->toContain('App\\Old\\Widget')
            ->and($consumer)->toContain('private Widget $widget')   // imported short name untouched
            ->and($consumer)->toContain('return Widget::class;');

        // The moved file relocated to its PSR-4 destination with the rewritten namespace line.
        expect(is_file($root.'/src/Old/Widget.php'))->toBeFalse()
            ->and(is_file($root.'/src/New/Widget.php'))->toBeTrue()
            ->and(file_get_contents($root.'/src/New/Widget.php'))->toContain('namespace App\\New;');

        // Both touched files still parse.
        foreach ([$root.'/src/Consumer.php', $root.'/src/New/Widget.php'] as $file) {
            $lint = Process::run([PHP_BINARY, '-l', $file]);
            expect($lint->successful())->toBeTrue();
        }
    } finally {
        surgeon_rrmdir($root);
    }
});

it('rolls a relocation back to exactly the pre-tool state (manifest-scoped)', function () {
    $root = surgeon_psr4_root('rollback');

    try {
        $before = [
            'consumer' => file_get_contents($root.'/src/Consumer.php'),
            'widget' => file_get_contents($root.'/src/Old/Widget.php'),
        ];

        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('App\\Old\\Widget', 'App\\New\\Widget'));
        $plan = (new RewritePlanner(new Psr4Resolver([$root])))->plan($report);

        $applier = new SpliceApplier;
        $manifest = $applier->apply($plan);
        $applier->rollback($manifest);

        expect(file_get_contents($root.'/src/Consumer.php'))->toBe($before['consumer'])
            ->and(is_file($root.'/src/New/Widget.php'))->toBeFalse()
            ->and(is_file($root.'/src/Old/Widget.php'))->toBeTrue()
            ->and(file_get_contents($root.'/src/Old/Widget.php'))->toBe($before['widget']);
    } finally {
        surgeon_rrmdir($root);
    }
});
