<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Operation\Operation;

/**
 * The GENERIC Tier-1 literal-rewrite writer (dogfood campaign) — the operation half of the "a string
 * literal in the source drifted and must be corrected in place" shape. It is deliberately host-blind:
 * it knows nothing about routes, SDKs, or endpoints. A host-side audit ({@see SuggestsOperations}) is
 * what decides *which* literal is wrong and what it should become; this operation only performs the
 * precise, deterministic byte-splice that corrects it — reusing the estate's one byte-splicer
 * ({@see SpliceApplier}) under its drift-refusal + descending-offset invariants, never reimplementing it.
 *
 * The classic case it was built for: a Saloon request's `resolveEndpoint()` returns
 * `'/api/v1/compositions/{id}/render'` after the app moved the route to
 * `'/api/v1/splice/compositions/{id}/render'` (ADR-0124). The host audit locates the literal + its
 * corrected value; this operation splices `old → new` at the literal's exact byte span.
 *
 * `plan()`/`apply()` stay literal-rewrite-typed (not on the {@see Operation} interface) — same posture
 * as {@see RelocationOperation}: no generic plan/apply signature until a shared shape is forced.
 */
class LiteralRewriteOperation implements Operation
{
    public function kind(): string
    {
        return 'literal-rewrite';
    }

    public function describe(): string
    {
        return 'Rewrite drifted string literals in place — one byte-splice per {file, old, new} edit.';
    }

    public function isWriter(): bool
    {
        return true;
    }

    /**
     * Build the previewable splice plan from a flat list of literal edits. Each edit locates its
     * `oldText` in the named file by `strpos` and records the exact inclusive byte span; the applier
     * later re-verifies the live bytes still equal `oldText` before writing (drift refusal).
     *
     * @param  list<array{file: string, oldText: string, newText: string}>  $edits
     *
     * @throws \RuntimeException when an edit's `oldText` is absent from (or ambiguous in) its file
     */
    public function plan(array $edits): RewritePlan
    {
        $planned = [];
        $sources = [];

        foreach ($edits as $edit) {
            $file = $edit['file'];
            $old = $edit['oldText'];
            $new = $edit['newText'];

            if (! isset($sources[$file])) {
                $contents = @file_get_contents($file);
                if ($contents === false) {
                    throw new \RuntimeException("literal-rewrite: cannot read {$file}.");
                }
                $sources[$file] = $contents;
            }
            $source = $sources[$file];

            $pos = strpos($source, $old);
            if ($pos === false) {
                throw new \RuntimeException(sprintf(
                    'literal-rewrite: literal %s not found in %s — refusing to plan a splice with no span.',
                    var_export($old, true),
                    $file,
                ));
            }
            if (strpos($source, $old, $pos + 1) !== false) {
                throw new \RuntimeException(sprintf(
                    'literal-rewrite: literal %s appears more than once in %s — ambiguous span, refusing to guess.',
                    var_export($old, true),
                    $file,
                ));
            }

            $planned[] = new PlannedEdit(
                file: $file,
                relativePath: basename($file),
                line: substr_count($source, "\n", 0, $pos) + 1,
                startPos: $pos,
                endPos: $pos + strlen($old) - 1,
                oldText: $old,
                newText: $new,
                category: ReferenceCategory::NameReference,
            );
        }

        // A literal-rewrite is not a symbol relocation; carry a nominal directory target so the plan's
        // preview/summary machinery ({@see RewritePlan::toArray()}) has something to name.
        return new RewritePlan(Target::directory('literal-rewrite'), $planned, []);
    }

    /**
     * Apply the plan via the estate's byte-splicer and return the touched files (absolute paths,
     * de-duplicated). Drift refusal + descending-offset ordering are the applier's own invariants.
     *
     * @return list<string> the files written
     */
    public function apply(RewritePlan $plan, SpliceApplier $applier): array
    {
        $manifest = $applier->apply($plan);

        return $manifest->touchedFiles();
    }
}
