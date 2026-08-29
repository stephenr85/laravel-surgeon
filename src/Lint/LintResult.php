<?php

namespace Rushing\Surgeon\Lint;

/**
 * The outcome of running one {@see LintStack} against one root (ticket 12). A pure value — the adapter
 * builds it from its subprocess exit code + output; {@see LintOrchestrator} aggregates a batch of them and
 * {@see LintAudit} maps them to doctor Findings.
 *
 * Four outcomes. Three mirror the deterministic gate's pass / fail / skip vocabulary (a lint stage is
 * just one more gate stage, so it speaks the same words); the fourth was split out of `skipped` because
 * that word was carrying two events with opposite meanings:
 *
 *  - **clean** — the stack ran and found no violations (check-mode pass, or a `--fix` that left nothing).
 *  - **violations** — the stack ran and reported drift ({@see $violations} > 0). In check-mode this fails
 *    the gate; in fix-mode it is the count the formatter *rewrote*.
 *  - **skipped** — the stack did not apply to this root, or its binary/config was absent. Nothing was
 *    asked of it, so nothing is owed: this is genuinely a pass everywhere.
 *  - **unrunnable** — the stack DID apply, and could not execute: the process would not spawn, or it
 *    emitted a PHP fatal / OOM / config crash instead of a verdict. **Nothing was checked.**
 *
 * ## Why the fourth outcome exists (beam-facade ticket 163)
 *
 * `skipped` used to mean all four of *"does not apply here"*, *"binary absent"*, *"could not spawn"* and
 * *"crashed mid-run"*, and {@see LintRun::clean()} folded the lot into a clean verdict. Measured
 * 2026-08-27 at `~/Herd/splicewire-app`: pint OOMed, the run printed `All applicable stacks clean.` and
 * **exited 0**. The command is one of the four gates this fleet's `AGENTS.md` names as its review gate,
 * and 21 of 125 repos have any CI at all — so for most of the estate that verdict is the ONLY verdict.
 *
 * The scale is what settles it: enumerated over the 117 roots carrying a `composer.json`, **94 configure
 * exactly one stack**, so a single fold there is a run in which nothing executed at all. Two roots are in
 * that state today with no memory condition required —
 * `splicewire/laravel-circuit-spine` (a `pint.json`, no `vendor/`) and
 * `splicewire/laravel-knowledge-spine` (a `vendor/`, no `vendor/bin/pint`) — both pint-only, both
 * spawning nothing.
 *
 * **The severity is the RUNNER's, never the adapter's.** That ruling is not invented here: it is
 * `rushing/laravel-doctor`'s `AuditError`, applied one channel over — *never silently*, and *warn on an
 * advisory channel, fail on a gate, because a gate that degrades to "no findings" is how a gate stops
 * gating.* So an adapter mapping a crash to `unrunnable` is right; `surgeon:lint` check-mode (a declared
 * gate) turns it into a non-zero exit, and {@see LintAudit} (surgeon's advisory conformance channel)
 * turns the same event into a Warn.
 *
 * {@see $fixable} carries the *deterministic-fix* verdict the stack declared for this run (from
 * {@see LintStack::isDeterministicFix()}): a Pint drift is a deterministic reformat (auto-fixable), an
 * eslint error may not be (some rules have no fixer) — this is the seam that keeps the deterministic/
 * agentic split honest ({@see LintAudit} only nominates `--fix` where the stack says it is deterministic).
 */
class LintResult
{
    public const CLEAN = 'clean';

    public const VIOLATIONS = 'violations';

    public const SKIPPED = 'skipped';

    /** The stack applied and could NOT execute — see the class docblock. Nothing was checked. */
    public const UNRUNNABLE = 'unrunnable';

    /**
     * @param  string  $stack  the stack name (e.g. `pint`, `eslint`)
     * @param  string  $root  the root the stack ran against
     * @param  string  $outcome  one of {@see CLEAN} / {@see VIOLATIONS} / {@see SKIPPED} / {@see UNRUNNABLE}
     * @param  int  $violations  count of files/rules with drift (0 when clean or skipped)
     * @param  bool  $fixable  whether this stack's drift is deterministically fixable (nominates `--fix`)
     * @param  bool  $fixed  whether this result is from a fix-mode run that rewrote the drift
     * @param  string  $detail  a short human line (the tail of the tool's own output, or the skip reason)
     */
    public function __construct(
        public string $stack,
        public string $root,
        public string $outcome,
        public int $violations = 0,
        public bool $fixable = false,
        public bool $fixed = false,
        public string $detail = '',
    ) {}

    public static function clean(string $stack, string $root, string $detail = ''): self
    {
        return new self($stack, $root, self::CLEAN, 0, false, false, $detail);
    }

    public static function violations(string $stack, string $root, int $count, bool $fixable, string $detail = ''): self
    {
        return new self($stack, $root, self::VIOLATIONS, $count, $fixable, false, $detail);
    }

    public static function fixed(string $stack, string $root, int $count, string $detail = ''): self
    {
        // Outcome is CLEAN (a fix leaves no drift), but the count is retained so a fix run can report
        // how many files it reformatted; hasViolations() keys on outcome, not the count, so this is safe.
        return new self($stack, $root, self::CLEAN, $count, false, true, $detail !== '' ? $detail : $count.' file(s) reformatted');
    }

    public static function skipped(string $stack, string $root, string $reason): self
    {
        return new self($stack, $root, self::SKIPPED, 0, false, false, $reason);
    }

    /**
     * The stack applied and could not execute. Distinct from {@see skipped()} on purpose: a skip means
     * nothing was asked, an unrunnable means something was asked and no answer came back.
     */
    public static function unrunnable(string $stack, string $root, string $reason): self
    {
        return new self($stack, $root, self::UNRUNNABLE, 0, false, false, $reason);
    }

    public function isClean(): bool
    {
        return $this->outcome === self::CLEAN;
    }

    public function hasViolations(): bool
    {
        return $this->outcome === self::VIOLATIONS;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === self::SKIPPED;
    }

    /** The stack applied and could not execute — nothing about this root was actually checked. */
    public function couldNotRun(): bool
    {
        return $this->outcome === self::UNRUNNABLE;
    }

    /**
     * Did this stack actually produce a verdict? False for both a skip and an unrunnable, which is what
     * every "N of M stacks ran" count is asking.
     */
    public function ran(): bool
    {
        return $this->isClean() || $this->hasViolations();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stack' => $this->stack,
            'root' => $this->root,
            'outcome' => $this->outcome,
            'violations' => $this->violations,
            'fixable' => $this->fixable,
            'fixed' => $this->fixed,
            'detail' => $this->detail,
        ];
    }
}
