<?php

namespace Vendor\Old;

// A same-namespace sibling with NO import: the bare mention in its docblock resolves through the
// current namespace — the false-positive gate's second arm. (The comment you are reading never
// names the target.)
class Sibling
{
    /** Wraps one Widget per estate order. */
    public function wrap(): void {}
}
