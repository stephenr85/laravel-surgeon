<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Audit\Reference;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\Tier;

/**
 * THE TIER-1 REWRITE PLANNER — turns the audit's span-annotated relocation finding-set into a
 * concrete, previewable {@see RewritePlan} of byte-splices, WITHOUT touching disk.
 *
 * The load-bearing insight the naive "splice the new FQN into every span" statement misses: the text
 * that belongs in a span is NOT the new FQN — it is whatever *written form* keeps that reference
 * resolving to the new home. A `use Old\Widget;` span holds the whole FQN, so it takes the whole new
 * FQN; but an imported short `Widget::class` span holds only `Widget`, and once the `use` line is
 * repointed it needs NO change at all (its basename is unchanged). Splicing the full new FQN there
 * would bloat and mis-resolve the code. So per reference the planner decides one of three actions:
 *
 *  - **edit**  — a real byte-splice (the written form must change to track the move);
 *  - **noop**  — the reference already resolves correctly once the imports move (short name, unchanged
 *                basename; or an alias whose `use ... as` line carries the move);
 *  - **skip**  — a Tier-1 shape a byte-splice can't safely handle alone (a group-use member crossing
 *                namespaces, a partially-qualified inline name leaning on an unmanaged import). Handed
 *                to the guided path rather than guessed at — the deterministic/agentic seam, upheld.
 *
 * Only {@see Tier::One} references are considered; Tier 2/3 are the audit's guided/advisory business
 * and this pass never touches them.
 */
class RewritePlanner
{
    public function __construct(
        private ?Psr4Resolver $psr4 = null,
    ) {}

    /**
     * Plan the Tier-1 rewrite for a relocation audit. Throws if the report is not a relocation
     * (an insight-only audit has nothing to apply).
     */
    public function plan(AuditReport $report): RewritePlan
    {
        if (! $report->target->relocates()) {
            throw new \InvalidArgumentException('surgeon:move needs a relocation audit (a --to destination); got an insight-only finding-set.');
        }

        // A basename rename (Old\Widget → Old\Gadget) needs the class-declaration TOKEN rewritten as
        // well — and that token is not a touch-point the ticket-05 audit surfaces (it records the
        // moved file's `namespace` line, not its `class Name`). Applying only the FQN-reference edits
        // would leave `class Widget` behind references to `Gadget` — broken code. Ticket 02 already
        // rules the rename's non-FQN cascade Tier-3 advisory, so this Tier-1 pass declines the whole
        // move rather than half-apply it. Pure relocation (basename preserved) is unaffected.
        //
        // The check is per moved MEMBER — a moved symbol whose OWN declaration changes basename between
        // its matched and new FQN. Reading it off the declaration references (not the target's `value`)
        // is what makes it correct for a namespace / atomic-cluster move too: those targets carry a
        // *prefix* in `value` (`App\Cluster` → `Dest`), never a basename, so comparing the target
        // strings would false-flag a pure relocation as a rename. Each member preserves its basename by
        // construction, so a namespace / cluster move is (correctly) never flagged here.
        $unsupported = null;
        foreach ($report->references() as $ref) {
            if ($ref->category !== ReferenceCategory::NamespaceDeclaration || $ref->newFqn === null) {
                continue;
            }
            $oldBase = $this->basenameOf(Target::normalize($ref->matchedFqn));
            $newBase = $this->basenameOf(Target::normalize($ref->newFqn));
            if ($oldBase !== $newBase) {
                $unsupported = sprintf(
                    'basename rename (%s → %s): the class-declaration token is not in the audit finding-set — '
                    .'deferred to the guided rename path (ticket 02: the rename cascade is Tier-3 advisory).',
                    $oldBase,
                    $newBase,
                );
                break;
            }
        }

        // File-context for the no-op safety check below. A short-name reference is a safe no-op ONLY
        // when the target is explicitly imported in its file (that `use` line is itself an edit, so it
        // repoints) OR the reference lives in the moved file itself (it travels with the namespace).
        // A short name resolved by *same-namespace proximity* (no import) would silently break on a
        // relocation — the old namespace empties out from under it — so it is NOT a no-op; it needs an
        // import added, which is import management beyond a single-span byte-splice → guided path.
        [$importedInFile, $declarationFile] = $this->fileContext($report);

        $edits = [];
        $skips = [];

        foreach ($report->references() as $ref) {
            if ($ref->tier !== Tier::One || $ref->newFqn === null) {
                continue; // Tier 2/3 are the guided/advisory path; this pass never touches them.
            }

            $decision = $this->decide($ref);

            // Re-examine a no-op that leans on import/namespace context: safe only if imported in-file
            // or self-contained in the moved file; otherwise it is a context-resolved reference the
            // splice alone can't keep valid.
            if ($decision['action'] === 'noop' && $this->isContextDependent($ref)
                && $ref->relativePath !== $declarationFile
                && ! isset($importedInFile[$ref->relativePath])) {
                $decision = ['action' => 'skip', 'reason' => 'unqualified reference resolved by namespace context (no import of the target in this file) — relocating it needs an import added — guided path'];
            }

            if ($decision['action'] === 'skip') {
                $skips[] = SkippedReference::of($ref, (string) $decision['reason']);
            } elseif ($decision['action'] === 'edit') {
                $edits[] = new PlannedEdit(
                    file: $ref->file,
                    relativePath: $ref->relativePath,
                    line: $ref->line,
                    startPos: $ref->startPos,
                    endPos: $ref->endPos,
                    oldText: $ref->snippet,
                    newText: (string) $decision['text'],
                    category: $ref->category,
                );
            }
            // 'noop' contributes nothing — the import repoint already covers it.
        }

        return new RewritePlan($report->target, $edits, $skips, $this->physicalMoves($report), $unsupported);
    }

