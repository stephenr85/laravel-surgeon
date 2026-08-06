<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceSweep;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Surgeon's OWN built-in conformance audits (ticket 15) — the audits surgeon ships itself, as distinct
 * from the ones a HOST self-registers into its doctor manifest. The Finding→Operation bridge (ticket 13)
 * discovers host audits through the {@see ConformanceManifest} port; but b1
 * ({@see UpstreamDtoAudit}) and b2 ({@see StaleDownstreamDuplicateAudit}) are surgeon-native — generic
 * package-graph hygiene, not any host's concept — so they are NOT in a host's doctor manifest. This
 * provider is the second input to a {@see ConformanceSweep}: the sweep runs the
 * built-in set ALONGSIDE the host-manifest registrations, so `surgeon:audit` reports both in one pass.
 *
 * **Why a separate seam, not a fake host registration.** Faking a `DoctorRegistration` for surgeon's own
 * audits would (a) pollute the host's manifest with a foundation audit the host didn't ask for, and (b)
 * force a container binding for what is a pure, config-scoped value. Instead the built-in set is a plain
 * list the sweep composes — the cleanest wiring: host audits keep flowing through the port unchanged, and
 * surgeon's own audits ride a parallel, always-present channel scoped to the running host's app root.
 *
 * Both audits are foundation-clean: they name no `splicewire/*`/`schemastud/*` type. They scope to the
 * host's `App\Data` DTO namespace by default (configurable), and read only what the host has installed —
 * the downstream twin is DATA discovered at runtime (ticket-13 decision 6), not a compile dependency.
 */
class BuiltInAudits
{
    /**
     * @param  string  $appRoot  the running host's app root (its `vendor/` + scanned namespace dir)
     * @param  string  $namespace  the app DTO namespace to scan (default `App\Data`)
     * @param  string  $dir  the directory that namespace maps to, relative to $appRoot (default `app/Data`)
     */
    public function __construct(
        public string $appRoot,
        public string $namespace = 'App\\Data',
        public string $dir = 'app/Data',
    ) {}

    /**
     * The built-in audits, in report order: b1 (promotion candidates) then b2 (stale duplicates). Both
     * emit {@see FixableFinding}s through the ticket-07/13 bridge.
     *
     * @return list<SuggestsOperations>
     */
    public function audits(): array
    {
        return [
            new UpstreamDtoAudit($this->appRoot, $this->namespace, $this->dir),
            new StaleDownstreamDuplicateAudit($this->appRoot, $this->namespace, $this->dir),
        ];
    }
}
