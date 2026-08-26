<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceSweep;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * Surgeon's OWN built-in conformance audits (ticket 15, +{@see LocalMcpWiringAudit} follow-on) — the audits
 * surgeon ships itself, as distinct from the ones a HOST self-registers into its doctor manifest. The
 * Finding→Operation bridge (ticket 13) discovers host audits through the {@see ConformanceManifest} port;
 * but b1 ({@see UpstreamDtoAudit}), b2 ({@see StaleDownstreamDuplicateAudit}), and the MCP-wiring audit are
 * surgeon-native — generic package-graph / dev-tooling hygiene, not any host's concept — so they are NOT in
 * a host's doctor manifest. This provider is the second input to a {@see ConformanceSweep}: the sweep runs
 * the built-in set ALONGSIDE the host-manifest registrations, so `surgeon:audit` reports both in one pass.
 *
 * **Why a separate seam, not a fake host registration.** Faking a `DoctorRegistration` for surgeon's own
 * audits would (a) pollute the host's manifest with a foundation audit the host didn't ask for, and (b)
 * force a container binding for what is a pure, config-scoped value. Instead the built-in set is a plain
 * list the sweep composes — the cleanest wiring: host audits keep flowing through the port unchanged, and
 * surgeon's own audits ride a parallel, always-present channel scoped to the running host's app root.
 *
 * The two DTO audits are foundation-clean: they name no `splicewire/*`/`schemastud/*` type. They scope to
 * the host's `App\Data` DTO namespace by default (configurable), and read only what the host has installed
 * — the downstream twin is DATA discovered at runtime (ticket-13 decision 6), not a compile dependency.
 * {@see LocalMcpWiringAudit} is likewise foundation-clean and additionally optional-dependency-clean: it
 * guards every touch of `laravel/mcp`'s `Registrar` behind `class_exists()`. {@see TrackerStatusDriftAudit}
 * (surgeon-audit-viability ticket 11) is the same posture applied to the local-markdown issue tracker: a
 * PRD's own terminal-status claim about a child issue going stale once the issue's `Status:` line moves on.
 * {@see ChecklistStatusDriftAudit} (ticket 20) is that same posture's intra-file sibling: an issue's own
 * `Status:` line disagreeing with its own acceptance-checkbox state, no PRD involved.
 */
class BuiltInAudits
{
    /**
     * @param  string  $appRoot  the running host's app root (its `vendor/` + scanned namespace dir)
     * @param  string  $namespace  the app DTO namespace to scan (default `App\Data`)
     * @param  string  $dir  the directory that namespace maps to, relative to $appRoot (default `app/Data`)
     * @param  string  $trackerDir  the local-markdown issue-tracker root, relative to $appRoot (default `.scratch`)
     */
    public function __construct(
        public string $appRoot,
        public string $namespace = 'App\\Data',
        public string $dir = 'app/Data',
        public string $trackerDir = '.scratch',
    ) {}

    /**
     * The built-in audits, in report order: b1 (promotion candidates), b2 (stale duplicates), the
     * local-MCP-wiring audit (a registered `Mcp::local()` handle missing from `.mcp.json`), the
     * two tracker status-drift audits (PRD-vs-issue, then issue-vs-own-checkboxes), then the
     * config class-string audit (a class-string in a config file that does not resolve —
     * particle-doctrine-followups #04), then the two composer-supply audits: the dangling-path-repo
     * audit (a composer `type: path` repository whose checkout is gone, which aborts every composer
     * command in the repo at parse time — scanning this repo AND the repos its path repos declare)
     * and the require-without-supply audit (a require whose only declared source is a path repo, so it
     * resolves here and nowhere else), then the third supply check — the unsatisfied-neighbour-require
     * audit (a path-INSTALLED package requiring something this root's installed set does not carry, which
     * neither manifest-reading sibling can see because the require belongs to a neighbour), then the fourth
     * and fifth — the unsatisfied-root-require audit ({@see UnsatisfiedRootRequireAudit}, beam-facade ticket
     * 129) and the transitive-supply audit ({@see TransitiveSupplyAudit}, ticket 129). The root-require one
     * catches the estate's loudest failure and the one no other check sees: a package this root itself
     * requires that is absent from its own installed set, which dies at BOOT with zero assertions and reads
     * exactly like a real regression. The transitive one answers the question
     * {@see RequireWithoutSupplyAudit} is green-by-construction on — a private package reached
     * TRANSITIVELY with no `repositories` entry at this root, which is what composer actually resolves
     * against. It nominates and never certifies, and it stamps the mode (overlay-present/absent) it
     * answered in on every finding, because the same root gives different answers in the two modes and a
     * bare zero is unreadable. Then the dangling-install-path audit
     * ({@see DanglingInstallPathAudit}, beam-facade tickets 74/92): a `vendor/` entry that resolves
     * nowhere — Fail when composer's own installed record names it, Warn when it is an orphaned symlink
     * composer has never heard of. It sits after the supply checks because it is the only one that walks
     * the filesystem rather than reading manifests, and it is the running root's report alone. Finally b3 — the published-migration-drift audit ({@see PublishedMigrationDriftAudit}, beam-facade ticket
     * 116): a copy under the host's `database/migrations/**` whose content no longer matches the package
     * file it was published from. It is last because it is the widest and purely advisory. All emit
     * {@see FixableFinding}s through the ticket-07/13 bridge.
     *
     * @return list<SuggestsOperations>
     */
    public function audits(): array
    {
        return [
            new UpstreamDtoAudit($this->appRoot, $this->namespace, $this->dir),
            new StaleDownstreamDuplicateAudit($this->appRoot, $this->namespace, $this->dir),
            new LocalMcpWiringAudit($this->appRoot),
            new TrackerStatusDriftAudit($this->appRoot, $this->trackerDir),
            new ChecklistStatusDriftAudit($this->appRoot, $this->trackerDir),
            new ConfigClassStringAudit($this->appRoot),
            new DanglingPathRepoAudit($this->appRoot),
            new RequireWithoutSupplyAudit($this->appRoot),
            new UnsatisfiedNeighbourRequireAudit($this->appRoot),
            new UnsatisfiedRootRequireAudit($this->appRoot),
            new TransitiveSupplyAudit($this->appRoot),
            new DanglingInstallPathAudit($this->appRoot),
            new PublishedMigrationDriftAudit($this->appRoot),
        ];
    }
}
