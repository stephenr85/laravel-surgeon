<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Surgeon\Operation\OperationSuggestion;

/**
 * The adapter seam that holds surgeon's lint engine **stack-agnostic** (ticket 08 decision 4, ticket 12).
 * The engine ({@see LintOrchestrator}, {@see LintStackRegistry}, the gate integration) knows nothing about
 * PHP or JS; every stack-specific fact lives in one of these adapters. Ship a {@see PintAdapter} + one JS
 * adapter ({@see EslintAdapter}) as the proof-of-two; the rest ({@see PrettierAdapter}, a `TscAdapter`, …)
 * graduate as needed by dropping another implementation into the registry — no engine change.
 *
 * Mirrors doctor's `DoctorRegistration` shape (each package registers its conformance audit into a merged
 * manifest): a stack registers itself and the engine iterates. The three responsibilities:
 *
 *  - {@see name()} — the stable machine token (`pint`, `eslint`), the same token a {@see LintResult} and a
 *    lint {@see OperationSuggestion} carry.
 *  - {@see detect()} — the SNIFF: does this stack apply to this root? Pure filesystem reads (presence of
 *    `pint.json` / `composer.json` for Pint, `package.json` + an eslint config/devDep for eslint). The
 *    per-root `extra.surgeon.lint` override that corrects/suppresses a sniff is applied by the *registry*
 *    (it is engine policy, not per-adapter), so an adapter's `detect()` is pure signal, never override.
 *  - {@see check()} / {@see fix()} — run the stack in check-mode (report drift, mutate nothing) or fix-mode
 *    (deterministically reformat), returning a {@see LintResult}. Both take a {@see StackRunner} so the
 *    subprocess is injectable and the adapter's exit-code→result mapping unit-tests without shelling out.
 *
 * The **check-vs-fix split** (ticket 08 decision 4) is honoured by keeping these two methods distinct: the
 * gate calls {@see check()} only (never silently reformats inside a `move`/`canonicalize`), and only
 * `surgeon:lint --fix` calls {@see fix()}.
 */
interface LintStack
{
    /** Stable machine token for this stack — matches the token a {@see LintResult} and a lint suggestion carry. */
    public function name(): string;

    /**
     * The sniff: pure filesystem signal that this stack applies to the given root. The registry layers the
     * `extra.surgeon.lint` declared override on top — an adapter never reads the override itself.
     */
    public function detect(string $root): bool;

    /** Run in check-mode: report drift, mutate nothing (the gate's mode). */
    public function check(string $root, StackRunner $runner): LintResult;

    /** Run in fix-mode: deterministically reformat (the `surgeon:lint --fix` write-op's mode). */
    public function fix(string $root, StackRunner $runner): LintResult;
}