    /**
     * Decide what to do with one Tier-1 reference. Pure over the reference's own data (its recorded
     * span text {@see Reference::$snippet}, matched FQN, and destination) — no disk access — so it is
     * directly unit-testable across the written-form matrix.
     *
     * @return array{action: 'edit'|'noop'|'skip', text?: string, reason?: string}
     */
    public function decide(Reference $ref): array
    {
        $old = Target::normalize($ref->matchedFqn);
        $new = Target::normalize((string) $ref->newFqn);
        $written = $ref->snippet;
        $lead = str_starts_with($written, '\\') ? '\\' : '';
        $bare = ltrim($written, '\\');

        // The moved file's own `namespace X;` line: the span holds the namespace (a *prefix* of the
        // old FQN), so it takes the new FQN's namespace — not the FQN, and never a leading backslash.
        if ($ref->category === ReferenceCategory::NamespaceDeclaration) {
            $expected = $this->namespaceOf($old);
            if ($bare !== $expected) {
                return ['action' => 'skip', 'reason' => "namespace-declaration span '{$bare}' does not match expected '{$expected}'"];
            }
            $newNamespace = $this->namespaceOf($new);
            if ($newNamespace === '') {
                return ['action' => 'skip', 'reason' => 'relocation destination has no namespace to write into the declaration'];
            }

            return $this->editOrNoop($written, $newNamespace);
        }

        // A fully-written reference — a plain `use A\B\C;` line or a fully-qualified inline
        // `\A\B\C::class`. The whole span is the FQN, so it takes the whole new FQN (leading `\`
        // preserved when the source had one).
        if ($bare === $old) {
            return $this->editOrNoop($written, $lead.$new);
        }

        // A trailing segment-run of the old FQN — a short name resolved through an import.
        if (str_ends_with($old, '\\'.$bare)) {
            // Multi-segment partial (`use A\B; ... B\Widget`) leans on an import this Tier-1 pass
            // doesn't manage — remapping the tail without touching that import would break it.
            if (str_contains($bare, '\\')) {
                return ['action' => 'skip', 'reason' => "partially-qualified reference '{$bare}' relies on an import this Tier-1 pass does not manage — guided path"];
            }

            // A single short segment. For a `use` member this is a group-use entry
            // (`use A\B\{Widget, ...}`); relocating it across namespaces needs the member split out
            // of the group — not a byte-splice. Safe only when the namespace is unchanged (a rename).
            if ($ref->category === ReferenceCategory::UseImport
                && $this->namespaceOf($old) !== $this->namespaceOf($new)) {
                return ['action' => 'skip', 'reason' => "group-use member '{$bare}' relocated across namespaces — needs the member split out — guided path"];
            }

            return $this->editOrNoop($written, $lead.$this->basenameOf($new));
        }

        // Written form maps to neither the whole FQN nor a trailing run of it — e.g. an aliased
        // import's inline alias (`use A\B\Widget as W; ... W::class`). The `use ... as` line carries
        // the move; the alias itself is stable. Leave it untouched.
        return ['action' => 'noop'];
    }

    /**
     * A reference whose validity leans on import/namespace context rather than being a full written
     * FQN — i.e. a short or aliased name (its span is not the whole old FQN). These are the no-ops the
     * plan re-examines against file context; a full-FQN reference and the namespace declaration are
     * self-sufficient and never need this check.
     */
    private function isContextDependent(Reference $ref): bool
    {
        if ($ref->category === ReferenceCategory::NamespaceDeclaration) {
            return false;
        }

        return ltrim($ref->snippet, '\\') !== Target::normalize($ref->matchedFqn);
    }

