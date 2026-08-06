<?php

namespace Rushing\Surgeon;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Rushing\Surgeon\Console\AuditCommand;
use Rushing\Surgeon\Console\PingCommand;

/**
 * Laravel entry point for rushing/laravel-surgeon — the deterministic refactor engine.
 *
 * Foundation tier (no Splicewire/schemastud dependency): it ships artisan commands, so any
 * tier that composes it inherits the `surgeon:*` family (the "different package levels" outcome
 * is emergent — no per-tier wiring). Today it registers only the {@see PingCommand} smoke command;
 * the audit + move commands land as their tickets graduate. Commands register only in console so a
 * mere web/queue boot that has the provider installed pays nothing.
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
            ]);
        }
    }
}
