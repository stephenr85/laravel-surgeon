<?php

use Illuminate\Console\Command;
use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\Target;

/** Produce a resolved finding-set on disk, exactly as `surgeon:trace --out` would. */
function writeFindingSet(string $root, string $old, string $new): string
{
    $report = (new AuditEngine)->audit([$root], Target::relocatingTo($old, $new));
    $path = $root.'/finding-set.json';
    file_put_contents($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $path;
}

it('previews without writing anything by default', function () {
    $root = surgeon_psr4_root('cmd-preview');

    try {
        $from = writeFindingSet($root, 'App\\Old\\Widget', 'App\\New\\Widget');
        $before = file_get_contents($root.'/src/Consumer.php');

        $this->artisan('surgeon:move', ['--from' => $from, '--root' => [$root], '--no-composer' => true])
            ->assertExitCode(Command::SUCCESS);

        expect(file_get_contents($root.'/src/Consumer.php'))->toBe($before)
            ->and(is_file($root.'/src/New/Widget.php'))->toBeFalse();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('applies the move and writes a touch-manifest under --apply', function () {
    $root = surgeon_psr4_root('cmd-apply');

    try {
        $from = writeFindingSet($root, 'App\\Old\\Widget', 'App\\New\\Widget');
        $manifest = $root.'/manifest.json';

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$root],
            '--manifest' => $manifest,
            '--no-composer' => true,
        ])->assertExitCode(Command::SUCCESS);

        expect(file_get_contents($root.'/src/Consumer.php'))->toContain('use App\\New\\Widget;')
            ->and(is_file($root.'/src/New/Widget.php'))->toBeTrue()
            ->and(is_file($root.'/src/Old/Widget.php'))->toBeFalse();

        $sidecar = json_decode(file_get_contents($manifest), true);
        expect($sidecar['files'])->toContain('src/Consumer.php')
            ->and($sidecar['move']['to'])->toBe('src/New/Widget.php');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('refuses without a --from resolved operation set (no inference)', function () {
    $this->artisan('surgeon:move', ['--apply' => true])
        ->assertExitCode(Command::INVALID);
});

it('refuses to apply a basename rename and writes nothing', function () {
    $root = surgeon_psr4_root('cmd-rename');

    try {
        $from = writeFindingSet($root, 'App\\Old\\Widget', 'App\\Old\\Gadget');
        $before = file_get_contents($root.'/src/Consumer.php');

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$root],
            '--no-composer' => true,
        ])->assertExitCode(Command::FAILURE);

        expect(file_get_contents($root.'/src/Consumer.php'))->toBe($before);
    } finally {
        surgeon_rrmdir($root);
    }
});
