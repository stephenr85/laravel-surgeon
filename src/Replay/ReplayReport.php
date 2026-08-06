<?php

namespace Rushing\Surgeon\Replay;

use Rushing\Doctor\Finding;

/**
 * The delta of one golden replay — what the audit got right, what it missed, and where it
 * over-reached, measured against a real historical campaign's ground-truth file set.
 *
 * The three sets carry different weight (see {@see self::toDoctorFindings()}):
 *
 *  - **matched** — files the tool enumerated that the campaign really touched. The win.
 *  - **missed** — files the campaign touched that the tool did NOT enumerate. This is the
 *    load-bearing signal: a miss is exactly the FR false-premise failure the audit exists to
 *    prevent, so a non-empty `missed` is a FAIL.
 *  - **overreached** — files the tool enumerated that the campaign did not touch. A WARN, not a
 *    fail: the pre-move tree can legitimately reference a moved symbol in a spot the human handled
 *    by another mechanism (or a candidate they left). It is signal to inspect, not a defect proof.
 */
class ReplayReport
{
    /**
     * @param  list<string>  $toolFiles  repo-relative files the audit enumerated (ground-truth-scoped)
     * @param  list<string>  $actualFiles  repo-relative files the campaign actually changed (scoped)
     * @param  list<string>  $matched
     * @param  list<string>  $missed
     * @param  list<string>  $overreached
     */
    public function __construct(
        public string $fixtureName,
        public string $beforeRef,
        public string $afterRef,
        public array $toolFiles,
        public array $actualFiles,
        public array $matched,
        public array $missed,
        public array $overreached,
        public int $referenceCount,
    ) {}

    /**
     * Assemble a report by set-differencing the tool's enumeration against ground truth. Both sides
     * are already ground-truth-scoped (the harness applies the fixture's include/exclude globs).
     *
     * @param  list<string>  $toolFiles
     * @param  list<string>  $actualFiles
     */
    public static function diff(
        CampaignFixture $fixture,
        string $beforeRef,
        string $afterRef,
        array $toolFiles,
        array $actualFiles,
        int $referenceCount,
    ): self {
        $tool = array_values(array_unique($toolFiles));
        $actual = array_values(array_unique($actualFiles));
        sort($tool);
        sort($actual);

        $matched = array_values(array_intersect($actual, $tool));
        $missed = array_values(array_diff($actual, $tool));
        $overreached = array_values(array_diff($tool, $actual));

        return new self(
            fixtureName: $fixture->name,
            beforeRef: $beforeRef,
            afterRef: $afterRef,
            toolFiles: $tool,
            actualFiles: $actual,
            matched: $matched,
            missed: $missed,
            overreached: $overreached,
            referenceCount: $referenceCount,
        );
    }

    /** A replay is clean when the audit missed nothing — over-reach is a warning, not a break. */
    public function isClean(): bool
    {
        return $this->missed === [];
    }

    /** Enumeration fidelity: matched / actual, the headline acceptance number (1.0 = no misses). */
    public function recall(): float
    {
        if ($this->actualFiles === []) {
            return 1.0;
        }

        return count($this->matched) / count($this->actualFiles);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fixture' => $this->fixtureName,
            'before' => $this->beforeRef,
            'after' => $this->afterRef,
            'summary' => [
                'references' => $this->referenceCount,
                'tool_files' => count($this->toolFiles),
                'actual_files' => count($this->actualFiles),
                'matched' => count($this->matched),
                'missed' => count($this->missed),
                'overreached' => count($this->overreached),
                'recall' => round($this->recall(), 4),
                'clean' => $this->isClean(),
            ],
            'matched' => $this->matched,
            'missed' => $this->missed,
            'overreached' => $this->overreached,
        ];
    }

    /**
     * Project into doctor's `Finding` vocabulary — the same channel `surgeon:audit` reports through
     * (ticket 07 grounding). One Fail per miss-bearing replay, a Warn carrying the over-reach, and a
     * clean Pass when the audit reproduced the campaign exactly.
     *
     * @return list<Finding>
     */
    public function toDoctorFindings(): array
    {
        $check = 'surgeon:replay '.$this->fixtureName;
        $findings = [];

        if ($this->missed === []) {
            $findings[] = Finding::pass(
                $check,
                'reproduced '.count($this->matched).'/'.count($this->actualFiles).' touched file(s) — no misses.',
            );
        } else {
            $findings[] = Finding::fail(
                $check.' — misses',
                count($this->missed).' file(s) the campaign touched were not enumerated: '
                    .implode(', ', array_slice($this->missed, 0, 8)).(count($this->missed) > 8 ? ' …' : ''),
            );
        }

        if ($this->overreached !== []) {
            $findings[] = Finding::warn(
                $check.' — over-reach',
                count($this->overreached).' enumerated file(s) the campaign did not touch: '
                    .implode(', ', array_slice($this->overreached, 0, 8)).(count($this->overreached) > 8 ? ' …' : ''),
            );
        }

        return $findings;
    }
}
