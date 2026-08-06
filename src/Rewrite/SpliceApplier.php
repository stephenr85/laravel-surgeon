<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Console\MoveCommand;

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
            $this->relocate($plan->move);
            $manifest->recordMove($plan->move);
        }

        return $manifest;
    }

    /**
     * Manifest-scoped rollback: reverse the physical move, then restore every tool-written file to its
     * pre-tool bytes. Restores ONLY what the manifest records — pre-existing dirt elsewhere is never
     * touched (the `--allow-dirty` guarantee).
     *
     * The moved file's SOURCE bytes are always recoverable because its in-place namespace splice was
     * recorded in {@see TouchManifest::$writes} (with `original` = the pre-tool source), so the second
     * loop below rewrites the source file back to its exact pre-move content after the reverse-relocate
     * puts it back at its old path. This makes the cross-repo rollback safe with no lost bytes even if
     * the forward pass half-completed (see {@see reverseRelocate()}).
     */
    public function rollback(TouchManifest $manifest): void
    {
        if ($manifest->move !== null) {
            $this->reverseRelocate($manifest->move);
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

    /**
     * Perform the tool-owned physical relocation (ticket 09 same-repo, ticket 14 cross-repo). The mode
     * is on the {@see PhysicalMove} itself, derived by the planner — the applier just executes it.
     *
     *  - **Same repo** — an in-repo `rename()` (a fast, atomic move within one filesystem/repo). This is
     *    the historical path, unchanged: a same-repo move is byte-for-byte what ticket 09 did.
     *  - **Cross repo** — a two-repo copy+delete: create the (already namespace-spliced) file at its
     *    destination path in the OTHER repo, then delete it from the source repo. The ORDER is
     *    load-bearing for reversibility: **write destination first, delete source second**. If the
     *    source delete fails, the file exists in both places — rollback still restores each side
     *    cleanly (delete the destination, rewrite the source). We never leave a window where the file
     *    exists in NEITHER repo. Git staging of the two paths is a separate best-effort review
     *    affordance ({@see MoveCommand::stageWithGit()}), scoped to the exact
     *    moved paths — never a blind `git add -A` that would sweep unrelated dirt.
     */
    private function relocate(PhysicalMove $move): void
    {
        if ($move->from === $move->to) {
            return;
        }

        if (! $move->crossRepo) {
            $this->ensureDir(dirname($move->to));
            if (! @rename($move->from, $move->to)) {
                throw new \RuntimeException("cannot move {$move->from} → {$move->to}.");
            }

            return;
        }

        // Cross-repo: copy the current (spliced) source bytes into the destination repo, THEN remove
        // the source. Reading + writing bytes (not rename) is required — rename cannot cross a git
        // boundary and, in a co-dev overlay, the two repos may even be different filesystems.
        $bytes = @file_get_contents($move->from);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read {$move->from} to relocate it cross-repo.");
        }
        $this->ensureDir(dirname($move->to));
        $this->write($move->to, $bytes);

        if (! @unlink($move->from)) {
            throw new \RuntimeException("wrote {$move->to} but cannot remove source {$move->from} — rollback will reconcile.");
        }
    }

    /**
     * Reverse a physical relocation for rollback. Same-repo reverses the `rename()`. Cross-repo puts the
     * file back at the source path (the second rollback loop then rewrites its pre-tool bytes) and
     * removes the destination copy — restoring BOTH repos to exactly their pre-move state. Each fs op is
     * defensive (best-effort on the destination delete): a partially-applied forward pass — file in both
     * repos, or only in the source — still lands back at a clean pre-move tree.
     */
    private function reverseRelocate(array $move): void
    {
        $from = $move['from'];
        $to = $move['to'];
        if ($from === $to) {
            return;
        }

        if (empty($move['crossRepo'])) {
            $this->ensureDir(dirname($from));
            if (is_file($to) && ! @rename($to, $from)) {
                throw new \RuntimeException("cannot roll back move {$to} → {$from}.");
            }

            return;
        }

        // Cross-repo reverse: restore the source path (bytes are corrected by the writes loop that
        // follows), then delete the destination copy so the destination repo returns to pristine.
        $this->ensureDir(dirname($from));
        if (! is_file($from)) {
            $bytes = is_file($to) ? (string) @file_get_contents($to) : '';
            $this->write($from, $bytes);
        }
        if (is_file($to)) {
            @unlink($to);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("cannot create destination directory {$dir}.");
        }
    }
}
