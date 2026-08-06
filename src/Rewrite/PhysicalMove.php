<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * The tool-owned physical relocation of the moved symbol's own file (ticket 02, decision 5): a
 * PSR-4-classed symbol's file moves from its old autoload-derived path to the new one.
 *
 * This is derived, never authored — {@see RewritePlanner} resolves `from`/`to` from the FQN pair
 * against the composer PSR-4 map. When the source can't be resolved to a PSR-4 root (a non-PSR-4
 * ancillary file), no `PhysicalMove` is produced: the audit reports the file and a human moves it.
 *
 * **Two relocation modes (ticket 14).** The move's *shape* depends on whether `from` and `to` sit in
 * the same git repository:
 *
 *  - **Same repo** ({@see crossRepo} false) — a single-repo relocation, historically a `git mv`. `from`
 *    and `to` live under one `.git`; this is the original ticket-09 behaviour, byte-for-byte unchanged.
 *  - **Cross repo** ({@see crossRepo} true) — the moved file's *new* FQN resolves into a **different**
 *    package root than its source (the app → package promotion — the co-dev estate's most common move).
 *    A `git mv` cannot cross that boundary, so the relocation becomes a two-repo operation: write the
 *    (namespace-rewritten) file at its PSR-4 path in the DESTINATION repo, then delete it from the
 *    SOURCE repo — each staged in its own repo. {@see sourceRepo}/{@see destinationRepo} record the two
 *    repos so the applier's rollback can restore *each* to its exact pre-move state (manifest-scoped,
 *    per ticket 03), and so the deterministic gate runs `php -l`/`dump-autoload` in the destination
 *    repo where the class now lives.
 *
 * The `crossRepo` flag is *derived* by {@see RewritePlanner} via {@see GitRoot}, never authored — the
 * caller declares an FQN pair and named roots; the tool decides the mechanism.
 */
class PhysicalMove
{
    public function __construct(
        public string $from,
        public string $to,
        public string $fromRelative,
        public string $toRelative,
        public string $oldFqn,
        public string $newFqn,
        public bool $crossRepo = false,
        public ?string $sourceRepo = null,
        public ?string $destinationRepo = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->fromRelative,
            'to' => $this->toRelative,
            'old_fqn' => $this->oldFqn,
            'new_fqn' => $this->newFqn,
            'cross_repo' => $this->crossRepo,
            'source_repo' => $this->sourceRepo,
            'destination_repo' => $this->destinationRepo,
        ];
    }
}
