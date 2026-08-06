<?php

namespace Rushing\Surgeon\Tests\Fixtures\Operations;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * A plain {@see DoctorAudit} with no fix half — the ordinary case a host's doctor manifest is full of.
 * The sweep wraps each finding as a {@see \Rushing\Surgeon\Operation\FixableFinding} with a null
 * suggestion (no operation nominated). Proves the diagnosis-only channel and the non-fixable path.
 */
class PlainAudit implements DoctorAudit
{
    /** @return list<Finding> */
    public function run(): array
    {
        return [
            Finding::pass('boot', 'Package booted cleanly'),
            Finding::warn('config', 'Config not published — using defaults'),
        ];
    }
}
