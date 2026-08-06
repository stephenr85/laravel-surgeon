<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use PhpParser\ParserFactory;
use Rushing\Graphine\Testing\SeamGuard;

/**
 * A trivial end-to-end smoke command proving the provider + command registration work from any
 * host that composes the package — and that the two load-bearing substrate deps (graphine's
 * {@see SeamGuard} and nikic/php-parser) resolve and are usable. It performs no refactor; it is the
 * "is the surgeon scrubbed in?" check. Real operations (`surgeon:trace`, `surgeon:audit`, `surgeon:move`) land later.
 */
class PingCommand extends Command
{
    protected $signature = 'surgeon:ping';

    protected $description = 'Smoke-check that rushing/laravel-surgeon is wired in and its AST substrate resolves.';

    public function handle(): int
    {
        // Prove the nikic parser substrate is present and parses.
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $parsed = $parser->parse("<?php echo 'pong';") !== null;

        // Prove graphine's SeamGuard (the find-half scanner ticket 05 extends) is autoloadable.
        $scannerReady = class_exists(SeamGuard::class);

        if (! $parsed || ! $scannerReady) {
            $this->error('surgeon: substrate NOT ready (parser or SeamGuard missing).');

            return self::FAILURE;
        }

        $this->info('surgeon: pong — provider registered, nikic/php-parser + graphine SeamGuard resolve.');

        return self::SUCCESS;
    }
}
