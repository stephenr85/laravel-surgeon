<?php

namespace Rushing\Surgeon;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Rushing\McpRegistry\Bridge\ArtisanCommandReflector;
use Rushing\Surgeon\Console\AuditCommand;
use Rushing\Surgeon\Console\CanonicalizeCommand;
use Rushing\Surgeon\Console\LintCommand;
use Rushing\Surgeon\Console\MoveCommand;
use Rushing\Surgeon\Console\OverlayCommand;
use Rushing\Surgeon\Console\PingCommand;
use Rushing\Surgeon\Console\ReplayCommand;
use Rushing\Surgeon\Console\RewriteCommand;
use Rushing\Surgeon\Console\TraceCommand;
use Rushing\Surgeon\Mcp\SurgeonMcpServer;
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
 * the co-dev-overlay operation family {@see OverlayCommand} (ticket 10 — the first non-AST writer), the
 * overlay-inverse {@see CanonicalizeCommand} (ticket 11 — go shippable / git-resolve the lock), and the
 * cross-stack lint orchestration {@see LintCommand} (ticket 12 — Pint / eslint / … across the owned roots,
 * check by default, `--fix` as a separate reformat write-op; also a gate stage-4).
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
        // `surgeon.mcp.granted_abilities` was read by SurgeonMcpServer without this package ever
        // shipping or merging a file that defines it, so the root resolved to null. The inline `[]`
        // default made that harmless — default-DENY is the intended floor — but it also meant a host
        // had no declared, discoverable place to grant an ability. Merging the file makes the knob real
        // without changing the default.
        $this->mergeConfigFrom(__DIR__.'/../config/surgeon.php', 'surgeon');

        $this->app->bindIf(ConformanceManifest::class, NullConformanceManifest::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/surgeon.php' => config_path('surgeon.php'),
            ], 'surgeon-config');

            $this->commands([
                PingCommand::class,
                TraceCommand::class,
                AuditCommand::class,
                MoveCommand::class,
                ReplayCommand::class,
                OverlayCommand::class,
                CanonicalizeCommand::class,
                LintCommand::class,
                RewriteCommand::class,
            ]);
        }

        $this->registerMcpServer();
    }

    /**
     * Self-register the reflected surgeon MCP server ({@see SurgeonMcpServer}),
     * guarded on laravel/mcp actually being installed. laravel/mcp + rushing/laravel-mcp-registry are
     * optional (`suggest`/require-dev): a host without them autoloads nothing here and pays nothing —
     * a host that composes surgeon + laravel/mcp gets `mcp:start surgeon` for free, no per-app wiring.
     * The registry bridge is the reflection engine the server mounts; both must be present.
     */
    protected function registerMcpServer(): void
    {
        if (! class_exists(Mcp::class)
            || ! class_exists(ArtisanCommandReflector::class)) {
            return;
        }

        Mcp::local('surgeon', SurgeonMcpServer::class);
    }
}
