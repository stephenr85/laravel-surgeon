<?php

use Rushing\Surgeon\Conformance\TrackerStatusDriftAudit;

/**
 * Surgeon-audit-viability ticket 11 — the PRD-vs-issue Status: cross-reference. Proven both via the
 * pure `suggestFor()` core (no disk) and against a real temp `.scratch/` tree (the real read path).
 */
it('flags a PRD line that disagrees with the issue\'s own terminal Status:', function () {
    $findings = (new TrackerStatusDriftAudit('/unused'))->suggestFor([
        [
            'slug' => 'assistants-to-agents-seam',
            'prd' => "## Landing state\n- **AGT-03 — Regenerate contracts** · **PARKED**.\n",
            'issues' => [
                ['file' => '03-regenerate-contracts-and-rename-ui-and-docs.md', 'number' => '03', 'status' => 'done (2026-08-06) — the blocker was resolved upstream'],
            ],
        ],
    ]);

    $drift = array_values(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK));

    expect($drift)->toHaveCount(1)
        ->and($drift[0]->finding->detail)->toContain('AGT-03')
        ->and($drift[0]->finding->detail)->toContain('PARKED')
        ->and($drift[0]->finding->detail)->toContain('done')
        ->and($drift[0]->isAdvisory())->toBeTrue()
        ->and($drift[0]->isFixable())->toBeFalse();
});

it('does NOT flag a PRD line that agrees with the issue\'s own Status:', function () {
    $findings = (new TrackerStatusDriftAudit('/unused'))->suggestFor([
        [
            'slug' => 'feature',
            'prd' => "## Landing state\n- **FT-01 — Thing** · **done**.\n",
            'issues' => [
                ['file' => '01-thing.md', 'number' => '01', 'status' => 'done'],
            ],
        ],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK))->toBe([])
        ->and($findings[0]->finding->check)->toBe(TrackerStatusDriftAudit::CHECK.'.clean');
});

it('does NOT flag when the PRD mentions the issue with no status word on that line', function () {
    $findings = (new TrackerStatusDriftAudit('/unused'))->suggestFor([
        [
            'slug' => 'feature',
            'prd' => "See AGT-03 for the seam design.\n",
            'issues' => [
                ['file' => '03-thing.md', 'number' => '03', 'status' => 'done'],
            ],
        ],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK))->toBe([]);
});

it('does NOT flag when the issue Status: itself is unrecognized vocabulary', function () {
    $findings = (new TrackerStatusDriftAudit('/unused'))->suggestFor([
        [
            'slug' => 'feature',
            'prd' => "- **FT-01** · **PARKED**\n",
            'issues' => [
                ['file' => '01-thing.md', 'number' => '01', 'status' => 'ready-for-review'],
            ],
        ],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK))->toBe([]);
});

it('matches by filename stem as well as by code token', function () {
    $findings = (new TrackerStatusDriftAudit('/unused'))->suggestFor([
        [
            'slug' => 'feature',
            'prd' => "01-thing.md is now **blocked** pending review.\n",
            'issues' => [
                ['file' => '01-thing.md', 'number' => '01', 'status' => 'done'],
            ],
        ],
    ]);

    $drift = array_values(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK));

    expect($drift)->toHaveCount(1);
});

it('does NOT flag a status word in plain, unstyled prose describing the issue\'s subject matter', function () {
    // The real false positive this guards: "stale ... docblock, orphaned" describes what issue 07 was
    // ABOUT (fixing a stale docblock), not a claim that the issue itself is unresolved — the issue's own
    // Status: line can (and here does) explicitly contradict that plain-prose word ("not orphaned").
    $findings = (new TrackerStatusDriftAudit('/unused'))->suggestFor([
        [
            'slug' => 'composition-dialect-gaps',
            'prd' => "7. `07-housekeeping.md` — stale CompositionLifecycle docblock, orphaned\n",
            'issues' => [
                ['file' => '07-housekeeping.md', 'number' => '07', 'status' => '2 done 2026-07-10; 1 corrected (not orphaned — do NOT delete)'],
            ],
        ],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK))->toBe([]);
});

it('reads a real .scratch/ tree off disk end-to-end', function () {
    $root = surgeon_tmp('tracker-status-drift');

    surgeon_write($root.'/.scratch/my-feature/PRD.md', <<<'MD'
        # My Feature

        ## Landing state
        - **MF-01 — Do the thing** · **PARKED**.
        MD);

    surgeon_write($root.'/.scratch/my-feature/issues/01-do-the-thing.md', <<<'MD'
        Status: done (2026-08-06) — shipped and verified.

        # Do the thing
        MD);

    try {
        $findings = (new TrackerStatusDriftAudit($root))->suggestOperations();
        $drift = array_values(array_filter($findings, fn ($f) => $f->finding->check === TrackerStatusDriftAudit::CHECK));

        expect($drift)->toHaveCount(1)
            ->and($drift[0]->finding->detail)->toContain('my-feature/PRD.md')
            ->and($drift[0]->finding->detail)->toContain('01-do-the-thing.md');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes cleanly when there is no .scratch/ tracker directory at all', function () {
    $root = surgeon_tmp('tracker-status-drift-empty');

    try {
        $findings = (new TrackerStatusDriftAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->check)->toBe(TrackerStatusDriftAudit::CHECK.'.clean');
    } finally {
        surgeon_rrmdir($root);
    }
});
