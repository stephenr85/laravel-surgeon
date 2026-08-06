<?php

namespace Rushing\Surgeon\HouseStyle;

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\Token;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Operation\Operation;
use Rushing\Surgeon\Rewrite\PlannedEdit;
use Rushing\Surgeon\Rewrite\SpliceApplier;

/**
 * Strips the three modern-PHP constructs a house style may forbid — `declare(strict_types=1);`, the
 * `final` keyword (class- and method-level), and the `readonly` keyword (properties and promoted
 * params) — as a first-class, deterministic {@see Operation} (`kind() = 'house-style-strip'`).
 *
 * It is a byte-splice writer in the surgeon Tier-1 grain: {@see plan()} resolves the exact spans to
 * delete from ONE file into a {@see HouseStyleStripPlan}; {@see apply()} feeds those spans through the
 * shared {@see SpliceApplier::spliceSource()} (descending-offset, drift-refusal) so the correctness
 * property is REUSED, never reimplemented. Every other byte in the file is preserved untouched.
 *
 * **Token-aware, not regex.** Detection runs off php-parser's tokenizer, so it is immune to the traps a
 * regex hits: `readonly` used as a *method name* (`public function readonly()` — the keyword token there
 * is directly followed by `(`, which the modifier guard rejects), `'final'`/`'readonly'` as string
 * literals or array keys (never keyword tokens), and `declare(ticks=1)` (matched only when the directive
 * is literally `strict_types`). A modifier keyword and its ONE trailing whitespace run are removed
 * together so no double space is left; `final readonly class Foo` collapses cleanly to `class Foo` because
 * each keyword+space is an independent deletion. The `declare(strict_types=1);` statement is removed whole,
 * plus one trailing newline, matching the convention's strip rule 1.
 *
 * Fully generic (operates on any PHP source, names no host namespace), so it lives in foundation-tier
 * surgeon. The *policy* of whether a given estate forbids these constructs is the caller's — this op only
 * performs the mechanical removal.
 */
class HouseStyleStripOperation implements Operation
{
    public function kind(): string
    {
        return 'house-style-strip';
    }

    public function describe(): string
    {
        return 'Strip forbidden modern-PHP constructs (declare(strict_types), final, readonly) from a PHP file.';
    }

    public function isWriter(): bool
    {
        return true;
    }

    /**
     * Resolve every span to delete from one file into an applyable plan. Pure w.r.t. disk beyond the one
     * read; a parse failure yields an empty plan (a file that doesn't parse is left untouched, never
     * corrupted — fail safe, like the applier's drift refusal).
     */
    public function plan(string $file, ?string $relativePath = null): HouseStyleStripPlan
    {
        $relativePath ??= $file;
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException("cannot read {$file} to plan a house-style strip.");
        }

