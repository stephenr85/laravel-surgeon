<?php

use Illuminate\Contracts\Console\Kernel;
use PhpParser\ParserFactory;
use Rushing\Graphine\Testing\SeamGuard;
use Rushing\Surgeon\Console\PingCommand;

it('boots the provider and registers surgeon:ping', function () {
    expect(array_key_exists('surgeon:ping', app(Kernel::class)->all()))->toBeTrue();
});

it('runs the smoke command end-to-end', function () {
    $this->artisan('surgeon:ping')
        ->expectsOutputToContain('pong')
        ->assertExitCode(0);
});

it('has the AST substrate available', function () {
    expect(class_exists(SeamGuard::class))->toBeTrue()
        ->and(class_exists(ParserFactory::class))->toBeTrue()
        ->and(class_exists(PingCommand::class))->toBeTrue();
});
