<?php

namespace Rushing\Surgeon\Tests\Fixtures\Operations;

use RuntimeException;
use Rushing\Doctor\DoctorAudit;

/**
 * The specimen of ticket 56: an audit whose precondition is unmet at this root, so it throws instead of
 * reporting. The live instance was `BeamUxArtifactAudit` querying a column the host's database did not
 * have — a `QueryException` out of `run()` that killed the whole `surgeon:audit` command.
 *
 * A stand-in rather than the real thing on purpose: the sweep must survive ANY throw from an audit, and
 * the specimen that raised the ticket has since been repaired at its host, so the crash is reproducible
 * only here.
 */
class ThrowingAudit implements DoctorAudit
{
    /** @return list<\Rushing\Doctor\Finding> */
    public function run(): array
    {
        throw new RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'deleted_at'\nin the second line nobody needs");
    }
}
