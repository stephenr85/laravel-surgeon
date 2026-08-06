<?php

namespace Rushing\Surgeon\Lint;

/**
 * The outcome of running one {@see LintStack} against one root (ticket 12). A pure value — the adapter
 * builds it from its subprocess exit code + output; {@see LintOrchestrator} aggregates a batch of them and
 * {@see LintAudit} maps them to doctor Findings.
 *
 * Three outcomes, mirroring the deterministic gate's pass / fail / skip vocabulary (a lint stage is just
 * one more gate stage, so it speaks the same three words):
 *
 *  - **clean** — the stack ran and found no violations (check-mode pass, or a `--fix` that left nothing).
 *  - **violations** — the stack ran and reported drift ({@see $violations} > 0). In check-mode this fails
 *    the gate; in fix-mode it is the count the formatter *rewrote*.
 *  - **skipped** — the stack did not apply to this root, or its binary/config was absent — recorded
 *    honestly (like the gate's guarded stages) rather than faked into a pass.
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

    /**
     * @param  string  $stack  the stack name (e.g. `pint`, `eslint`)
     * @param  string  $root  the root the stack ran against
     * @param  string  $outcome  one of {@see CLEAN} / {@see VIOLATIONS} / {@see SKIPPED}
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
