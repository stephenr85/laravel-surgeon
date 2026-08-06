<?php

namespace Rushing\Surgeon\Audit;

use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Rewrite\LiteralRewriteOperation;

/**
 * The shared diagnosis core behind "convert manual registration to attribute discovery" audits
 * (ConduitProviderRegistry, ParticleResource, …): diffs a registry's manually-registered
 * class-strings against the classes an {@see AttributedClassScanner} finds carrying the target
 * attribute under given paths.
 *
 * Deliberately the ONLY reusable piece. Extracting a manual registration site's class-strings is
 * per-registry (call shapes differ — constructor args, closures, whatever), so that stays the
 * concrete audit's job; this class starts once both sides are already plain `class-string[]`.
 *
 * Pairs with {@see LiteralRewriteOperation} (already generic, host-blind)
 * for the fix half — a concrete audit turns each {@see RegistrationDrift::$needsAttribute} entry
 * into a `literal-rewrite` {@see OperationSuggestion} itself, since only
 * it knows the source text to match and the attribute text to insert.
 */
class RegistrationDriftDetector
{
    public function __construct(
        protected AttributedClassScanner $scanner = new AttributedClassScanner,
    ) {}

    /**
     * @param  list<string>  $scanPaths  filesystem paths to scan for attribute-carrying classes
     * @param  class-string  $attributeClass  the target attribute
     * @param  list<class-string>  $manuallyRegistered  classes found at the registry's manual registration site
     */
    public function detect(array $scanPaths, string $attributeClass, array $manuallyRegistered): RegistrationDrift
    {
        $attributed = $this->scanner->scan($scanPaths, $attributeClass);

        return new RegistrationDrift(
            needsAttribute: array_values(array_diff($manuallyRegistered, $attributed)),
            redundantManualEntry: array_values(array_intersect($manuallyRegistered, $attributed)),
        );
    }
}
