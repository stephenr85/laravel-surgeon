<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Surgeon\Console\CanonicalizeCommand;
use Rushing\Surgeon\Operation\Operation;

/**
 * Canonicalization as a first-class {@see Operation} (ticket 08 decision 3, built in ticket 11) — the
 * inverse of the overlay verbs: where {@see OverlayOperation} goes co-dev (path-resolve), this goes
 * shippable (git-resolve the lock). The third operation family, and — with {@see OverlayOperation} — one
 * of the two non-AST proofs of the audit spine: {@see CanonicalizationAudit} emits Findings through the
 * ticket-07/13 Finding→Operation bridge.
 *
 * Pure identity, like {@see OverlayOperation}: the plan/perform work lives on {@see Canonicalizer} +
 * {@see CanonicalizeCommand} (the thin {@see Operation} interface hoists no
 * plan/apply signature — decision 1). {@see CanonicalizationAudit} nominates this op by {@see kind()}
 * when a required path repo is ahead of upstream (a push, run under the writer gate, resolves it).
 */
class CanonicalizationOperation implements Operation
{
    public function kind(): string
    {
        return 'canonicalize';
    }

    public function describe(): string
    {
        return 'Take the project out of its co-dev overlay and regenerate a shippable, git-resolved composer.lock.';
    }

    public function isWriter(): bool
    {
        return true;
    }
}
