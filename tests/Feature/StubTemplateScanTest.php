<?php

use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\Tier;

/**
 * `.php.stub` publish-only migration templates are real source and the engine reads them.
 *
 * Written against a temp tree rather than `tests/Fixtures/estate` on purpose: adding a stub there
 * would shift the category and tier counts the two headline AuditEngineTest cases pin, and those
 * counts are the readable statement of what this engine classifies. A blind spot's regression test
 * should not blur the census it was hiding from.
 */
function stubTree(): string
{
    $root = sys_get_temp_dir().'/surgeon-stub-scan-'.uniqid();

    @mkdir($root.'/database/migrations/shared', 0777, true);
    @mkdir($root.'/src', 0777, true);
    @mkdir($root.'/resources/js', 0777, true);

    // The population that was invisible: a publish-only template importing a real symbol.
    file_put_contents($root.'/database/migrations/shared/create_widgets_table.php.stub', <<<'PHP'
        <?php

        use Vendor\Old\Widget;

        return new class
        {
            public function up(): void
            {
                Widget::provision();
            }
        };
        PHP);

    // An ordinary source file, so the test proves the stub ADDS to the walk rather than replacing it.
    file_put_contents($root.'/src/Consumer.php', <<<'PHP'
        <?php

        use Vendor\Old\Widget;

        class Consumer
        {
            public function widget(): Widget
            {
                return new Widget;
            }
        }
        PHP);

    // Stub dialects that are NOT PHP. The estate ships `nav.ts.stub`, `prototype-host.tsx.stub` and
    // bare `particle-resource.stub` templates carrying placeholder tokens; matching on the `stub`
    // EXTENSION rather than the `.php.stub` suffix would drag all of them into a PHP parser.
    file_put_contents($root.'/resources/js/nav.ts.stub', "import { Widget } from 'Vendor/Old/Widget';\n");
    file_put_contents($root.'/src/particle-resource.stub', "<?php\n\nclass {{ class }} extends Widget {{\n");

    return $root;
}

function rmTree(string $path): void
{
    foreach ((array) glob($path.'/*') as $entry) {
        is_dir((string) $entry) ? rmTree((string) $entry) : @unlink((string) $entry);
    }
    @rmdir($path);
}

it('enumerates references inside a .php.stub migration template', function () {
    $root = stubTree();

    $report = (new AuditEngine)->audit([$root], Target::symbol('Vendor\\Old\\Widget'));

    $files = array_values(array_unique(array_map(fn ($ref) => $ref->relativePath, $report->references())));

    expect($files)->toContain('database/migrations/shared/create_widgets_table.php.stub')
        ->and($files)->toContain('src/Consumer.php');

    rmTree($root);
});

/**
 * The load-bearing negative. Teaching the engine this extension closes the DISCOVERY gap and nothing
 * else: `refineByFileRole()` maps any path under `/migrations/` to a migration reference, which is
 * tier-2, which the writer declines — and every `.php.stub` in the estate lives under
 * `database/migrations/`. This is why the beam-facade effort's option B3 (hand edits) was right and
 * option B1 (teach the extension, then sweep) would still not have written a single one.
 */
it('classifies a stub template reference as tier-2, so the writer still declines it', function () {
    $root = stubTree();

    $report = (new AuditEngine)->audit([$root], Target::symbol('Vendor\\Old\\Widget'));

    $stubRefs = array_values(array_filter(
        $report->references(),
        fn ($ref) => str_ends_with($ref->relativePath, '.php.stub'),
    ));

    expect($stubRefs)->not->toBeEmpty();

    foreach ($stubRefs as $ref) {
        expect($ref->category)->toBe(ReferenceCategory::MigrationReference)
            ->and($ref->tier->value)->toBe(Tier::Two->value);
    }

    rmTree($root);
});

it('does not scan stub dialects that are not PHP', function () {
    $root = stubTree();

    $report = (new AuditEngine)->audit([$root], Target::symbol('Vendor\\Old\\Widget'));

    $files = array_map(fn ($ref) => $ref->relativePath, $report->references());

    expect($files)->not->toContain('resources/js/nav.ts.stub')
        ->and($files)->not->toContain('src/particle-resource.stub');

    rmTree($root);
});
