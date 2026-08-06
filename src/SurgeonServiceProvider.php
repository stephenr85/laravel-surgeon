<?php

namespace Rushing\Surgeon;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Rushing\Surgeon\Console\AuditCommand;
use Rushing\Surgeon\Console\CanonicalizeCommand;
use Rushing\Surgeon\Console\MoveCommand;
use Rushing\Surgeon\Console\OverlayCommand;
use Rushing\Surgeon\Console\PingCommand;
use Rushing\Surgeon\Console\ReplayCommand;
use Rushing\Surgeon\Console\TraceCommand;
use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Operation\NullConformanceManifest;

/**
 * Laravel entry point for rushing/laravel-surgeon — the deterministic refactor engine.
 *
 * Foundation tier (no Splicewire/schemastud dependency): it ships artisan commands, so any
 * tier that composes it inherits the `surgeon:*` family (the "different package levels" outcome
 * is emergent — no per-tier wiring). It registers the {@see PingCommand} smoke command, the
 * target-driven {@see TraceCommand} (ticket 05, was `surgeon:audit`), the conformance-sweep
 * {@see AuditCommand} (ticket 13 — the Finding→Operation bridge read of the doctor manifest), the
 * Tier-1 writer {@see MoveCommand} (ticket 09), the golden-replay {@see ReplayCommand} (ticket 06), and
 * the co-dev-overlay operation family {@see OverlayCommand} (ticket 10 — the first non-AST writer), and
 * the overlay-inverse {@see CanonicalizeCommand} (ticket 11 — go shippable / git-resolve the lock).
 * Commands register only in console so a mere web/queue boot that has the provider installed pays nothing.
 *
 * The {@see ConformanceManifest} discovery port defaults to {@see NullConformanceManifest} (a foundation
 * package can't reach up to a host's own doctor manifest); a host binds an adapter to opt surgeon into its
 * manifest (ticket 07, decision 6 — the running host context scopes discovery).
 */
class SurgeonServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ConformanceManifest::class, NullConformanceManifest::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PingCommand::class,
                TraceCommand::class,
                AuditCommand::class,
                MoveCommand::class,
                ReplayCommand::class,
                OverlayCommand::class,
                CanonicalizeCommand::class,
            ]);
        }
    }
}
