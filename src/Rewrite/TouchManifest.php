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

    /** @var array{from: string, to: string, fromRelative: string, toRelative: string, crossRepo: bool, sourceRepo: ?string, destinationRepo: ?string}|null */
    public ?array $move = null;

    /**
     * Every recorded physical move — one per moving member for an atomic cluster. {@see $move} is the
     * first (and, for a single-symbol relocation, only) entry, kept for backward-compatible callers.
     *
     * @var list<array{from: string, to: string, fromRelative: string, toRelative: string, crossRepo: bool, sourceRepo: ?string, destinationRepo: ?string}>
     */
    public array $moves = [];

    public function __construct(public RewritePlan $plan) {}

    public function recordWrite(string $file, string $relativePath, string $original, string $updated): void
    {
        $this->writes[] = compact('file', 'relativePath', 'original', 'updated');
    }

    public function recordMove(PhysicalMove $move): void
    {
        // The `crossRepo` flag + the two repo roots ride in memory so the applier's rollback knows to
        // reverse a two-repo copy+delete (ticket 14), and so the deterministic gate can target the
        // destination repo. They are omitted from the persisted sidecar shape (that stays a legible
        // relative-path operation manifest, not an execution-mode dump) — see {@see toArray()}.
        $entry = [
            'from' => $move->from,
            'to' => $move->to,
            'fromRelative' => $move->fromRelative,
            'toRelative' => $move->toRelative,
            'crossRepo' => $move->crossRepo,
            'sourceRepo' => $move->sourceRepo,
            'destinationRepo' => $move->destinationRepo,
        ];
        $this->moves[] = $entry;
        $this->move ??= $entry;
    }

    /** @return list<string> absolute paths of every file the tool wrote or relocated */
    public function touchedFiles(): array
    {
        $files = array_map(fn (array $w) => $w['file'], $this->writes);
        foreach ($this->moves as $move) {
            $files[] = $move['to'];
        }

        return array_values(array_unique($files));
    }

    /**
     * The roots that hosted this operation's writes — the source roots of every spliced file plus, for
     * a cross-repo move, the DESTINATION repo (ticket 14). The deterministic gate consumes this so
     * `php -l`/`dump-autoload`/topology run in the destination repo where the moved class now lives,
     * not only the source. De-duplicated; empty entries dropped.
     *
     * @param  list<string>  $namedRoots  the write blast-radius roots (from `--root`)
     * @return list<string>
     */
    public function gateRoots(array $namedRoots): array
    {
        $roots = $namedRoots;
        foreach ($this->moves as $move) {
            if (! empty($move['crossRepo']) && $move['destinationRepo'] !== null) {
                $roots[] = $move['destinationRepo'];
            }
        }

        return array_values(array_unique(array_filter($roots, fn ($r) => $r !== null && $r !== '')));
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
            'moves' => array_map(fn (array $m) => [
                'from' => $m['fromRelative'],
                'to' => $m['toRelative'],
            ], $this->moves),
            'operation' => $this->plan->toArray(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
