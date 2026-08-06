<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Surgeon\Overlay\CanonicalizationAudit;

/**
 * The subprocess boundary a {@see LintStack} adapter runs its check/fix command through (ticket 12) — the
 * same discipline the rest of surgeon keeps: the *derivation* logic (which command, how to read its exit
 * code into a {@see LintResult}) is pure and unit-tested; the actual process launch lives behind this thin
 * seam so a test injects a fake and the adapter's mapping is exercised without ever shelling out.
 *
 * Mirrors the JSON-edit-vs-composer-effect split ticket 10/11 keep (pure logic testable, subprocess in the
 * command) and the injectable git reader {@see CanonicalizationAudit} uses. The
 * default {@see ProcessStackRunner} runs argv-array (no shell) so a root path is passed as data.
 */
interface StackRunner
{
    /**
     * Run an argv command in the given working directory.
     *
     * @param  string  $cwd  the working directory (a root)
     * @param  list<string>  $command  argv (no shell)
     * @return StackRunResult exit code + captured output
     */
    public function run(string $cwd, array $command): StackRunResult;
}
