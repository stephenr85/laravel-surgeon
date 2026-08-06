<?php

namespace Vendor\Estate;

use Vendor\Old\Widget;

// Exercises the three mechanical Tier-1 categories: use-import, typehint, inline ::class.
class Consumer
{
    public function __construct(private Widget $widget) {}

    public function name(): string
    {
        return Widget::class;
    }
}
