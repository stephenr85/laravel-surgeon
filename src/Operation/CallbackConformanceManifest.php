<?php

namespace Rushing\Surgeon\Operation;

use Rushing\Doctor\DoctorRegistration;

/**
 * The one-line adapter a host binds to expose its existing doctor manifest to surgeon (see
 * {@see ConformanceManifest}). It wraps a closure returning the host manifest's `registrations()`, so a
 * beam/satellite/tower host reuses its own singleton without surgeon ever naming that (reach-up) type.
 */
class CallbackConformanceManifest implements ConformanceManifest
{
    /** @param callable(): list<DoctorRegistration> $source */
    public function __construct(
        private $source,
    ) {}

    public function registrations(): array
    {
        return array_values(($this->source)());
    }
}
