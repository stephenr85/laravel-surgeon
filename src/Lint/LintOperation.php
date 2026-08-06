<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Surgeon\Console\LintCommand;
use Rushing\Surgeon\Operation\Operation;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Overlay\OverlayOperation;

/**
 * Cross-stack `lint --fix` as a first-class {@see Operation} (ticket 12) — the fourth operation family
 * after relocation, overlay, and canonicalize, and the third non-AST one. {@see LintAudit} nominates this
 * op by {@see kind()} (`lint`) when it diagnoses deterministically-fixable style drift in an owned root;
 * {@see LintCommand}'s `--fix` performs it under ticket 03's writer posture (its own touch-manifest).
 *
 * Pure identity, like {@see OverlayOperation} / the canonicalize op: the perform
 * work lives on {@see LintOrchestrator} + {@see LintCommand} (the thin {@see Operation} interface hoists no
 * plan/apply signature — ticket 07 decision 1).
 *
 * A writer — but with a load-bearing safety nuance: it is a *separate* write-op from `move`/`canonicalize`
 * so a formatter never reformats inside those (ticket 08 decision 4's check-vs-fix split). The gate runs
 * lint in check-mode only; this op is the deliberate, standalone automated reformat pass.
 */
class LintOperation implements Operation
{
    public function kind(): string
    {
        return 'lint';
    }

    public function describe(): string
    {
        return 'Deterministically reformat an owned root with its stack\'s formatter (surgeon:lint --fix).';
    }

    public function isWriter(): bool
    {
        return true;
    }

    /** The suggestion a lint drift finding pairs with — nominates this op for the given stack + root. */
    public function suggestFor(string $stack, string $root): OperationSuggestion
    {
        return new OperationSuggestion(
            $this->kind(),
            "Run {$stack} --fix on {$root} to reformat the drift.",
            ['stack' => $stack, 'root' => $root],
        );
    }
}
