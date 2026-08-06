<?php

use Rushing\Surgeon\Rewrite\DeterministicGate;

it('passes php -l on syntactically valid touched files', function () {
    $root = surgeon_tmp('gate-pass');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Good.php'], [$root]);

        expect($result->passed())->toBeTrue();
        $php = collect($result->stages)->firstWhere('stage', 'php -l');
        expect($php['status'])->toBe('pass');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('fails the gate when a touched file has broken syntax', function () {
    $root = surgeon_tmp('gate-fail');

    try {
        surgeon_write($root.'/Broken.php', "<?php\n\nclass Broken { public function x(  {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: false))
            ->run([$root.'/Broken.php'], [$root]);

        expect($result->passed())->toBeFalse()
            ->and($result->failures())->not->toBeEmpty();
    } finally {
        surgeon_rrmdir($root);
    }
});

it('honestly skips the topology stage for a relocation rather than fabricating a pass', function () {
    $root = surgeon_tmp('gate-topo');

    try {
        surgeon_write($root.'/Good.php', "<?php\n\nclass Good {}\n");

        $result = (new DeterministicGate(runComposer: false, runTopology: true))
            ->run([$root.'/Good.php'], [$root]);

        $topo = collect($result->stages)->firstWhere('stage', 'topology cycle-guard');
        expect($topo['status'])->toBe('skip')
            ->and($result->passed())->toBeTrue();
    } finally {
        surgeon_rrmdir($root);
    }
});
