<?php

namespace Rushing\Surgeon\Tests\Fixtures\Operations;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * One audit class registered once per subject, its subject passed as a constructor argument through
 * `DoctorRegistration::$with` (make-surface 04). `splicewire/laravel-beam`'s stub-migrations check is the
 * live case: one class, one registration per package, each naming its own package and provider. The
 * subject is in the check-string so a test can see which arguments each instance was built with.
 */
class SubjectAudit implements DoctorAudit
{
    public function __construct(private string $subject) {}

    /** @return list<Finding> */
    public function run(): array
    {
        return [Finding::pass("subject ({$this->subject})", 'built with its registration\'s arguments')];
    }
}