        return $this->planSource($source, $file, $relativePath);
    }

    /** The pure core — plan directly over a source string (disk-free, so unit-testable). */
    public function planSource(string $source, string $file = 'in-memory.php', string $relativePath = 'in-memory.php'): HouseStyleStripPlan
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $parser->parse($source);
        } catch (Error) {
            return new HouseStyleStripPlan($file, $relativePath, [], []);
        }

        /** @var list<Token> $tokens */
        $tokens = $parser->getTokens();
        $count = count($tokens);

        $edits = [];
        $reasons = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === T_DECLARE && $this->isStrictTypesDeclare($tokens, $i, $count)) {
                $edits[] = $this->declareEdit($source, $tokens, $i, $count, $file, $relativePath);
                $reasons[] = 'declare(strict_types)';

                continue;
            }

            if (($token->id === T_FINAL || $token->id === T_READONLY) && $this->isModifierKeyword($tokens, $i, $count)) {
                $edits[] = $this->modifierEdit($source, $tokens, $i, $count, $file, $relativePath);
                $reasons[] = $token->id === T_FINAL ? 'final' : 'readonly';
            }
        }

        return new HouseStyleStripPlan($file, $relativePath, $edits, $reasons);
    }

    /** Apply a resolved plan to a source string via the shared splicer (drift-checked, descending-offset). */
    public function applyToSource(string $source, HouseStyleStripPlan $plan): string
    {
        return SpliceApplier::spliceSource($source, $plan->edits);
    }

    /** Apply a resolved plan to disk in place. Reads the file fresh so the splicer's drift check is live. */
    public function apply(HouseStyleStripPlan $plan, ?SpliceApplier $applier = null): void
    {
        if ($plan->isEmpty()) {
            return;
        }

        $source = @file_get_contents($plan->file);
        if ($source === false) {
            throw new \RuntimeException("cannot read {$plan->file} to apply a house-style strip.");
        }

        $updated = SpliceApplier::spliceSource($source, $plan->edits);

        if (@file_put_contents($plan->file, $updated) === false) {
            throw new \RuntimeException("cannot write {$plan->file}.");
        }
    }

    // --- detection --------------------------------------------------------------------------------

    /**
     * True only for `declare(strict_types = ...)` — the next significant tokens after `declare` are
     * `(` then a `strict_types` identifier. `declare(ticks=1)` / `declare(encoding=...)` are left alone.
     *
     * @param  list<Token>  $tokens
     */
    private function isStrictTypesDeclare(array $tokens, int $i, int $count): bool
    {
        $j = $this->nextSignificant($tokens, $i + 1, $count);
        if ($j === null || $tokens[$j]->text !== '(') {
            return false;
        }
        $k = $this->nextSignificant($tokens, $j + 1, $count);

        return $k !== null && $tokens[$k]->text === 'strict_types';
    }

    /**
     * True when a `final`/`readonly` keyword token is a real modifier, not an identifier. The one context
     * where these keyword tokens are NOT modifiers is a method *named* `readonly` (`function readonly()`),
     * whose token is immediately followed by `(`. Any other following token (`class`, `function`, a
     * visibility, a type, another modifier) is a genuine modifier.
     *
     * @param  list<Token>  $tokens
     */
    private function isModifierKeyword(array $tokens, int $i, int $count): bool
    {
        $j = $this->nextSignificant($tokens, $i + 1, $count);
        if ($j === null) {
            return false;
        }

        return $tokens[$j]->text !== '(';
    }

    // --- span resolution --------------------------------------------------------------------------

    /**
     * Delete the whole `declare(strict_types=1);` statement plus a single trailing newline (strip rule 1).
     * The statement span runs from the `declare` token to its terminating `;`; the trailing newline is
     * folded in when the immediately-following whitespace token begins with one.
     *
     * @param  list<Token>  $tokens
     */
    private function declareEdit(string $source, array $tokens, int $i, int $count, string $file, string $relativePath): PlannedEdit
    {
        $start = $tokens[$i]->pos;

        // Walk to the terminating semicolon of the declare statement.
        $end = $tokens[$i]->pos + strlen($tokens[$i]->text) - 1;
        for ($j = $i; $j < $count; $j++) {
            if ($tokens[$j]->text === ';') {
                $end = $tokens[$j]->pos;
                // Fold in the trailing newline(s) so the `declare` line AND the single blank line after it
                // both go (strip rule 1). Consume the following whitespace run up to two newlines: one ends
                // the declare line, the second is the blank separator before the next statement.
                if ($j + 1 < $count && $tokens[$j + 1]->id === T_WHITESPACE) {
                    $end += $this->trailingNewlineSpan($tokens[$j + 1]->text);
                }
                break;
            }
        }

        $length = $end - $start + 1;
        $oldText = substr($source, $start, $length);

        return $this->deletion($file, $relativePath, $tokens[$i]->line, $start, $end, $oldText);
    }

    /**
     * Delete a `final `/`readonly ` modifier: the keyword token and its ONE trailing whitespace run, so
     * `final readonly class` collapses to `class` with no residual double space.
     *
     * @param  list<Token>  $tokens
     */
    private function modifierEdit(string $source, array $tokens, int $i, int $count, string $file, string $relativePath): PlannedEdit
    {
        $start = $tokens[$i]->pos;
        $end = $tokens[$i]->pos + strlen($tokens[$i]->text) - 1;

        // Consume the single trailing whitespace token so no gap is left behind.
        if ($i + 1 < $count && $tokens[$i + 1]->id === T_WHITESPACE) {
            $end += strlen($tokens[$i + 1]->text);
        }

        $length = $end - $start + 1;
        $oldText = substr($source, $start, $length);

        return $this->deletion($file, $relativePath, $tokens[$i]->line, $start, $end, $oldText);
    }

    /**
     * How many bytes of a leading whitespace run to fold into the declare deletion: everything up to and
     * including the SECOND newline (line terminator + one blank line), matching strip rule 1. Handles both
     * `\n` and `\r\n` line endings; stops at any non-newline, non-CR whitespace so indentation of the next
     * statement is preserved.
     */
    private function trailingNewlineSpan(string $ws): int
    {
        $span = 0;
        $newlines = 0;
        $len = strlen($ws);
        for ($p = 0; $p < $len; $p++) {
            $ch = $ws[$p];
            if ($ch === "\r") {
                $span++;

                continue;
            }
            if ($ch === "\n") {
                $span++;
                $newlines++;
                if ($newlines === 2) {
                    break;
                }

                continue;
            }
            break;
        }

        return $span;
    }

    private function deletion(string $file, string $relativePath, int $line, int $start, int $end, string $oldText): PlannedEdit
    {
        // ReferenceCategory is a relocation-shaped label the splicer never reads; NamespaceDeclaration is
        // the closest "a statement-shaped edit to this file's own source" carrier for the reused span type.
        return new PlannedEdit($file, $relativePath, $line, $start, $end, $oldText, '', ReferenceCategory::NamespaceDeclaration);
    }

    /**
     * The index of the next non-whitespace token at or after $from, or null.
     *
     * @param  list<Token>  $tokens
     */
    private function nextSignificant(array $tokens, int $from, int $count): ?int
    {
        for ($j = $from; $j < $count; $j++) {
            if ($tokens[$j]->id !== T_WHITESPACE) {
                return $j;
            }
        }

        return null;
    }
}
