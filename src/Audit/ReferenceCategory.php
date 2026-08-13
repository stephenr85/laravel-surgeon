<?php

namespace Rushing\Surgeon\Audit;

/**
 * The kinds of touch-point the audit distinguishes — the "recurring-operation table" of the
 * grounding scan, promoted to a closed vocabulary. Category is the *what-kind-of-reference*; the
 * {@see Tier} it maps to (via {@see self::tier()}) is *who-acts-on-it*.
 *
 * Detection is partly syntactic (the node shape: a `use`, a `::class`, a typehint) and partly
 * positional (the file's role: a reference living in a migration / test / route / config file is
 * categorised by *where it lives*, because that changes who repoints it and how risky it is). The
 * collector decides the syntactic kind; the engine refines it by file role.
 *
 * This is deliberately relocation-shaped but not relocation-only: a judgment-only insight audit
 * (find every touch-point of `App\Http\Middleware`, decide if each is still needed) reads the same
 * category+tier split to triage its hunt, it just never runs an operation off it.
 */
enum ReferenceCategory: string
{
    // --- Tier 1: mechanical class-load coupling ------------------------------------------------
    case UseImport = 'use-import';
    case ClassConstFetch = 'class-const-fetch';   // Foo::class
    case TypeHint = 'type-hint';                  // param / return / property type
    case NamespaceDeclaration = 'namespace-decl'; // the moved file's own `namespace X;`
    case NameReference = 'name-reference';        // new Foo(), Foo::method(), instanceof Foo, extends/implements
    case TestFixtureReference = 'test-fixture-reference';
    case DocblockTagReference = 'docblock-tag-reference'; // {@see Foo}, @var/@param/@return/@throws Foo

    // --- Tier 2: deterministic to apply, judgment to decide ------------------------------------
    case RuntimeApp = 'runtime-app';              // app(\FQN::class), resolve(FQN::class), ->make(FQN::class)
    case MorphMapEntry = 'morph-map-entry';       // ::class value inside a morphMap([...]) array
    case MigrationReference = 'migration-reference';
    case DocblockProseReference = 'docblock-prose-reference'; // a bare resolvable short name in comment free text

    // --- Tier 3: advisory handoff to a cleanup agent -------------------------------------------
    case ProviderRegistration = 'provider-registration'; // Gate::policy(), Event::listen(), $this->commands([...])
    case RouteConfigReference = 'route-config-reference';

    /**
     * The tier that owns this category — the deterministic/agentic seam, encoded once.
     */
    public function tier(): Tier
    {
        return match ($this) {
            self::UseImport,
            self::ClassConstFetch,
            self::TypeHint,
            self::NamespaceDeclaration,
            self::NameReference,
            self::TestFixtureReference,
            // Tag grammar makes the span unambiguous — a {@see}/@var/@param/@return/@throws type
            // slot is as mechanical as a use-import, so it splices automatically.
            self::DocblockTagReference => Tier::One,

            self::RuntimeApp,
            self::MorphMapEntry,
            self::MigrationReference,
            // Deterministic to apply, judgment to decide — exactly a prose mention. Tier 2 also
            // buys the required behavior for free: the planner skips everything below Tier 1, so
            // prose is REPORTED for hand-finish and never auto-spliced.
            self::DocblockProseReference => Tier::Two,

            self::ProviderRegistration,
            self::RouteConfigReference => Tier::Three,
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::UseImport => '`use` import (class-load coupling)',
            self::ClassConstFetch => 'inline `::class` constant',
            self::TypeHint => 'parameter / return / property typehint',
            self::NamespaceDeclaration => "the file's own `namespace` declaration",
            self::NameReference => 'name reference (new / static-call / instanceof / extends / implements)',
            self::TestFixtureReference => 'reference inside a test / factory / seeder',
            self::DocblockTagReference => 'docblock tag reference (`{@see}` / `@var` / `@param` / `@return` / `@throws`)',
            self::DocblockProseReference => 'docblock / comment prose mention (bare short name in free text)',
            self::RuntimeApp => 'runtime container resolve — `app(\\FQN::class)` (keep runtime to dodge a cycle?)',
            self::MorphMapEntry => 'morph / policy-map array entry (re-key to the canonical FQN)',
            self::MigrationReference => 'reference inside a migration (`use` / `foreignIdFor()`)',
            self::ProviderRegistration => 'service-provider registration (`Gate::policy` / `Event::listen` / command)',
            self::RouteConfigReference => 'route or config reference (route name / config key)',
        };
    }
}