    /**
     * @return array{0: array<string, true>, 1: string|null} [files that spell the target in full, the moved file's own path]
     */
    private function fileContext(AuditReport $report): array
    {
        $importedInFile = [];
        $declarationFile = null;

        foreach ($report->references() as $ref) {
            if ($ref->category === ReferenceCategory::NamespaceDeclaration) {
                $declarationFile = $ref->relativePath;

                continue;
            }

            // A file that spells the target's full FQN anywhere holds an explicit `use` line for it
            // (its span is the whole FQN) — the one place a `use` is recorded, even when file-role
            // reclassification (a test/migration/route dir) relabels its category. That import is
            // itself an edit, so short names in the file ride the repoint. (Being in the target's own
            // namespace *and* also spelling it fully-qualified inline is self-contradictory in
            // practice, so this doesn't misclassify a genuine same-namespace short reference.)
            if (ltrim($ref->snippet, '\\') === Target::normalize($ref->matchedFqn)) {
                $importedInFile[$ref->relativePath] = true;
            }
        }

        return [$importedInFile, $declarationFile];
    }

    /**
     * @return array{action: 'edit'|'noop', text?: string}
     */
    private function editOrNoop(string $written, string $replacement): array
    {
        return $replacement === $written
            ? ['action' => 'noop']
            : ['action' => 'edit', 'text' => $replacement];
    }

    /**
     * The tool-owned physical moves — ONE per moving member (each file whose own `namespace X;` line was
     * recorded as a NamespaceDeclaration reference *is* a moved file). A lone relocation yields exactly
     * one; an atomic cluster yields one per sibling, so the whole set relocates together.
     *
     * Each destination root is found by {@see Psr4Resolver::resolve()} across ALL named roots, not by
     * assuming the new FQN lives under the source root (ticket 14): the whole point of a cross-repo
     * promotion is that `Vendor\Pkg\Foo` resolves into the *package* root, a different tree than the
     * `App\` source root. Once both the source file's repo and the destination path's repo are known,
     * {@see GitRoot} decides the relocation mode (same-repo `git mv` vs. two-repo copy+delete) — the
     * single-repo path stays byte-for-byte identical because same-repo resolution yields the same
     * `to`/`toRelative` it always did.
     *
     * @return list<PhysicalMove>
     */
    private function physicalMoves(AuditReport $report): array
    {
        if ($this->psr4 === null) {
            return [];
        }

        // ONE physical move per moving member — every file whose OWN declaration matched the target
        // (its `namespace X;` line was recorded as a NamespaceDeclaration reference). A single-symbol
        // relocation catches exactly one; an atomic cluster catches one per sibling, so the whole
        // cohesive set relocates together instead of half-moving. Deduplicated by source file.
        $moves = [];
        $seen = [];
        foreach ($report->references() as $ref) {
            if ($ref->category !== ReferenceCategory::NamespaceDeclaration || $ref->newFqn === null) {
                continue;
            }
            if (isset($seen[$ref->file])) {
                continue;
            }
            $seen[$ref->file] = true;
            $move = $this->physicalMoveFor($ref);
            if ($move !== null) {
                $moves[] = $move;
            }
        }

        return $moves;
    }

    /** Resolve the tool-owned physical move for ONE moved-symbol declaration reference. */
    private function physicalMoveFor(Reference $declaration): ?PhysicalMove
    {
        $old = Target::normalize($declaration->matchedFqn);
        $new = Target::normalize((string) $declaration->newFqn);

        // Locate the destination across every root — the new FQN may belong to a package root that is
        // NOT the source root (the cross-repo case). Fall back to same-root resolution so a new FQN
        // whose namespace no root autoloads still resolves under the source root exactly as before.
        $resolved = $this->psr4->resolve($new);
        if ($resolved === null) {
            $sameRootPath = $this->psr4->pathFor($new, $declaration->root);
            if ($sameRootPath === null) {
                return null; // non-PSR-4 ancillary destination — audit-reported, not tool-moved.
            }
            $resolved = ['root' => $declaration->root, 'path' => $sameRootPath];
        }

        $to = $resolved['path'];
        $destRoot = $resolved['root'];

        // Decide the relocation mode by the two files' real git repos (ticket 14). The source file
        // exists on disk; the destination file does not yet, so we ask which repo its *directory* (or
        // nearest existing ancestor) lives in — the PSR-4 destination dir under the package root.
        $sourceRepo = GitRoot::of($declaration->file);
        $destinationRepo = GitRoot::of($to);
        $crossRepo = $sourceRepo !== null && $destinationRepo !== null && $sourceRepo !== $destinationRepo;

        return new PhysicalMove(
            from: $declaration->file,
            to: $to,
            fromRelative: $declaration->relativePath,
            toRelative: str_starts_with($to, $destRoot.'/') ? substr($to, strlen($destRoot) + 1) : $to,
            oldFqn: $old,
            newFqn: $new,
            crossRepo: $crossRepo,
            sourceRepo: $sourceRepo,
            destinationRepo: $destinationRepo,
        );
    }

    private function namespaceOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? '' : substr($fqn, 0, $pos);
    }

    private function basenameOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
