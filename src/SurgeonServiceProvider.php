<?php

namespace Rushing\Surgeon;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Rushing\Surgeon\Console\AuditCommand;
use Rushing\Surgeon\Console\MoveCommand;
use Rushing\Surgeon\Console\PingCommand;
use Rushing\Surgeon\Console\ReplayCommand;

/**
 * Laravel entry point for rushing/laravel-surgeon — the deterministic refactor engine.
 *
 * Foundation tier (no Splicewire/schemastud dependency): it ships artisan commands, so any
 * tier that composes it inherits the `surgeon:*` family (the "different package levels" outcome
 * is emergent — no per-tier wiring). It registers the {@see PingCommand} smoke command, the
 * read-only {@see AuditCommand} (ticket 05), the Tier-1 writer {@see MoveCommand} (ticket 09), and
 * the golden-replay acceptance harness {@see ReplayCommand} (ticket 06). Commands register only in
 * console so a mere web/queue boot that has the provider installed pays nothing.
 */
class SurgeonServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PingCommand::class,
                AuditCommand::class,
                MoveCommand::class,
                ReplayCommand::class,
            ]);
        }
    }
}
