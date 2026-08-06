<?php

namespace Acme\App;

use Acme\Shared\Widget;

// Lives in acme/app. Relocating Widget DOWN into acme/low is safe: app already requires low, so no
// new upward edge. The audit must NOT flag this reference.
class AppConsumer
{
    public function use(Widget $widget): void {}
}
