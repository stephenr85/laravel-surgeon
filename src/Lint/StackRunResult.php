<?php

namespace Rushing\Surgeon\Lint;

/**
 * The raw outcome of a {@see StackRunner} subprocess (ticket 12): the exit code and the combined output. A
 * {@see LintStack} adapter maps this into a {@see LintResult} (its whole check/fix mapping is pure over
 * this value, so a fake runner returning a canned `StackRunResult` unit-tests the adapter without a real
 * process). {@see $ran} is false when the binary was absent — an honest skip, not a failure.
 */
class StackRunResult
{
    public function __construct(
        public int $exitCode,
        public string $output = '',
        public bool $ran = true,
    ) {}

    public static function notRun(string $reason = ''): self
    {
        return new self(-1, $reason, false);
    }

    public function successful(): bool
    {
        return $this->ran && $this->exitCode === 0;
    }
}
