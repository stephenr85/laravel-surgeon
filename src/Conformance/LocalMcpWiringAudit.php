<?php

namespace Rushing\Surgeon\Conformance;

use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Surgeon\SurgeonServiceProvider;

/**
 * A standing, surgeon-native conformance audit catching a real drift shape: a package or app self-registers
 * a local MCP server at Laravel boot time ({@see Mcp}`::local($handle, $serverClass)`
 * — surgeon does exactly this for itself, {@see SurgeonServiceProvider::registerMcpServer()}),
 * but the *consuming dev session's* `.mcp.json` (the file Claude Code reads to know which MCP servers to
 * connect to) never got told about it. The Laravel-side registration is live and correct; the tool surface
 * it should expose is simply unreachable — dead weight nobody notices because nothing errors.
 *
 * **Detection is 100% deterministic and reads the TRUE runtime state, not source text.** It asks the booted
 * `Laravel\Mcp\Server\Registrar` which handles are actually registered via `Mcp::local()` (the honest
 * runtime read of the booted registry — the same posture as this package's other runtime-registry reads),
 * then diffs that set against the `mcpServers` keys in `.mcp.json`. A registered handle absent from the
 * config file is a real, mechanically-detectable gap.
 *
 * **The fix stays advisory, deliberately.** Unlike a relocation or a stale-duplicate retire, there is no
 * operation here that can safely auto-write the correct `.mcp.json` entry: the right `command`/`args`/`env`
 * (a plain `php artisan`, an `herd php` wrapper, `XDEBUG_MODE=off`, a Docker/Sail exec, …) is host-specific
 * judgment nobody has asked this audit to infer. So a gap emits a **Fail** Finding (it IS broken — the tool
 * surface is genuinely unreachable) paired with an {@see OperationSuggestion::advisory()} naming the exact
 * `php artisan mcp:start <handle>` invocation a human wires in by hand. A future `mcp-wiring` operation
 * family (peer to Overlay's `composer.local.json` mutation) is the natural place this graduates to if the
 * shape repeats — the advisory names `rushing/laravel-surgeon` as that owner.
 *
 * Foundation-clean: `laravel/mcp` is an optional (`require-dev`/`suggest`) dependency of this package, so
 * every touch of `Laravel\Mcp\Server\Registrar` is behind a `class_exists()` guard — a host without
 * laravel/mcp installed simply has no local servers to find, and this audit passes silently.
 */
class LocalMcpWiringAudit implements SuggestsOperations
{
    /** @var callable(): list<string> */
    private $registeredHandles;

    /** @var callable(): list<string>|null */
    private $wiredHandles;

    /**
     * @param  string  $appRoot  the running host's app root (holds the `.mcp.json` to check)
     * @param  string  $mcpConfigPath  the config file, relative to $appRoot (default `.mcp.json`)
     * @param  (callable(): list<string>)|null  $registeredHandles  reader for the booted Mcp::local() handles (default: the real Registrar)
     * @param  (callable(): list<string>|null)|null  $wiredHandles  reader for `.mcp.json`'s `mcpServers` keys, null when the file is absent (default: real file read)
     */
    public function __construct(
        public string $appRoot,
        public string $mcpConfigPath = '.mcp.json',
        ?callable $registeredHandles = null,
        ?callable $wiredHandles = null,
    ) {
        $this->registeredHandles = $registeredHandles ?? fn (): array => $this->realRegisteredHandles();
        $this->wiredHandles = $wiredHandles ?? fn (): ?array => $this->realWiredHandles();
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $registered = ($this->registeredHandles)();
        if ($registered === []) {
            return [new FixableFinding(Finding::pass(
                'mcp-wiring.no-local-servers',
                'No Mcp::local() servers registered in this booted host — nothing to wire.',
            ))];
        }

        $wired = ($this->wiredHandles)();
        if ($wired === null) {
            return [new FixableFinding(Finding::pass(
                'mcp-wiring.no-config',
                "No {$this->mcpConfigPath} in this project — not using a project-scoped MCP client config, nothing to check.",
            ))];
        }

        $findings = [];
        foreach ($registered as $handle) {
            if (in_array($handle, $wired, true)) {
                continue;
            }

            $findings[] = new FixableFinding(
                Finding::fail(
                    'mcp-wiring.unwired-local-server',
                    "'{$handle}' is registered via Mcp::local() but missing from {$this->mcpConfigPath} — its mcp__{$handle}__* tools are unreachable in an MCP client session.",
                ),
                OperationSuggestion::advisory(
                    "Add a \"{$handle}\" entry to {$this->mcpConfigPath} pointing at `php artisan mcp:start {$handle}` (the exact command/env — e.g. an herd wrapper, XDEBUG_MODE — is host-specific and left to a human).",
                    'rushing/laravel-surgeon',
                ),
            );
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                'mcp-wiring.all-wired',
                count($registered).' registered local MCP server(s) all present in '.$this->mcpConfigPath.'.',
            ));
        }

        return $findings;
    }

    /** @return list<string> */
    private function realRegisteredHandles(): array
    {
        if (! class_exists(Registrar::class)) {
            return [];
        }

        /** @var Registrar $registrar */
        $registrar = app(Registrar::class);

        $handles = [];
        foreach ($registrar->servers() as $handle => $server) {
            if ($server instanceof \Closure) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /** @return list<string>|null null when the config file doesn't exist */
    private function realWiredHandles(): ?array
    {
        $path = rtrim($this->appRoot, '/').'/'.ltrim($this->mcpConfigPath, '/');
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || ! isset($decoded['mcpServers']) || ! is_array($decoded['mcpServers'])) {
            return [];
        }

        return array_keys($decoded['mcpServers']);
    }
}
