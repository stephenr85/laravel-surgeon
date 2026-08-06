<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Diagnoses a project's co-dev overlay and pairs each mal-adaptation with the deterministic
 * {@see OverlayOperation} that fixes it (ticket 10) — the overlay family's entry into the ticket-07/13
 * Finding→Operation bridge. It emits the same {@see FixableFinding} vocabulary as a package conformance
 * audit, so `surgeon:overlay` reports and nominates through exactly the machinery `surgeon:audit` uses,
 * but over the *overlay* (a surgeon-owned, host-level concern) rather than the host's doctor manifest.
 * This is the "emits Findings through the bridge" the ticket asks for, and the second non-AST proof of
 * the audit spine (canonicalize, ticket 11, is the other).
 *
 * The mal-adaptations, and their deterministic fixes:
 *  - **broken path repo** — a declared `type: path` url whose checkout is gone → **Fail**, nominates
 *    `overlay-remove` (dropping a stale entry is deterministic + safe).
 *  - **overlay off** — only a template exists (`OverlayState::Template`) → **Warn**, nominates
 *    `overlay-materialize` (the copy is deterministic).
 *  - **require without path repo** — a co-dev `require` the overlay can't satisfy locally → **Warn**,
 *    NO suggestion: locating the right checkout to `add` is a judgment call, so it stays a plain
 *    diagnosis (preserving the deterministic/agentic seam).
 *
 * Harmless extras (a resolvable path repo no `require` engages) are intentionally silent — the
 * merge-plugin never materializes them, so they are not a defect ({@see OverlayEntry::isExtra()}).
 */
class OverlayAudit implements SuggestsOperations
{
    public function __construct(
        public string $baseDir,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $manifest = OverlayManifest::fromDirectory($this->baseDir);

        if ($manifest->state === OverlayState::Absent) {
            return [new FixableFinding(
                Finding::pass('overlay.absent', 'No co-dev overlay in this project — nothing to manage.'),
            )];
        }

        $findings = [];

        foreach ($manifest->brokenEntries() as $entry) {
            $findings[] = new FixableFinding(
                Finding::fail('overlay.broken-path-repo', "Stale path repo: {$entry->url} → {$entry->path} (checkout missing)."),
                new OperationSuggestion(
                    (new OverlayOperation(OverlayVerb::Remove))->kind(),
                    "Remove the stale path repo {$entry->url}.",
                    ['url' => $entry->url, 'name' => $entry->name],
                ),
            );
        }

        if ($manifest->state === OverlayState::Template) {
            $findings[] = new FixableFinding(
                Finding::warn('overlay.off', 'Overlay is off (template only) — path repos are not symlinked.'),
                new OperationSuggestion(
                    (new OverlayOperation(OverlayVerb::Materialize))->kind(),
                    'Materialize the overlay (copy the template to composer.local.json).',
                ),
            );
        }

        foreach ($manifest->requiresWithoutPathRepo() as $name) {
            $findings[] = new FixableFinding(
                Finding::warn('overlay.require-without-path-repo', "Overlay requires {$name} but declares no path repo for it (resolves from git, not a local checkout)."),
            );
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(
                Finding::pass('overlay.healthy', count($manifest->requiredEntries()).' path repo(s) required and resolvable; overlay is consistent.'),
            );
        }

        return $findings;
    }
}
