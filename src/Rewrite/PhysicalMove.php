<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * The tool-owned physical relocation of the moved symbol's own file (ticket 02, decision 5): a
 * PSR-4-classed symbol's file is `git mv`'d from its old autoload-derived path to the new one.
 *
 * This is derived, never authored — {@see RewritePlanner} resolves `from`/`to` from the FQN pair
 * against the composer PSR-4 map. When the source can't be resolved to a PSR-4 root (a non-PSR-4
 * ancillary file), no `PhysicalMove` is produced: the audit reports the file and a human moves it.
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
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->fromRelative,
            'to' => $this->toRelative,
            'old_fqn' => $this->oldFqn,
            'new_fqn' => $this->newFqn,
        ];
    }
}
