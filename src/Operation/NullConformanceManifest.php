<?php

namespace Rushing\Surgeon\Operation;

/**
 * The default {@see ConformanceManifest} surgeon binds when the host wires no adapter: an empty manifest.
 * A host with no doctor manifest (or one that hasn't opted surgeon into it) discovers nothing — the
 * conformance sweep runs clean and reports zero audits, rather than surgeon reaching up to guess.
 */
class NullConformanceManifest implements ConformanceManifest
{
    public function registrations(): array
    {
        return [];
    }
}
