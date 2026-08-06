<?php

use Rushing\Surgeon\Rewrite\LiteralRewriteOperation;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * The dogfood case (ADR-0124): an SDK request's `resolveEndpoint()` literal drifted from the app's real
 * route. {@see LiteralRewriteOperation} plans + applies the exact byte-splice that corrects it, reusing
 * the estate's {@see SpliceApplier} under its drift-refusal invariant.
 */
it('plans and applies a literal rewrite: repoints a drifted endpoint literal in place', function () {
    $root = surgeon_tmp('literal-rewrite');

    try {
        $file = $root.'/TriggerRender.php';
        surgeon_write($file, <<<'PHP'
            <?php

            class TriggerRender
            {
                public function resolveEndpoint(): string
                {
                    return '/api/v1/compositions/x/render';
                }
            }

            PHP);

        $operation = new LiteralRewriteOperation;
        $plan = $operation->plan([[
            'file' => $file,
            'oldText' => '/api/v1/compositions/x/render',
            'newText' => '/api/v1/splice/compositions/x/render',
        ]]);

        expect($operation->kind())->toBe('literal-rewrite')
            ->and($operation->isWriter())->toBeTrue()
            ->and($plan->edits)->toHaveCount(1)
            ->and($plan->edits[0]->line)->toBe(7); // the return line

        $touched = $operation->apply($plan, new SpliceApplier);

        expect($touched)->toBe([$file]);

        $rewritten = file_get_contents($file);
        expect($rewritten)->toContain("return '/api/v1/splice/compositions/x/render';")
            ->and($rewritten)->not->toContain("'/api/v1/compositions/x/render'")
            // surrounding source is byte-preserved
            ->and($rewritten)->toContain('public function resolveEndpoint(): string');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('refuses to plan a splice for a literal that is absent (fails safe, never guesses)', function () {
    $root = surgeon_tmp('literal-rewrite-absent');

    try {
        $file = $root.'/f.php';
        surgeon_write($file, "<?php return '/api/v1/other';");

        expect(fn () => (new LiteralRewriteOperation)->plan([[
            'file' => $file,
            'oldText' => '/api/v1/missing',
            'newText' => '/api/v1/whatever',
        ]]))->toThrow(RuntimeException::class, 'not found');
    } finally {
        surgeon_rrmdir($root);
    }
});
