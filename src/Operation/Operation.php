<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Rewrite\RewritePlan;

/**
 * The thin, first-class abstraction every surgeon capability is (ticket 07, decision 1). A surgeon
 * `Operation` is the *fix half* doctor never had: doctor diagnoses (a {@see Finding}),
 * an Operation performs the precise, deterministic change that resolves it.
 *
 * Deliberately THIN, not a framework (decision 1). The shared contract is only the *identity + intent*
 * every operation must expose so an agent can enumerate the `surgeon:*` family and nominate work back:
 *
 *  - {@see kind()} — the stable machine token (`relocation`, `canonicalize`, `lint`, …). It is the same
 *    token an {@see OperationSuggestion} carries, so a diagnosis names the operation that fixes it.
 *  - {@see describe()} — a one-line human summary of what this concrete operation would do.
 *  - {@see isWriter()} — whether applying it mutates source. A writer runs under ticket-03's whole
 *    safety contract (named `--root` blast radius, clean-tree gate, deterministic post-write gate with
 *    manifest-scoped rollback, no auto-commit); a non-writer (a pure conformance read) pays none of it.
 *
 * `plan()`/`apply()` are each operation's OWN typed methods, NOT hoisted onto this interface: relocation
 * plans an {@see AuditReport} into a {@see RewritePlan},
 * canonicalize/lint will take and return their own shapes. Forcing a common `plan(mixed): mixed` here
 * would be the "framework" decision 1 rejected. When a *second* writer operation lands (ticket 11/12), a
 * shared plan/apply shape may be extracted — the same tripwire discipline as the `surgeon-contracts`
 * split (decision 3). Until then the writer contract is behavioural, honoured by each op, not a signature.
 */
interface Operation
{
    /** Stable machine token for this operation family — matches the token an {@see OperationSuggestion} nominates. */
    public function kind(): string;

    /** One-line human summary of what this concrete operation would do. */
    public function describe(): string;

    /** Whether applying this operation mutates source (and so runs under ticket-03's writer contract). */
    public function isWriter(): bool;
}
