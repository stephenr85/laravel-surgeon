<?php

use Laravel\Mcp\Server\Registrar;
use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\LocalMcpWiringAudit;
use Rushing\Surgeon\Mcp\SurgeonMcpServer;

/**
 * The local-MCP-wiring audit: a registered Mcp::local() handle missing from .mcp.json. Both readers are
 * injected — no real Registrar boot, no real filesystem — the same posture as CanonicalizationAudit's
 * injectable git reader.
 */
it('passes silently when no local MCP servers are registered', function () {
    $audit = new LocalMcpWiringAudit(
        appRoot: '/irrelevant',
        registeredHandles: fn () => [],
        wiredHandles: fn () => ['unused'],
    );

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(1);
    expect($findings[0]->finding->status)->toBe(DoctorStatus::Pass);
    expect($findings[0]->finding->check)->toBe('mcp-wiring.no-local-servers');
});

it('passes with a distinct check when there is no .mcp.json to check against', function () {
    $audit = new LocalMcpWiringAudit(
        appRoot: '/irrelevant',
        registeredHandles: fn () => ['surgeon'],
        wiredHandles: fn () => null,
    );

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(1);
    expect($findings[0]->finding->status)->toBe(DoctorStatus::Pass);
    expect($findings[0]->finding->check)->toBe('mcp-wiring.no-config');
});

it('passes when every registered handle is present in .mcp.json', function () {
    $audit = new LocalMcpWiringAudit(
        appRoot: '/irrelevant',
        registeredHandles: fn () => ['surgeon', 'splicewire'],
        wiredHandles: fn () => ['surgeon', 'splicewire', 'playwright'],
    );

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(1);
    expect($findings[0]->finding->status)->toBe(DoctorStatus::Pass);
    expect($findings[0]->finding->check)->toBe('mcp-wiring.all-wired');
});

it('fails with an advisory suggestion for a registered handle missing from .mcp.json', function () {
    $audit = new LocalMcpWiringAudit(
        appRoot: '/irrelevant',
        mcpConfigPath: '.mcp.json',
        registeredHandles: fn () => ['surgeon', 'splicewire'],
        wiredHandles: fn () => ['surgeon'], // splicewire is genuinely missing
    );

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(1);
    $finding = $findings[0];
    expect($finding->finding->status)->toBe(DoctorStatus::Fail);
    expect($finding->finding->check)->toBe('mcp-wiring.unwired-local-server');
    expect($finding->finding->detail)->toContain('splicewire')
        ->toContain('.mcp.json');

    expect($finding->suggestion)->not->toBeNull();
    expect($finding->suggestion->isAdvisory())->toBeTrue();
    expect($finding->suggestion->owningPackage)->toBe('rushing/laravel-surgeon');
    expect($finding->suggestion->summary)->toContain('mcp:start splicewire');
});

it('reports one finding per unwired handle, leaving wired handles silent', function () {
    $audit = new LocalMcpWiringAudit(
        appRoot: '/irrelevant',
        registeredHandles: fn () => ['a', 'b', 'c'],
        wiredHandles: fn () => ['b'],
    );

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(2);
    $handles = array_map(fn ($f) => $f->finding->detail, $findings);
    expect(implode(' ', $handles))->toContain("'a'")->toContain("'c'")->not->toContain("'b'");
});

it('reads real Mcp::local() registrations and a real .mcp.json off disk end to end', function () {
    $root = surgeon_tmp('mcp-wiring');

    surgeon_write($root.'/.mcp.json', json_encode([
        'mcpServers' => [
            'surgeon' => ['command' => 'herd', 'args' => ['php', 'artisan', 'mcp:start', 'surgeon']],
        ],
    ]));

    $registrar = new Registrar;
    $registrar->local('surgeon', SurgeonMcpServer::class);
    $registrar->local('splicewire', SurgeonMcpServer::class); // stand-in class, only the handle matters

    $audit = new LocalMcpWiringAudit(
        appRoot: $root,
        registeredHandles: function () use ($registrar) {
            $handles = [];
            foreach ($registrar->servers() as $handle => $server) {
                if ($server instanceof Closure) {
                    $handles[] = $handle;
                }
            }

            return $handles;
        },
    );

    $findings = $audit->suggestOperations();

    expect($findings)->toHaveCount(1);
    expect($findings[0]->finding->status)->toBe(DoctorStatus::Fail);
    expect($findings[0]->finding->detail)->toContain('splicewire');

    surgeon_rrmdir($root);
});

it('is wired into BuiltInAudits alongside the DTO audits', function () {
    $audits = (new BuiltInAudits('/irrelevant'))->audits();

    $classes = array_map(fn ($a) => $a::class, $audits);

    expect($classes)->toContain(LocalMcpWiringAudit::class);
});
