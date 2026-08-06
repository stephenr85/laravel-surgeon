<?php

namespace Rushing\Surgeon\Docblock;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * THE FIND-HALF for the docblock-deref footgun (paired with {@see DocblockDerefOperation}). It scans one
 * owned package's PHP docblocks for a leading-slash `see`-tag naming `A\B\C` (brace-wrapped or bare) —
 * the *fully-qualified, importable* form — where `A\B\C` resolves (via {@see PackageGraph}) to a HIGHER
 * tier than the file's own package, i.e. the file's package does NOT already depend on the referenced
 * FQN's package. Pint's `fully_qualified_strict_types` (with `import_symbols`) forges such a reference
 * into a real `use A\B\C;` import — an illegal upward package→app / package→higher-package composer edge.
 * (This docblock is slash-free by the same discipline the audit enforces.)
 *
 * **Tier direction reuses `PackageGraph`, not a reinvented tier map** (the ticket's IMPORTANT note). A
 * reference is *upward* exactly when the FQN's owning package is not reachable from the file's package
 * through `require` edges — the same reachability the move-audit's cycle-risk check uses. There is no
 * bespoke "app is above beam" literal here: the direction is a graph fact, so this audit stays generic
 * and foundation-tier.
 *
 * Each upward reference becomes a {@see FixableFinding}: a `Warn` {@see Finding} (`docblock.upward-see`)
 * paired with an {@see OperationSuggestion} nominating `docblock-deref` and carrying the exact splice
 * payload ({file, relativePath, line, span, old, new}) the operation applies. The `new` text is
 * {@see DocblockDerefOperation::derefText()} — the class short-name in backticks, the form Pint provably
 * leaves alone.
 */
class DocblockTierAudit implements SuggestsOperations
{
    /**
     * Matches a see-reference to a leading-slash (fully-qualified) name — either the brace-wrapped
     * `{see \A\B\C}` shape or the bare `see \A\B\C` annotation (leading slash implied, shown here
     * slash-free so this file does not trip its own audit). Only the leading-slash form is a forge
     * risk — a name without the leading slash is not an importable fully-qualified token. Group 1 is
     * the whole reference span to splice; group 2 is the FQN (without the leading slash).
     */
    private const SEE_FQN = '/(\{@see\s+\\\\([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)\s*\}|@see\s+\\\\([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+))/';

    /**
     * @param  list<string>  $files  absolute paths of the PHP files in the owned package to scan
     * @param  string  $ownPackage  the composer name of the package these files belong to
     */
    public function __construct(
        private array $files,
        private string $ownPackage,
        private PackageGraph $graph,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $findings = [];
        foreach ($this->files as $file) {
            foreach ($this->scanFile($file) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /** @return list<FixableFinding> */
    private function scanFile(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false || $code === '') {
            return [];
        }

        if (! preg_match_all(self::SEE_FQN, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $findings = [];
        foreach ($matches as $match) {
            [$whole, $start] = $match[1];
            $fqn = ($match[2][0] !== '') ? $match[2][0] : $match[3][0];

            if (! $this->isUpward($fqn)) {
                continue;
            }

            $new = DocblockDerefOperation::derefText($fqn);
            $end = $start + strlen($whole) - 1;
            $line = substr_count($code, "\n", 0, $start) + 1;
            $relative = basename($file);

            $findings[] = new FixableFinding(
                Finding::warn(
                    'docblock.upward-see',
                    "{$relative}:{$line} — docblock references higher-tier \\{$fqn} via an importable @see; "
                    ."Pint would forge `use {$fqn};` (upward edge). Deref to {$new}.",
                ),
                new OperationSuggestion(
                    'docblock-deref',
                    "Rewrite {$whole} to {$new} so Pint cannot forge an upward use import.",
                    [
                        'file' => $file,
                        'relativePath' => $relative,
                        'line' => $line,
                        'span' => [$start, $end],
                        'old' => $whole,
                        'new' => $new,
                    ],
                ),
            );
        }

        return $findings;
    }

    /**
     * Is a docblock reference to $fqn *upward* from the owning package — i.e. would importing it close a
     * package→(higher package) edge the graph does not already carry? True when the FQN's owning package
     * is known, differs from the owner, and is NOT reachable from the owner through `require` edges.
     * (An external / unresolvable FQN is not flagged — the audit only speaks to packages it can place.)
     */
    private function isUpward(string $fqn): bool
    {
        $target = $this->graph->packageForFqn($fqn);

        return $target !== null
            && $target !== $this->ownPackage
            && ! $this->graph->reaches($this->ownPackage, $target);
    }
}
