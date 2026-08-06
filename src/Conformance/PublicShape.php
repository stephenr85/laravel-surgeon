<?php

namespace Rushing\Surgeon\Conformance;

/**
 * The PUBLIC shape of a class — its public property names and its public method signatures — reduced to
 * a comparable, order-independent form. This is the deterministic core of b2's drift decision
 * ({@see StaleDownstreamDuplicateAudit}): an app-local class and a downstream twin are treated as *the
 * same class* only when their public shapes are identical; any difference is DRIFT, and drift is never a
 * blind retire (advisory-only — the deterministic/agentic seam).
 *
 * **Public shape, NOT behaviour.** We compare the surface a consumer binds to — the promoted constructor
 * params + public properties a `spatie/laravel-data` DTO exposes, and public method signatures — never
 * method bodies. Two classes with identical public shape are drop-in for a repoint; a body difference is
 * not the audit's concern (and reconciling it is judgment). This keeps the compare cheap (a reflection- or
 * AST-level read) and the fixable/advisory split honest.
 *
 * Promoted constructor params (spatie-data's idiom, `public string $slug` in `__construct`) are folded
 * into the property set by {@see DtoClassReader}, so the app copy's 4 promoted params and the downstream
 * copy's 5 (it adds `$composable`) surface as a property-set difference — exactly the real drift.
 */
class PublicShape
{
    /**
     * @param  list<string>  $properties  public property names (incl. promoted ctor params), sorted
     * @param  list<string>  $methods  public method signatures as `name(paramCount)`, sorted
     */
    public function __construct(
        public array $properties,
        public array $methods,
    ) {
        sort($this->properties);
        sort($this->methods);
    }

    /** Whether this shape is byte-for-byte the same surface as another (order-independent). */
    public function equals(PublicShape $other): bool
    {
        return $this->properties === $other->properties
            && $this->methods === $other->methods;
    }

    /**
     * A human-readable summary of how this shape differs from another — used to NAME the drift in a b2
     * advisory finding ("downstream adds `$composable`; app has `#[TypeScript]` downstream lacks" is
     * assembled by the audit, which owns the attribute half; this covers the property/method half).
     * Empty string when the shapes are identical.
     *
     * @param  string  $selfLabel  what THIS shape is (e.g. "app")
     * @param  string  $otherLabel  what the OTHER shape is (e.g. "downstream")
     */
    public function describeDiff(PublicShape $other, string $selfLabel, string $otherLabel): string
    {
        $parts = [];

        $otherAddsProps = array_values(array_diff($other->properties, $this->properties));
        $otherDropsProps = array_values(array_diff($this->properties, $other->properties));
        if ($otherAddsProps !== []) {
            $parts[] = "{$otherLabel} adds ".$this->renderProps($otherAddsProps);
        }
        if ($otherDropsProps !== []) {
            $parts[] = "{$otherLabel} drops ".$this->renderProps($otherDropsProps);
        }

        $otherAddsMethods = array_values(array_diff($other->methods, $this->methods));
        $otherDropsMethods = array_values(array_diff($this->methods, $other->methods));
        if ($otherAddsMethods !== []) {
            $parts[] = "{$otherLabel} adds method(s) ".implode(', ', $otherAddsMethods);
        }
        if ($otherDropsMethods !== []) {
            $parts[] = "{$otherLabel} drops method(s) ".implode(', ', $otherDropsMethods);
        }

        return implode('; ', $parts);
    }

    /** @param  list<string>  $props */
    private function renderProps(array $props): string
    {
        return implode(', ', array_map(fn (string $p) => '$'.$p, $props));
    }
}
