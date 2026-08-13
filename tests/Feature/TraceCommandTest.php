<?php

use Illuminate\Contracts\Console\Kernel;

function estateRoot(): string
{
    return dirname(__DIR__).'/Fixtures/estate';
}

it('registers surgeon:trace', function () {
    expect(array_key_exists('surgeon:trace', app(Kernel::class)->all()))->toBeTrue();
});

it('rejects an ambiguous target selection', function () {
    $this->artisan('surgeon:trace', [
        '--symbol' => 'Vendor\\Old\\Widget',
        '--namespace' => 'Vendor\\Old',
        '--root' => [estateRoot()],
    ])->assertExitCode(2); // Command::INVALID
});

it('runs the read-only audit and lists touch-points by tier', function () {
    $this->artisan('surgeon:trace', [
        '--symbol' => 'Vendor\\Old\\Widget',
        '--root' => [estateRoot()],
    ])
        ->expectsOutputToContain('Tier 1')
        ->expectsOutputToContain('Tier 3')
        ->assertExitCode(0);
});

it('exits non-zero when a relocation has cycle risk', function () {
    $roots = [dirname(__DIR__).'/Fixtures/graph/app', dirname(__DIR__).'/Fixtures/graph/low'];

    $this->artisan('surgeon:trace', [
        '--symbol' => 'Acme\\Shared\\Thing',
        '--to' => 'Acme\\App\\Thing',
        '--root' => $roots,
    ])->assertExitCode(1);
});

it('emits the machine-readable finding-set to a file with --out', function () {
    $out = sys_get_temp_dir().'/surgeon-audit-'.getmypid().'.json';
    @unlink($out);

    $this->artisan('surgeon:trace', [
        '--symbol' => 'Vendor\\Old\\Widget',
        '--root' => [estateRoot()],
        '--out' => $out,
    ])->assertExitCode(0);

    expect(file_exists($out))->toBeTrue();
    $decoded = json_decode(file_get_contents($out), true);
    expect($decoded['summary']['total'])->toBe(20)
        ->and($decoded['references'])->toBeArray();

    @unlink($out);
});
