<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit catching a real drift shape (surfaced by
 * splicewire-app's surgeon-audit-viability wayfinder, ticket 11): a feature's `PRD.md` makes its OWN
 * terminal-status claim about a child issue ("AGT-03 · PARKED"), and that claim silently goes stale
 * once the child issue's own `Status:` line moves on — nobody re-reads the PRD's prose after closing
 * the issue it summarizes.
 *
 * **Detection is purely structural — no reasoning about reality.** For the local-markdown tracker
 * convention (`{trackerDir}/<feature-slug>/PRD.md` + `{trackerDir}/<feature-slug>/issues/<NN>-<slug>.md`,
 * one `Status:` line near the top of each issue file), this audit:
 *  1. reads each issue's own `Status:` line and classifies it TERMINAL / NON_TERMINAL via a plain
 *     word-list match (config-supplied, not inferred);
 *  2. finds every line in the sibling `PRD.md` that mentions that issue by a `LETTERS-NN` code token
 *     (e.g. `AGT-03`) or its filename stem, AND carries a status word from the same vocabulary INSIDE a
 *     markdown-bold span (`**PARKED**`) — this tracker's own convention for a status claim, as opposed
 *     to incidental prose describing the issue's subject matter (see {@see boldSpans()});
 *  3. flags a disagreement when the PRD line's classification differs from the issue's own.
 *
 * This is a cross-reference of two ALREADY-DECLARED status claims, not a judgment about whether work
 * is actually done — squarely inside the deterministic bar (a Surgeon Audit renders no verdict on
 * reality, only on whether two things that both claim to state it agree). Foundation-clean: names no
 * `splicewire/*`/`schemastud/*` concept, just the tracker shape `docs/agents/issue-tracker.md`-style
 * conventions describe, which the wayfinder skill also treats as the generic local-markdown fallback.
 *
 * **The fix stays advisory, deliberately** — same posture as {@see LocalMcpWiringAudit}: which side is
 * wrong (the PRD's summary, or the issue's own status) is exactly the judgment call
 * `CLAUDE.md`'s Notes reserve for a human/agent, never a Surgeon Audit. A Fail finding names both
 * locations; no auto-rewrite is offered.
 *
 * **Known limitations, by design, not gaps to close blindly:** only same-line PRD mentions are
 * considered (a status word stated on a preceding group header, with the bullet itself silent, is
 * invisible to this audit); and only bold-wrapped status words count (a status stated in plain,
 * unstyled prose is invisible too) — the "don't guess" posture every audit in this file shares.
 */
class TrackerStatusDriftAudit implements SuggestsOperations
{
    public const CHECK = 'tracker-status-drift';

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
        return $this->suggestFor($this->realFeatures());
    }

    /**
     * The pure core — no disk. Given a discovered feature list, cross-reference each issue's own status
     * against every same-line PRD mention and emit a Fail per disagreement.
     *
     * @param  list<array{slug: string, prd: ?string, issues: list<array{file: string, number: string, status: string}>}>  $features
     * @return list<FixableFinding>
     */
    public function suggestFor(array $features): array
    {
        $findings = [];
        $checked = 0;

        foreach ($features as $feature) {
            if ($feature['prd'] === null || $feature['issues'] === []) {
                continue;
            }

            foreach ($feature['issues'] as $issue) {
                $checked++;
                $issueClass = $this->classify($issue['status']);
                if ($issueClass === null) {
                    continue; // unrecognized vocabulary — don't guess
                }

                foreach ($this->prdMentions($feature['prd'], $issue['number'], $issue['file']) as $line) {
                    $prdClass = $this->classify($this->boldSpans($line));
                    if ($prdClass === null || $prdClass === $issueClass) {
                        continue;
                    }

                    $findings[] = new FixableFinding(
                        Finding::fail(self::CHECK, sprintf(
                            "%s/PRD.md says \"%s\" (reads as %s) for issue %s, but %s's own Status: line says \"%s\" (reads as %s).",
                            $feature['slug'],
                            trim($line),
                            $prdClass,
                            $issue['file'],
                            $issue['file'],
                            trim($issue['status']),
                            $issueClass,
                        )),
                        OperationSuggestion::advisory(
                            "Reconcile {$feature['slug']}/PRD.md's status claim for {$issue['file']} against the issue's own Status: line — decide which side is stale, by hand.",
                            'rushing/laravel-surgeon',
                        ),
                    );
                }
            }
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                "No PRD-vs-issue Status: disagreements found across {$checked} issue(s) in ".count($features).' feature(s).',
            ));
        }

        return $findings;
    }

    /**
     * Every same-line PRD mention of the issue: a line containing either a `LETTERS-NN` code token
     * (e.g. `AGT-03`, matched by the issue's own numeric prefix with leading zeros stripped) or the
     * issue's filename stem (without the `.md` extension).
     *
     * @return list<string>
     */
    private function prdMentions(string $prdText, string $number, string $file): array
    {
        $stem = preg_replace('/\.md$/', '', $file) ?? $file;
        $numberInt = ltrim($number, '0');
        $numberInt = $numberInt === '' ? '0' : $numberInt;

        $codePattern = '/\b[A-Za-z]{2,8}-0*'.preg_quote($numberInt, '/').'\b/';

        $lines = [];
        foreach (preg_split('/\R/', $prdText) ?: [] as $line) {
            if (str_contains($line, $stem) || preg_match($codePattern, $line) === 1) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The line's markdown-bold spans only (`**...**`), joined — a PRD line mentioning an issue often
     * has BOTH a status claim ("**PARKED**") and incidental prose describing the issue's subject matter
     * ("stale docblock, orphaned"); only the former is a status declaration in this tracker's own
     * convention (every real `Status:`/landing-state claim observed is bold-wrapped). Restricting
     * classification to bold spans is what keeps this audit from reading subject-matter words as status
     * claims — caught live: `composition-dialect-gaps/PRD.md`'s "stale ... docblock, orphaned" (plain
     * prose about the issue's CONTENT) would otherwise misfire against an issue whose own Status line
     * explicitly reads "not orphaned". A line with no bold span classifies as unrecognized (empty string
     * matches nothing) — don't guess from unstyled prose.
     */
    private function boldSpans(string $line): string
    {
        preg_match_all('/\*\*(.*?)\*\*/', $line, $m);

        return implode(' ', $m[1]);
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
     * The real filesystem read: every `{trackerDir}/<slug>/` directory holding a `PRD.md` and an
     * `issues/` subdir, per `docs/agents/issue-tracker.md`'s convention.
     *
     * @return list<array{slug: string, prd: ?string, issues: list<array{file: string, number: string, status: string}>}>
     */
    private function realFeatures(): array
    {
        $root = rtrim($this->appRoot, '/').'/'.trim($this->trackerDir, '/');
        if (! is_dir($root)) {
            return [];
        }

        $features = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($root.'/'.$entry)) {
                continue;
            }

            $issuesDir = $root.'/'.$entry.'/issues';
            if (! is_dir($issuesDir)) {
                continue;
            }

            $prdPath = $root.'/'.$entry.'/PRD.md';
            $prd = is_file($prdPath) ? (@file_get_contents($prdPath) ?: null) : null;

            $issues = [];
            foreach (scandir($issuesDir) ?: [] as $issueFile) {
                if (! preg_match('/^(\d+)-.+\.md$/', $issueFile, $m)) {
                    continue;
                }
                $contents = @file_get_contents($issuesDir.'/'.$issueFile) ?: '';
                $status = $this->extractStatus($contents);
                if ($status === null) {
                    continue;
                }

                $issues[] = ['file' => $issueFile, 'number' => $m[1], 'status' => $status];
            }

            $features[] = ['slug' => $entry, 'prd' => $prd, 'issues' => $issues];
        }

        return $features;
    }

    /**
     * The issue's own status fragment: the text right after a `Status:` marker (optionally
     * markdown-bolded) up to the first strong delimiter — a parenthesis, an em-dash, or the line end —
     * so a long trailing rationale never dilutes the classification.
     */
    private function extractStatus(string $contents): ?string
    {
        if (preg_match('/^\s*(?:\*\*)?Status:(?:\*\*)?\s*([^\n(—]*)/mi', $contents, $m) !== 1) {
            return null;
        }

        return trim($m[1], " \t*.");
    }
}
