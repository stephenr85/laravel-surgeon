<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit catching a sibling drift shape to
 * {@see TrackerStatusDriftAudit} — surfaced by splicewire-app's surgeon-audit-viability wayfinder,
 * ticket 20: an issue file's OWN `Status:` line disagrees with its OWN acceptance-checkbox state
 * (`- [x]`/`- [ ]`), rather than with a sibling `PRD.md`'s prose. Same recurring cost this whole
 * conformance family exists for — code and checkboxes land, nobody circles back to flip the header.
 *
 * **Detection is purely structural — no reasoning about reality.** For the local-markdown tracker
 * convention (`{trackerDir}/<feature-slug>/issues/<NN>-<slug>.md`, a `Status:` line near the top,
 * zero or more `- [ ]`/`- [x]` checkbox lines anywhere in the body — this repo's own
 * `docs/agents/issue-tracker.md` calls these "acceptance-criteria boxes"), this audit classifies the
 * `Status:` line TERMINAL / NON_TERMINAL via the same word-list vocabulary
 * {@see TrackerStatusDriftAudit} uses, reads every checkbox line's checked state, and flags:
 *  - **non-terminal status, every checkbox checked** — the shape ticket 20 found (`Status: pending`
 *    with all 4 acceptance boxes ticked and the body's own "Implementation notes" narrating each as
 *    done/confirmed/built/documented).
 *
 * **Deliberately one-directional, not symmetric with {@see TrackerStatusDriftAudit}'s two-sided
 * check — proven wrong by live data, not assumed.** The mirror shape (terminal status, an unchecked
 * box) was built and run against this app's real `.scratch/` tree first: 157 of 159 raw findings
 * were that direction, and spot-checking several confirmed they're not drift at all — this repo's
 * real, established convention is to flip `Status:` to `done` and narrate the resolution in prose
 * (or a `## Comments`/`## Implementation notes` section) WITHOUT going back to tick every acceptance
 * box (e.g. `article-richness-gap/issues/23-persist-nonnull-thumb.md`: `Status: done (2026-07-08) —
 * shipped in thingsontv 406e869 ...`, every box still unchecked). Only 2 of 159 were the
 * non-terminal-and-fully-checked direction, and both were genuine. Shipping the terminal-direction
 * check would have buried 2 real findings under 157 false ones — removed rather than kept "for
 * completeness."
 *
 * Files with zero checkbox lines are skipped entirely — nothing to cross-reference, not a finding of
 * "no checklist" (that's a different, non-drift question this audit doesn't answer).
 *
 * **The fix stays advisory, deliberately** — same posture as {@see TrackerStatusDriftAudit}: which
 * side is stale (the header, or a checkbox that's actually still open despite being ticked) is a
 * human/agent judgment call, never a Surgeon Audit's to force. A Fail finding names the file; no
 * auto-rewrite is offered.
 */
class ChecklistStatusDriftAudit implements SuggestsOperations
{
    public const CHECK = 'checklist-status-drift';

    /**
     * @param  string  $appRoot  the running host's app root (holds the tracker directory)
     * @param  string  $trackerDir  the local-markdown tracker root, relative to $appRoot
     * @param  list<string>  $terminalWords  status vocabulary read as "done" (case-insensitive, whole-word)
     * @param  list<string>  $nonTerminalWords  status vocabulary read as "not done" (case-insensitive, whole-word)
     */
    public function __construct(
        public string $appRoot,
        public string $trackerDir = '.scratch',
        public array $terminalWords = ['done', 'closed', 'complete', 'completed', 'shipped', 'landed'],
        public array $nonTerminalWords = [
            'parked', 'blocked', 'open', 'in-progress', 'in progress', 'pending', 'todo',
            'ready-for-agent', 'ready-for-human', 'needs-triage', 'needs-info', 'stale', 'wontfix',
        ],
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        return $this->suggestFor($this->realIssues());
    }

    /**
     * The pure core — no disk. Given a discovered issue list, cross-reference each issue's own
     * `Status:` line against its own checkbox state and emit a Fail per disagreement.
     *
     * @param  list<array{file: string, status: string, checkboxes: list<bool>}>  $issues
     * @return list<FixableFinding>
     */
    public function suggestFor(array $issues): array
    {
        $findings = [];
        $checked = 0;

        foreach ($issues as $issue) {
            if ($issue['checkboxes'] === []) {
                continue; // nothing to cross-reference
            }

            $checked++;
            $statusClass = $this->classify($issue['status']);
            if ($statusClass === null) {
                continue; // unrecognized vocabulary — don't guess
            }

            $anyUnchecked = in_array(false, $issue['checkboxes'], true);
            $total = count($issue['checkboxes']);

            if ($statusClass === 'non-terminal' && ! $anyUnchecked) {
                $findings[] = new FixableFinding(
                    Finding::fail(self::CHECK, sprintf(
                        '%s\'s Status: line says "%s" (reads as non-terminal), but all %d of its own acceptance checkboxes are checked.',
                        $issue['file'],
                        trim($issue['status']),
                        $total,
                    )),
                    OperationSuggestion::advisory(
                        "Reconcile {$issue['file']}'s Status: line against its own fully-checked acceptance boxes — decide which side is stale, by hand.",
                        'rushing/laravel-surgeon',
                    ),
                );
            }
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                "No Status:-vs-own-checkboxes disagreements found across {$checked} checklist-bearing issue(s).",
            ));
        }

        return $findings;
    }

    /** Classify a status fragment TERMINAL / NON_TERMINAL / null (unrecognized or ambiguous). */
    private function classify(string $text): ?string
    {
        $isTerminal = $this->matchesAny($text, $this->terminalWords);
        $isNonTerminal = $this->matchesAny($text, $this->nonTerminalWords);

        if ($isTerminal && ! $isNonTerminal) {
            return 'terminal';
        }
        if ($isNonTerminal && ! $isTerminal) {
            return 'non-terminal';
        }

        return null; // neither, or both (ambiguous) — don't guess
    }

    /** @param  list<string>  $words */
    private function matchesAny(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The real filesystem read: every `{trackerDir}/<slug>/issues/<NN>-<slug>.md` file, per
     * `docs/agents/issue-tracker.md`'s convention. No PRD needed — this check is intra-file only.
     *
     * @return list<array{file: string, status: string, checkboxes: list<bool>}>
     */
    private function realIssues(): array
    {
        $root = rtrim($this->appRoot, '/').'/'.trim($this->trackerDir, '/');
        if (! is_dir($root)) {
            return [];
        }

        $issues = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($root.'/'.$entry)) {
                continue;
            }

            $issuesDir = $root.'/'.$entry.'/issues';
            if (! is_dir($issuesDir)) {
                continue;
            }

            foreach (scandir($issuesDir) ?: [] as $issueFile) {
                if (! preg_match('/^\d+-.+\.md$/', $issueFile)) {
                    continue;
                }

                $contents = @file_get_contents($issuesDir.'/'.$issueFile) ?: '';
                $status = $this->extractStatus($contents);
                if ($status === null) {
                    continue;
                }

                $issues[] = [
                    'file' => $entry.'/issues/'.$issueFile,
                    'status' => $status,
                    'checkboxes' => $this->extractCheckboxes($contents),
                ];
            }
        }

        return $issues;
    }

    /**
     * The issue's own status fragment: the text right after a `Status:` marker (optionally
     * markdown-bolded) up to the first strong delimiter — a parenthesis, an em-dash, or the line end
     * — so a long trailing rationale never dilutes the classification. Mirrors
     * {@see TrackerStatusDriftAudit::extractStatus()} exactly (duplicated, not shared, matching this
     * package's own established cross-file duplication posture for small structural helpers).
     */
    private function extractStatus(string $contents): ?string
    {
        if (preg_match('/^\s*(?:\*\*)?Status:(?:\*\*)?\s*([^\n(—]*)/mi', $contents, $m) !== 1) {
            return null;
        }

        return trim($m[1], " \t*.");
    }

    /**
     * Every `- [ ]`/`- [x]`/`- [X]` checkbox line anywhere in the file, in document order, as a
     * plain checked/unchecked bool list.
     *
     * @return list<bool>
     */
    private function extractCheckboxes(string $contents): array
    {
        if (preg_match_all('/^\s*-\s*\[([ xX])\]/m', $contents, $m) === false) {
            return [];
        }

        return array_map(fn ($mark) => strtolower($mark) === 'x', $m[1]);
    }
}
