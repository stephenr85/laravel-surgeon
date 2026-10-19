<?php

namespace Rushing\Surgeon\Tests;

use Rushing\PackageTopology\Testing\DeclaredTopologyConformance;

/**
 * B8-2 (owner-ruled cluster-first, 2026-10-18): the manifests state the architecture through
 * `extra.package-topology`, and this runs the real gate over the real INSTALLED tree — the rules
 * are executed, not asserted in prose. The surgeon already requires the topology engine at runtime
 * (the cycle-guard is its post-rewrite verification), so adoption here is the gate alone. Scope
 * honestly (the screening-kernel exemplar's caveat holds here too): `DeclaredContractSource`
 * assembles from installed manifests under vendor/, so this package's OWN block (`mustRequire` its
 * five engine deps; `mustNotRequire` the product tiers) is evaluated wherever IT is installed —
 * what runs here is its dependencies' declarations plus the always-appended `mustBeAcyclic`.
 */
class DeclaredTopologyTest extends DeclaredTopologyConformance
{
    protected function vendorPath(): string
    {
        return dirname(__DIR__).'/vendor';
    }
}
