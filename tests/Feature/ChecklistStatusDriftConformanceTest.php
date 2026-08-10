<?php

use Rushing\Surgeon\Conformance\ChecklistStatusDriftAudit;

/**
 * Surgeon-audit-viability ticket 20 — the intra-file Status:-vs-own-checkboxes cross-reference.
 * Proven both via the pure `suggestFor()` core (no disk) and against a real temp `.scratch/` tree.
 */
it('flags a non-terminal Status: line when every checkbox is checked', function () {
    $findings = (new ChecklistStatusDriftAudit('/unused'))->suggestFor([
        [
            'file' => 'sourced-particles/issues/10-byo-hardening-checklist.md',
            'status' => 'pending',
            'checkboxes' => [true, true, true, true],
        ],
    ]);

    $drift = array_values(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK));

    expect($drift)->toHaveCount(1)
        ->and($drift[0]->finding->detail)->toContain('10-byo-hardening-checklist.md')
        ->and($drift[0]->finding->detail)->toContain('pending')
        ->and($drift[0]->finding->detail)->toContain('4')
        ->and($drift[0]->isAdvisory())->toBeTrue()
        ->and($drift[0]->isFixable())->toBeFalse();
});

it('does NOT flag a terminal Status: line even when a checkbox is still unchecked (this repo\'s real convention)', function () {
    // Proven against the live app: closing an issue here means flipping Status: and narrating the
    // resolution in prose, not going back to tick every acceptance box. Checking this direction
    // produced 157 false positives against 2 real ones in the real .scratch/ tree — deliberately
    // one-directional, not a gap.
    $findings = (new ChecklistStatusDriftAudit('/unused'))->suggestFor([
        [
            'file' => '01-thing.md',
            'status' => 'done',
            'checkboxes' => [true, false, true],
        ],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK))->toBe([]);
});

it('does NOT flag a non-terminal status with an open checkbox', function () {
    $findings = (new ChecklistStatusDriftAudit('/unused'))->suggestFor([
        ['file' => '01-thing.md', 'status' => 'in-progress', 'checkboxes' => [true, false]],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK))->toBe([]);
});

it('does NOT flag a terminal status with every checkbox checked', function () {
    $findings = (new ChecklistStatusDriftAudit('/unused'))->suggestFor([
        ['file' => '01-thing.md', 'status' => 'done', 'checkboxes' => [true, true]],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK))->toBe([]);
});

it('skips a file with no checkbox lines at all', function () {
    $findings = (new ChecklistStatusDriftAudit('/unused'))->suggestFor([
        ['file' => '01-thing.md', 'status' => 'pending', 'checkboxes' => []],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK))->toBe([])
        ->and($findings[0]->finding->check)->toBe(ChecklistStatusDriftAudit::CHECK.'.clean');
});

it('does NOT flag when the Status: itself is unrecognized vocabulary', function () {
    $findings = (new ChecklistStatusDriftAudit('/unused'))->suggestFor([
        ['file' => '01-thing.md', 'status' => 'ready-for-review', 'checkboxes' => [true, true]],
    ]);

    expect(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK))->toBe([]);
});

it('reads a real .scratch/ tree off disk end-to-end', function () {
    $root = surgeon_tmp('checklist-status-drift');

    surgeon_write($root.'/.scratch/sourced-particles/issues/10-byo-hardening-checklist.md', <<<'MD'
        # 10 — BYO hardening checklist

        Status: pending

        **Acceptance:**
        - [x] Vault reads are tenant-scoped.
        - [x] Federation resolve endpoint auth is confirmed.
        - [x] Per-conduit consumption quota enforceable.
        - [x] Sandbox limits documented.
        MD);

    try {
        $findings = (new ChecklistStatusDriftAudit($root))->suggestOperations();
        $drift = array_values(array_filter($findings, fn ($f) => $f->finding->check === ChecklistStatusDriftAudit::CHECK));

        expect($drift)->toHaveCount(1)
            ->and($drift[0]->finding->detail)->toContain('10-byo-hardening-checklist.md');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('passes cleanly when there is no .scratch/ tracker directory at all', function () {
    $root = surgeon_tmp('checklist-status-drift-empty');

    try {
        $findings = (new ChecklistStatusDriftAudit($root))->suggestOperations();

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->finding->check)->toBe(ChecklistStatusDriftAudit::CHECK.'.clean');
    } finally {
        surgeon_rrmdir($root);
    }
});
