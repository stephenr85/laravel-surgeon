<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Diagnoses whether a project's co-dev overlay is *ready to canonicalize* (ticket 11) — the detect half,
 * and (with {@see OverlayAudit}) one of the two non-AST proofs of the audit spine. For each path repo the
 * project actually requires, it reads the checkout's git state and asks the one question that decides a
 * shippable lock: would a canonical `composer update` resolve the code you tested? A package required as
 * `dev-main` resolves its *pushed tip*, so:
 *
 *  - **not a git repo** → **Fail**, NO fix: a path package that isn't a repo can't be git-resolved at all.
 *  - **dirty checkout** → **Fail**, NO fix: uncommitted work would silently vanish from the lock, and
 *    committing it is a judgment call the tool won't auto-make (the deterministic/agentic seam — a dirty
 *    package is the hard stop the `composer-canonicalize` skill has always enforced).
 *  - **no upstream** → **Fail**, NO fix: the lock can't resolve a tip that isn't on a remote; choosing or
 *    creating the upstream is judgment.
 *  - **ahead of upstream** → **Warn**, auto-fixable: committed-but-unpushed commits would also vanish,
 *    but the fix is deterministic — a push. Nominates the {@see CanonicalizationOperation} (its gated
 *    `--push` step), holding the seam: judgment stays Fail, the mechanical case is a fixable Warn.
 *  - **clean, pushed, in sync** → contributes to the aggregate Pass.
 *
 * Surfaced as `surgeon:canonicalize` (dry-run) — "what would canonicalize break?" — before anyone takes
 * the overlay offline. Emits the same {@see FixableFinding} vocabulary as {@see OverlayAudit}, through the
 * ticket-07/13 bridge. The git read is injectable so the mapping is unit-testable without real remotes.
 */
class CanonicalizationAudit implements SuggestsOperations
{
    /** @var callable(string): RepoStatus */
    private $inspect;

    /** @param  (callable(string): RepoStatus)|null  $inspect  git-state reader (default: real git) */
    public function __construct(
        public string $baseDir,
        ?callable $inspect = null,
    ) {
        $this->inspect = $inspect ?? fn (string $dir) => GitInspector::status($dir);
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $manifest = OverlayManifest::fromDirectory($this->baseDir);

        if ($manifest->state === OverlayState::Absent) {
            return [new FixableFinding(
                Finding::pass('canonicalize.no-overlay', 'No co-dev overlay in this project — nothing to canonicalize.'),
            )];
        }

        $required = $manifest->requiredEntries();
        if ($required === []) {
            return [new FixableFinding(
                Finding::pass('canonicalize.no-path-repos', 'No required path repos in the overlay — the lock is already git-resolved.'),
            )];
        }

        $findings = [];
        foreach ($required as $entry) {
            $status = ($this->inspect)($entry->path);
            $pkg = $entry->name ?? $entry->url;

            if (! $status->isRepo) {
                $findings[] = new FixableFinding(Finding::fail(
                    'canonicalize.not-a-repo',
                    "{$pkg}: path-repo checkout is not a git repository — the shippable lock can't git-resolve it.",
                ));

                continue;
            }
            if ($status->isDirty) {
                $findings[] = new FixableFinding(Finding::fail(
                    'canonicalize.dirty',
                    "{$pkg}: uncommitted changes — they would silently vanish from the git-resolved lock (commit or stash first).",
                ));

                continue;
            }
            if (! $status->hasUpstream) {
                $findings[] = new FixableFinding(Finding::fail(
                    'canonicalize.no-upstream',
                    "{$pkg}: no upstream branch — the lock can't resolve a tip that isn't on a remote.",
                ));

                continue;
            }
            if ($status->ahead > 0) {
                $findings[] = new FixableFinding(
                    Finding::warn(
                        'canonicalize.ahead',
                        "{$pkg}: {$status->ahead} unpushed commit(s) — they would vanish from the lock until pushed.",
                    ),
                    new OperationSuggestion(
                        (new CanonicalizationOperation)->kind(),
                        "Push {$pkg} so the git-resolved lock sees its tip.",
                        ['package' => $pkg, 'dir' => $entry->path, 'ahead' => $status->ahead],
                    ),
                );
            }
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                'canonicalize.ready',
                count($required).' path repo(s) clean, pushed, and in sync — safe to canonicalize.',
            ));
        }

        return $findings;
    }
}
