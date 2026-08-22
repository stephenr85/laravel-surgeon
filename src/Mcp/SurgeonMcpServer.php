<?php

namespace Rushing\Surgeon\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Rushing\McpRegistry\Bridge\ArtisanCommandReflector;
use Rushing\McpRegistry\Bridge\CommandToolMeta;
use Rushing\McpRegistry\Concerns\AuthorizesTools;
use Rushing\McpRegistry\Concerns\BootsTransport;
use Rushing\McpRegistry\Concerns\DescribesTools;
use Rushing\McpRegistry\Concerns\RegistersGroups;
use Rushing\McpRegistry\ToolGroup;
use Rushing\Surgeon\SurgeonServiceProvider;

/**
 * A thin MCP server, shipped WITH rushing/laravel-surgeon, that exposes surgeon's `surgeon:*`
 * artisan commands as MCP tools BY REFLECTION — declaring a surgeon command IS declaring the
 * tool, with zero per-command code here. An 8th surgeon command surfaces as a tool for free.
 *
 * The reflection machinery lives in rushing/laravel-mcp-registry (the generic, surgeon-blind
 * bridge). This class contributes ONLY the surgeon-specific read/write POLICY, which the
 * bridge deliberately does not own:
 *
 *   - Read / preview commands are exposed UNGATED and annotated IsReadOnly:
 *     surgeon:trace, surgeon:audit, surgeon:replay, surgeon:ping, surgeon:fingerprint, plus the read modes of
 *     surgeon:overlay (list/diff), surgeon:canonicalize (dry-run default) and surgeon:lint
 *     (check default). For the three read-defaulting-but-write-capable commands we SUPPRESS
 *     the write flags (--apply / --fix / --push / --composer) so the MCP surface can never
 *     make a writer easier to fire than the CLI — it keeps surgeon's own preview-by-default
 *     safety intact.
 *
 *   - Writers are annotated IsDestructive and GATED behind the `surgeon.write` ability
 *     (default-deny unless a host binds an authorizer): surgeon:move (the Tier-1 relocation
 *     apply). Its destructive --apply flag stays reachable only on this gated, destructive
 *     tool.
 *
 * {@see SurgeonServiceProvider} self-registers this server (guarded on the
 * laravel/mcp facade being installed), so any host composing surgeon + laravel/mcp gets
 * `mcp:start surgeon` for free — no per-app registration line. laravel/mcp and
 * rushing/laravel-mcp-registry are optional (`suggest`/require-dev): the class autoloads
 * lazily, so a host without them pays nothing and the provider simply skips registration.
 */
#[Name('surgeon')]
#[Version('1.0.0')]
#[Instructions('Surgeon is a deterministic refactoring surgeon for a PHP/Laravel estate. Read tools (trace/audit/replay) enumerate and classify symbol touch-points and conformance findings as machine-readable JSON; the write tool (move) applies a resolved relocation under a safety contract and is gated. Start with trace or audit to understand the blast radius before any move.')]
class SurgeonMcpServer extends Server
{
    use AuthorizesTools;
    use BootsTransport;
    use DescribesTools;
    use RegistersGroups;

    /**
     * The ability a caller must hold to see or run a surgeon WRITE tool. Default-deny:
     * with no authorizer bound the gate refuses, so a writer is never silently runnable.
     */
    public const WRITE_ABILITY = 'surgeon.write';

    /**
     * The artisan name-prefix reflected into the tool surface.
     */
    public const PREFIX = 'surgeon:';

    /**
     * @var array<int, string>
     */
    protected array $tools = [];

    protected function boot(): void
    {
        // stdio → unbounded; HTTP → generous-but-bounded.
        $this->bootTransport(600);

        // Mount the reflected surgeon group. Instances (not class-strings) flow straight
        // into $tools — laravel/mcp resolves them verbatim.
        $this->tools = $this->mountGroup();
    }

    /**
     * The one reflected group. The per-command policy encodes the surgeon read/write
     * doctrine; the reflector turns each `surgeon:*` command into a configured tool.
     *
     * @return array<int, ToolGroup>
     */
    public function groups(): array
    {
        $reflector = app(ArtisanCommandReflector::class)->withPolicy(
            fn (string $name): ?CommandToolMeta => $this->policyFor($name),
        );

        return [
            $reflector->group('surgeon', self::PREFIX),
        ];
    }

    /**
     * The surgeon read/write policy, per command. Returning null would drop a command
     * from the surface; every surgeon command is exposed, so this never returns null.
     */
    protected function policyFor(string $name): CommandToolMeta
    {
        return match ($name) {
            // The one true writer — destructive + gated; --apply stays reachable here only.
            'surgeon:move' => (new CommandToolMeta)->destructive(self::WRITE_ABILITY),

            // Read-defaulting but write-capable — exposed read-only with the write flags
            // suppressed, so a write verb can at most PREVIEW through MCP (never --apply/--fix).
            'surgeon:overlay' => (new CommandToolMeta)->readOnly()->suppress(['apply', 'composer']),
            'surgeon:canonicalize' => (new CommandToolMeta)->readOnly()->suppress(['apply', 'push', 'relink']),
            'surgeon:lint' => (new CommandToolMeta)->readOnly()->suppress(['fix']),

            // Pure reads / smoke-check.
            default => (new CommandToolMeta)->readOnly(),
        };
    }

    /**
     * The surgeon write gate. Default-DENY: a writer tool (annotated destructive, gated on
     * WRITE_ABILITY) is invisible in tools/list and refused before tools/call unless a host
     * binds an authorizer that grants `surgeon.write`. Read tools are ungated and unaffected.
     *
     * A host binds a real gate by overriding this (e.g. off an authenticated capability); the
     * default here is the safe floor — read-only surgeon over MCP until write is deliberately
     * enabled.
     *
     * @return callable(string, string): bool
     */
    protected function toolAuthorizer(): callable
    {
        $granted = (array) config('surgeon.mcp.granted_abilities', []);

        return static fn (string $ability, string $tool): bool => in_array($ability, $granted, true);
    }
}
