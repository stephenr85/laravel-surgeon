<?php

namespace Rushing\Surgeon\Tests\Fixtures\Operations;

use Error;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Ticket 56's other channel: an audit on the paired {@see SuggestsOperations} side that throws, and an
 * `Error` rather than an `Exception` — the sweep catches `Throwable`, because a fatal from a stale audit
 * (a call to a method a repointed collaborator no longer has) is the same event to a report as a query
 * against a missing column. This is also the shape surgeon's own built-ins ride on.
 */
class ThrowingSuggestingAudit implements SuggestsOperations
{
    /** @return list<\Rushing\Surgeon\Operation\FixableFinding> */
    public function suggestOperations(): array
    {
        throw new Error('Call to undefined method Collaborator::gone()');
    }
}
