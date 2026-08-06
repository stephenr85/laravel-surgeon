<?php

use Rushing\Surgeon\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * A throwaway directory under the system temp root for a rewriter test — the writer mutates files,
 * so tests operate on a fresh disposable tree, never on the committed fixtures. Vary by the caller's
 * label so parallel tests don't collide; the caller cleans up with {@see surgeon_rrmdir()}.
 */
function surgeon_tmp(string $label): string
{
    $dir = sys_get_temp_dir().'/surgeon-'.$label.'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    return $dir;
}

/** Write a file, creating parent directories — for standing up a temp fixture tree. */
function surgeon_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $contents);
}

/** Recursively remove a temp tree. */
function surgeon_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * Stand up a minimal PSR-4 root (`App\ => src/`) with a moveable `App\Old\Widget` and a consumer
 * that imports it — the fixture the apply + physical-move tests relocate. Returns the root path.
 */
function surgeon_psr4_root(string $label): string
{
    $root = surgeon_tmp($label);
    surgeon_write($root.'/composer.json', json_encode([
        'name' => 'surgeon/apply-fixture',
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ], JSON_PRETTY_PRINT));

    surgeon_write($root.'/src/Old/Widget.php', <<<'PHP'
        <?php

        namespace App\Old;

        class Widget
        {
            public string $label = 'widget';
        }

        PHP);

    surgeon_write($root.'/src/Consumer.php', <<<'PHP'
        <?php

        namespace App;

        use App\Old\Widget;

        class Consumer
        {
            public function __construct(private Widget $widget) {}

            public function name(): string
            {
                return Widget::class;
            }
        }

        PHP);

    return $root;
}
