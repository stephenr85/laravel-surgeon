<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * The sidecar record of exactly what a `surgeon:move --apply` wrote (ticket 03, decision 2) — the
 * review + scoped-recovery surface. It lists every file the tool touched and the applied operation
 * set, so a reviewer sees the blast radius at a glance and a later scoped undo knows precisely which
 * files are the tool's (never clobbering pre-existing dirt under `--allow-dirty`).
 *
 * It also holds, in memory, each touched file's pre-tool bytes so the applier can perform the
 * deterministic gate's **manifest-scoped auto-rollback** on failure — restoring only tool-touched
 * files, leaving all other working-tree state exactly as found. Those byte blobs stay out of
 * {@see toArray()}: the persisted sidecar is an operation manifest, not a backup.
 */
class TouchManifest
{
    /** @var list<array{file: string, relativePath: string, original: string, updated: string}> */
    public array $writes = [];

    /** @var array{from: string, to: string, fromRelative: string, toRelative: string}|null */
    public ?array $move = null;

    public function __construct(public RewritePlan $plan) {}

    public function recordWrite(string $file, string $relativePath, string $original, string $updated): void
    {
        $this->writes[] = compact('file', 'relativePath', 'original', 'updated');
    }

    public function recordMove(PhysicalMove $move): void
    {
        $this->move = [
            'from' => $move->from,
            'to' => $move->to,
            'fromRelative' => $move->fromRelative,
            'toRelative' => $move->toRelative,
        ];
    }

    /** @return list<string> absolute paths of every file the tool wrote or relocated */
    public function touchedFiles(): array
    {
        $files = array_map(fn (array $w) => $w['file'], $this->writes);
        if ($this->move !== null) {
            $files[] = $this->move['to'];
        }

        return array_values(array_unique($files));
    }

    /** @return array<string, mixed> the persisted sidecar shape (no byte blobs) */
    public function toArray(): array
    {
        return [
            'target' => [
                'old' => $this->plan->target->value,
                'new' => $this->plan->target->newFqn,
            ],
            'files' => array_map(fn (array $w) => $w['relativePath'], $this->writes),
            'move' => $this->move === null ? null : [
                'from' => $this->move['fromRelative'],
                'to' => $this->move['toRelative'],
            ],
            'operation' => $this->plan->toArray(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
