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

it('applies a same-namespace class rename: declaration token, basename move, and short-name repoints', function () {
    $root = surgeon_psr4_root('cmd-rename');

    try {
        // A same-namespace rename of the moved class itself: App\Old\Widget → App\Old\Gadget.
        $from = writeFindingSet($root, 'App\\Old\\Widget', 'App\\Old\\Gadget');

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$root],
            '--no-composer' => true,
        ])->assertExitCode(Command::SUCCESS);

        // The declaration file moved basename (same dir) and its `class` token was rewritten.
        expect(is_file($root.'/src/Old/Gadget.php'))->toBeTrue()
            ->and(is_file($root.'/src/Old/Widget.php'))->toBeFalse();
        $decl = file_get_contents($root.'/src/Old/Gadget.php');
        expect($decl)->toContain('class Gadget')
            ->and($decl)->not->toContain('class Widget')
            ->and($decl)->toContain('namespace App\\Old;');

        // The consumer's `use` line and its short-name usages all repointed to Gadget.
        $consumer = file_get_contents($root.'/src/Consumer.php');
        expect($consumer)->toContain('use App\\Old\\Gadget;')
            ->and($consumer)->toContain('private Gadget $widget')
            ->and($consumer)->toContain('return Gadget::class;')
            ->and($consumer)->not->toContain('Widget');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('refuses when uncommitted changes overlap the planned edits', function () {
    $root = surgeon_psr4_root('cmd-dirty-overlap');

    try {
        $from = writeFindingSet($root, 'App\\Old\\Widget', 'App\\New\\Widget');
        surgeon_git_init($root);

        // Consumer.php is IN the plan — this is the case the gate exists for.
        file_put_contents($root.'/src/Consumer.php', file_get_contents($root.'/src/Consumer.php')."\n// a neighbour is mid-edit\n");
        $before = file_get_contents($root.'/src/Consumer.php');

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$root],
            '--no-composer' => true,
        ])->assertExitCode(Command::FAILURE);

        // Refused means refused: the neighbour's edit is untouched and nothing moved.
        expect(file_get_contents($root.'/src/Consumer.php'))->toBe($before)
            ->and(is_file($root.'/src/New/Widget.php'))->toBeFalse();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('proceeds when the root is dirty only outside the planned edits', function () {
    $root = surgeon_psr4_root('cmd-dirty-elsewhere');

    try {
        $from = writeFindingSet($root, 'App\\Old\\Widget', 'App\\New\\Widget');
        surgeon_git_init($root);

        // Dirt the plan never opens. Refusing on this is what made a multi-root sweep unpassable.
        surgeon_write($root.'/src/Unrelated.php', "<?php\n\nnamespace App;\n\nclass Unrelated {}\n");

        $this->artisan('surgeon:move', [
            '--from' => $from,
            '--apply' => true,
            '--root' => [$root],
            '--no-composer' => true,
        ])->assertExitCode(Command::SUCCESS);

        expect(file_get_contents($root.'/src/Consumer.php'))->toContain('use App\\New\\Widget;')
            // and the bystander is exactly as the neighbour left it
            ->and(file_get_contents($root.'/src/Unrelated.php'))->toContain('class Unrelated {}');
    } finally {
        surgeon_rrmdir($root);
    }
});
