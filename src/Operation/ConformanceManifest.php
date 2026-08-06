<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\DoctorRegistration;

/**
 * Surgeon's discovery port over the host's doctor manifest (ticket 07, decision 5: "reuse doctor's
 * merged manifest as the discovery substrate — one manifest, two consumers: `*:doctor` reports,
 * `surgeon` fixes").
 *
 * **Why a port, not a direct dependency.** Surgeon is a foundation-tier package (its topology forbids
 * `splicewire/*` / `schemastud/*`), so it cannot reference the host's own manifest singleton (e.g.
 * `Splicewire\Beam\Doctor\BeamDoctorManifest`) — that would be a reach-up. Instead surgeon declares this
 * port over doctor's foundation {@see DoctorRegistration} value (doctor IS a surgeon dependency), and the
 * HOST binds an adapter mapping its manifest's `registrations()` onto it. This is exactly decision 6's
 * "the running host context scopes what's discovered": surgeon sees only what the host registers, and a
 * host with no manifest binds the {@see NullConformanceManifest} default and simply discovers nothing.
 *
 * A host with an existing doctor manifest wires the adapter in one line via {@see CallbackConformanceManifest}:
 *
 *     $this->app->bind(ConformanceManifest::class, fn ($app) => new CallbackConformanceManifest(
 *         fn () => $app->make(BeamDoctorManifest::class)->registrations(),
 *     ));
 */
interface ConformanceManifest
{
    /**
     * Every conformance audit registered in the host's doctor manifest. Surgeon dedupes these by audit
     * class-string before running them (decision 6).
     *
     * @return list<DoctorRegistration>
     */
    public function registrations(): array;
}
