<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * THE TIER-1 WRITER — applies a resolved {@see RewritePlan} to disk by byte-splice, then performs the
 * tool-owned physical move. It is the one component in the tool that mutates source, and it does so
 * under two invariants:
 *
 *  - **Descending-offset multi-splice.** Edits within a file are applied highest-offset-first, so
 *    every not-yet-applied edit's recorded span stays byte-valid (an earlier splice never shifts a
 *    later one's offsets). This is the load-bearing correctness property of a byte-splice rewriter.
 *  - **Drift refusal.** Before splicing, the live bytes at a span must still equal the audit's
 *    recorded text. If a file changed since the audit, the applier throws BEFORE writing anything —
 *    a stale plan fails safe rather than corrupting source. (Ticket 03's clean-tree gate makes drift
 *    rare; this is the belt to that suspenders.)
 *
 * Rollback is manifest-scoped: {@see rollback()} restores only tool-touched files to their pre-tool
 * bytes and reverses the physical move, so a failed deterministic gate leaves the tree as found.
 */
class SpliceApplier
{
    /**
     * Apply every edit for ONE file to its source string. Pure — no disk — so the splice invariant is
     * directly unit-testable. Verifies each span against its recorded `oldText` and applies in
     * descending offset order.
     *
     * @param  list<PlannedEdit>  $edits
     *
     * @throws \RuntimeException when a span's live bytes no longer match the recorded text (drift)
     */
    public static function spliceSource(string $source, array $edits): string
    {
        // Highest offset first: a splice never invalidates an earlier (lower-offset) edit's span.
        usort($edits, fn (PlannedEdit $a, PlannedEdit $b) => $b->startPos <=> $a->startPos);

        foreach ($edits as $edit) {
            $length = $edit->endPos - $edit->startPos + 1;
            $live = substr($source, $edit->startPos, $length);
            if ($live !== $edit->oldText) {
                throw new \RuntimeException(sprintf(
                    'drift at %s:%d — span holds %s, plan expected %s; refusing to splice a stale plan.',
                    $edit->relativePath,
                    $edit->line,
                    var_export($live, true),
                    var_export($edit->oldText, true),
                ));
            }
            $source = substr_replace($source, $edit->newText, $edit->startPos, $length);
        }

        return $source;
    }

    /**
     * Apply the whole plan to disk and return the touch-manifest. All splices are computed and
     * verified in memory first; disk is written only once every file's new content is known, so a
     * drift failure on any file leaves the tree completely untouched.
     */
    public function apply(RewritePlan $plan): TouchManifest
    {
        $manifest = new TouchManifest($plan);

        // Phase 1 — read + splice + verify every file in memory (may throw on drift; writes nothing).
        foreach ($plan->editsByFile() as $file => $edits) {
            $original = @file_get_contents($file);
            if ($original === false) {
                throw new \RuntimeException("cannot read {$file} to apply its splices.");
            }
            $updated = self::spliceSource($original, $edits);
            $manifest->recordWrite($file, $edits[0]->relativePath, $original, $updated);
        }

        // Phase 2 — commit the splices to disk.
        foreach ($manifest->writes as $write) {
            $this->write($write['file'], $write['updated']);
        }

        // Phase 3 — the tool-owned physical move (the moved file was just spliced in place, then relocates).
        if ($plan->move !== null) {
            $this->relocate($plan->move->from, $plan->move->to);
            $manifest->recordMove($plan->move);
        }

        return $manifest;
    }

    /**
     * Manifest-scoped rollback: reverse the physical move, then restore every tool-written file to its
     * pre-tool bytes. Restores ONLY what the manifest records — pre-existing dirt elsewhere is never
     * touched (the `--allow-dirty` guarantee).
     */
    public function rollback(TouchManifest $manifest): void
    {
        if ($manifest->move !== null) {
            $this->relocate($manifest->move['to'], $manifest->move['from']);
        }

        foreach ($manifest->writes as $write) {
            $this->write($write['file'], $write['original']);
        }
    }

    private function write(string $file, string $contents): void
    {
        if (@file_put_contents($file, $contents) === false) {
            throw new \RuntimeException("cannot write {$file}.");
        }
    }

    private function relocate(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        $dir = dirname($to);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("cannot create destination directory {$dir}.");
        }
        if (! @rename($from, $to)) {
            throw new \RuntimeException("cannot move {$from} → {$to}.");
        }
    }
}
