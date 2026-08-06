<?php

namespace Rushing\Surgeon\Tests\Fixtures\Registration;

use Attribute;

/** Synthetic stand-in for a real registry attribute (e.g. #[ConduitProvider], #[ParticleResource]). */
#[Attribute(Attribute::TARGET_CLASS)]
class RegistrationMarker {}
