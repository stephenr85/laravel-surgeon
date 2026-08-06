<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Diagnoses cross-stack style drift across the owned roots (ticket 12) — the fourth {@see SuggestsOperations}
 * audit, and the third non-AST proof of the audit spine (after overlay + canonicalize). It runs the
 * {@see LintOrchestrator} in **check-mode** (never reformats) and maps each {@see LintResult} to a doctor
 * {@see Finding} paired, through the ticket-07/13 bridge, with a {@see LintOperation} suggestion where the
 * stack's drift is deterministically fixable:
 *
 *  - **clean** → **Pass** (a lone aggregate pass surfaces only when nothing else fired).
 *  - **violations, fixable** → **Warn** + nominates `surgeon:lint --fix` (the deterministic case — Pint
 *    drift, eslint's auto-fixable subset). This is the deterministic side of the seam.
 *  - **violations, NOT fixable** → **Warn**, NO suggestion (advisory) — a non-deterministic formatter's
 *    leftovers stay agent territory (the seam the map insists every ticket preserve: the automated `--fix`
 *    is the deterministic pass, its residue is the agent's).
 *  - **skipped** → **Pass** with the honest skip reason (the stack didn't apply / its binary was absent) —
 *    reported, never faked into a failure, mirroring the deterministic gate's guarded stages.
 *
 * The subprocess is injectable (the orchestrator carries a {@see StackRunner}), so the whole result→Finding
 * mapping unit-tests with a fake runner — no real Pint/eslint needed.
 */
class LintAudit implements SuggestsOperations
{
    /** @param  list<string>  $roots  the owned roots to lint (app-only by default; cross-root by opt-in) */
    public function __construct(
        public array $roots,
        private LintOrchestrator $orchestrator = new LintOrchestrator,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        if ($this->roots === []) {
            return [new FixableFinding(Finding::pass('lint.no-roots', 'No roots to lint.'))];
        }

        $run = $this->orchestrator->check($this->roots);

        if ($run->results === []) {
            return [new FixableFinding(Finding::pass('lint.no-stacks', 'No lint stacks apply to the given root(s).'))];
        }

        $op = new LintOperation;
        $findings = [];

        foreach ($run->results as $result) {
            $where = $result->stack.' @ '.basename($result->root);

            if ($result->isSkipped()) {
                $findings[] = new FixableFinding(Finding::pass(
                    'lint.skipped',
                    "{$where}: skipped — {$result->detail}",
                ));

                continue;
            }

            if ($result->isClean()) {
                $findings[] = new FixableFinding(Finding::pass(
                    'lint.clean',
                    "{$where}: no style drift.",
                ));

                continue;
            }

            // Violations.
            if ($result->fixable) {
                $findings[] = new FixableFinding(
                    Finding::warn('lint.drift', "{$where}: {$result->violations} style violation(s) — deterministically fixable."),
                    $op->suggestFor($result->stack, $result->root),
                );

                continue;
            }

            // Non-deterministic leftover — advisory only, no fix nominated (agent territory).
            $findings[] = new FixableFinding(
                Finding::warn('lint.drift-advisory', "{$where}: {$result->violations} non-auto-fixable problem(s) — advisory (agent handles)."),
                OperationSuggestion::advisory(
                    "{$where}: style problems with no deterministic fixer — an agent reviews these.",
                    'rushing/laravel-surgeon',
                ),
            );
        }

        return $findings;
    }
}
