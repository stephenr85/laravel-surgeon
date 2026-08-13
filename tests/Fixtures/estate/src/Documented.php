<?php

namespace Vendor\Estate;

use Vendor\Old\Widget;

// Reproduces the five comment-only forms the original rename left behind (ticket 03): three
// see-tags, one orphan var-tag above a plain assignment, and one prose sentence naming the target
// bare. The explanatory comments in this file deliberately never name the target themselves.
class Documented
{
    /**
     * Builds each Widget the estate hands out — the prose sentence a rename must report, not splice.
     *
     * {@see Widget} for the shape, or {@see \Vendor\Old\Widget} fully qualified.
     */
    public function build(): object
    {
        /** @var Widget $made */
        $made = $this->make();

        return $made;
    }

    /** {@see Widget::label} is the only surface consumers read. */
    public function make(): object
    {
        return new \stdClass;
    }
}
