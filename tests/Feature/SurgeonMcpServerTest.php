<?php

use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Rushing\McpRegistry\Bridge\ArtisanCommandTool;
use Rushing\Surgeon\Mcp\SurgeonMcpServer;

/**
 * The surgeon MCP server (promoted into the package from the app) reflects every `surgeon:*`
 * command into a tool with ZERO per-command code, and carries the surgeon read/write policy:
 * reads are ungated + read-only, `surgeon:move` is destructive + gated behind `surgeon.write`.
 * These assertions read the reflected {@see ArtisanCommandTool} instances the server mounts —
 * no MCP transport needed.
 *
 * @return array<string, ArtisanCommandTool> tool-name => tool
 */
function surgeon_mcp_tools(): array
{
    $group = (new SurgeonMcpServer(new FakeTransporter))->groups()[0];

    $byName = [];
    foreach ($group->tools() as $tool) {
        $byName[$tool->name()] = $tool;
    }

    return $byName;
}

it('reflects every surgeon:* command into a tool with no per-command class', function () {
    $tools = surgeon_mcp_tools();

    // One reflected tool per registered surgeon command — trace/audit/move/replay/overlay/
    // canonicalize/lint/ping. Every value is the SAME generic bridge class, configured per command.
    expect($tools)->toHaveKeys(['surgeon_trace', 'surgeon_audit', 'surgeon_move', 'surgeon_lint'])
        ->and($tools['surgeon_trace'])->toBeInstanceOf(ArtisanCommandTool::class)
        ->and($tools['surgeon_move'])->toBeInstanceOf(ArtisanCommandTool::class);
});

it('picks up a newly added read command with no MCP-side edit', function () {
    // surgeon:env was added to the provider and to nothing else — it reaches the tool surface
    // through the default policy branch, which is the whole claim the reflection bridge makes.
    $env = surgeon_mcp_tools()['surgeon_env'];

    expect($env)->toBeInstanceOf(ArtisanCommandTool::class)
        ->and($env->toolAbility())->toBeNull()
        ->and($env->annotations())->toMatchArray(['readOnlyHint' => true])
        // The artisan signature IS the advertised schema — no Data class, no hand-authored tool.
        // --json is absent because the bridge forces it and returns structured content.
        ->and(JsonSchema::object($env->schema(...))->toArray()['properties'] ?? [])
        ->toHaveKeys(['path', 'example', 'strict'])
        ->and(JsonSchema::object($env->schema(...))->toArray()['properties'] ?? [])
        ->not->toHaveKey('json');
});

it('exposes read tools ungated and read-only', function () {
    $trace = surgeon_mcp_tools()['surgeon_trace'];

    expect($trace->toolAbility())->toBeNull()
        ->and($trace->annotations())->toMatchArray(['readOnlyHint' => true]);
});

it('gates surgeon:move as destructive behind the surgeon.write ability', function () {
    $move = surgeon_mcp_tools()['surgeon_move'];

    expect($move->toolAbility())->toBe(SurgeonMcpServer::WRITE_ABILITY)
        ->and($move->annotations())->toMatchArray(['destructiveHint' => true]);
});

it('suppresses the write flags off the read-defaulting write-capable commands', function () {
    $tools = surgeon_mcp_tools();

    // surgeon:lint is exposed read-only with --fix hidden, so MCP can never fire the writer.
    $lintSchema = JsonSchema::object($tools['surgeon_lint']->schema(...))->toArray();

    expect($lintSchema['properties'] ?? [])->not->toHaveKey('fix')
        ->and($tools['surgeon_lint']->annotations())->toMatchArray(['readOnlyHint' => true]);
});
