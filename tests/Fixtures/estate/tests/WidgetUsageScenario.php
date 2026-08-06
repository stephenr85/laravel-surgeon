<?php

namespace Vendor\Estate\Tests;

use Vendor\Old\Widget;

// A reference under a tests/ directory is positionally TestFixtureReference (still Tier 1).
// Named to avoid the *Test.php suffix so no runner collects it as a real test.
class WidgetUsageScenario
{
    public function make(): Widget
    {
        return new Widget;
    }
}
