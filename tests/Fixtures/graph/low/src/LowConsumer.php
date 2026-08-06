<?php

namespace Acme\Low;

use Acme\Shared\Thing;

// Lives in acme/low. Relocating Thing UP into acme/app would need low → app, but app already
// requires low — an upward composer edge. The audit must flag this reference as cycle-risk.
class LowConsumer
{
    public function use(Thing $thing): void {}
}
