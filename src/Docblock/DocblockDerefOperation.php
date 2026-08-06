<?php

namespace Rushing\Surgeon\Docblock;

use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Operation\Operation;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Rewrite\PlannedEdit;
use Rushing\Surgeon\Rewrite\RewritePlan;
use Rushing\Surgeon\Rewrite\SpliceApplier;
use Rushing\Surgeon\Rewrite\TouchManifest;

/**
 * DOCBLOCK-DEREF — the byte-splice writer that de-forges an upward-import footgun (the second Tier-1
 * writer after the relocation operation). It rewrites a docblock reference whose *fully-qualified,
 * importable* form — a leading-slash `see`-tag naming `A\B\C` — Pint's
 * `fully_qualified_strict_types` (with `import_symbols`) will forge into a real `use A\B\C;` import, into
 * a form Pint provably leaves alone. When the referenced class is a HIGHER tier than the file's own
 * package, that forged import is an illegal upward package→app (or package→higher-package) edge.
 *
 * NOTE: this very docblock obeys its own rule — it never writes a leading-slash `see \Fqn`, precisely
 * because Pint would forge THIS file's own upward import. Illustrative names are written slash-free.
 *
 * **The chosen rewrite: the short-name in backticks (`` `C` ``).** Verified empirically against the
 * installed php-cs-fixer (3.87+) with `fully_qualified_strict_types: {import_symbols: true}` +
 * `global_namespace_import` active: a leading-slash see-tag naming `App\Providers\ParticleServiceProvider`
 * was forged into `use App\Providers\ParticleServiceProvider;` (and the tag shortened) — while a
 * backtick-wrapped `` `ParticleServiceProvider` `` on an adjacent line, and a slash-free `App\...`
 * mention, were both left untouched. Backticks win for three reasons: (1) they carry no fully-qualified,
 * importable token at all, so no fixer in any version can key an import off them; (2) they match this
 * estate's own established convention for cross-tier references in prose docblocks; (3) they degrade to
 * plain, still-legible prose rather than a half-qualified `A\B\C` that reads like a broken link. The
 * transform therefore strips the whole leading-slash see-reference down to the class short-name wrapped
 * in backticks — a non-importable prose mention that names the same class.
 *
 * Thin, like every operation (ticket 07 decision 1): `kind()`/`describe()`/`isWriter()` are the only
 * shared contract; {@see plan()}/{@see apply()} are this op's own typed methods. It reuses the existing
 * byte-splice machinery verbatim — {@see PlannedEdit} spans + {@see SpliceApplier}'s descending-offset,
 * drift-refusing splice — and invents no rewriter of its own. It is generic: it operates on any docblock
 * reference span the audit hands it; the tier-awareness (is the FQN higher than the file's package?)
 * lives in {@see DocblockTierAudit} via `PackageGraph`, so this op stays foundation-tier with no
 * `App\*` / `Splicewire\*` / `Schemastud\*` literals.
 */
class DocblockDerefOperation implements Operation
{
    public function kind(): string
    {
        return 'docblock-deref';
    }

    public function describe(): string
    {
        return 'Rewrite an upward, importable {@see \\Fqn} docblock reference to a non-importable backtick short-name so Pint cannot forge a use import.';
    }

    public function isWriter(): bool
    {
        return true;
    }

    /**
     * The non-importable form a leading-slash see-reference to `A\B\C` is rewritten to: the class
     * short-name in backticks. Pure and static so a caller (the audit) can precompute the replacement
     * text for a payload without instantiating the operation.
     */
    public static function derefText(string $fqn): string
    {
        $short = self::shortName($fqn);

        return '`'.$short.'`';
    }

    /** The trailing class short-name of a (possibly leading-slash) fully-qualified name. */
    public static function shortName(string $fqn): string
    {
        $parts = explode('\\', trim($fqn, '\\'));

        return $parts === [] ? $fqn : (string) end($parts);
    }

    /**
     * Build the previewable splice plan from a set of resolved docblock references. Each ref is a
     * `{file, relativePath, line, span:[start,end], old, new}` shape (as {@see DocblockTierAudit}
     * emits into its {@see OperationSuggestion} payloads). The `old` text is
     * the exact source at the span — the applier re-verifies it and refuses to splice on drift.
     *
     * A synthetic {@see Target} tags the plan (this op relocates nothing on-disk, so there is no real
     * move-pair); {@see ReferenceCategory::NameReference} is the closest existing category token for a
     * prose mention, and the applier does not branch on it.
     *
     * @param  list<array{file:string, relativePath?:string, line?:int, span:array{0:int,1:int}, old:string, new:string}>  $refs
     */
    public function plan(array $refs): RewritePlan
    {
        $edits = [];
        foreach ($refs as $ref) {
            $edits[] = new PlannedEdit(
                file: $ref['file'],
                relativePath: $ref['relativePath'] ?? $ref['file'],
                line: $ref['line'] ?? 0,
                startPos: $ref['span'][0],
                endPos: $ref['span'][1],
                oldText: $ref['old'],
                newText: $ref['new'],
                category: ReferenceCategory::NameReference,
            );
        }

        return new RewritePlan(
            target: Target::directory('docblock-deref'),
            edits: $edits,
            skips: [],
        );
    }

    /** Apply the plan: descending-offset, drift-refusing byte-splices. Returns the scoped-undo manifest. */
    public function apply(RewritePlan $plan, SpliceApplier $applier): TouchManifest
    {
        return $applier->apply($plan);
    }
}
