<?php

namespace Rushing\Surgeon\Audit;

/**
 * A minimal, DB-free read of the composer package-require graph — enough to answer the audit's two
 * cycle-risk questions: *which package owns this file/FQN?* and *does package D already reach package
 * P?* (so relocating a P-referenced symbol into D would close an upward composer edge).
 *
 * This is deliberately NOT graphine's relational driver: the audit is a read-only insight pass, so it
 * wants a cheap in-memory reachability check, not a hydrated store. The heavyweight
 * `laravel-package-topology` guard (`assertDeclaredTopologyHolds()`) stays where ticket 03 put it —
 * inside the *writer's* post-rewrite verification gate — not here. Same graph, two fidelities: a fast
 * indicator for the audit, the full guard for the mutation.
 */
class PackageGraph
{
    /**
     * @param  array<string, list<string>>  $requires  package name => list of required package names
     * @param  array<string, string>  $psr4  namespace prefix (trailing `\`) => owning package name
     * @param  array<string, string>  $rootPackage  absolute root path => package name declared there
     */
    protected function __construct(
        private array $requires,
        private array $psr4,
        private array $rootPackage,
    ) {}

    /**
     * Build from the configured scan roots (each root is one package checkout), optionally completing
     * the transitive graph from a vendor directory so reachability is not truncated at the roots.
     *
     * @param  list<string>  $roots  absolute root paths, each expected to hold a composer.json
     * @param  list<string>  $include  vendor globs to complete the graph from (scope is load-bearing)
     */
    public static function fromRoots(array $roots, ?string $vendorPath = null, array $include = ['rushing/*', 'splicewire/*', 'schemastud/*']): self
    {
        $requires = [];
        $psr4 = [];
        $rootPackage = [];

        $ingest = function (string $manifestFile) use (&$requires, &$psr4): ?string {
            $decoded = json_decode((string) @file_get_contents($manifestFile), true);
            if (! is_array($decoded) || ! is_string($decoded['name'] ?? null)) {
                return null;
            }
            $name = $decoded['name'];
            $requires[$name] ??= [];
            foreach (['require', 'require-dev'] as $key) {
                foreach (array_keys((array) ($decoded[$key] ?? [])) as $dep) {
                    if (is_string($dep) && str_contains($dep, '/')) {
                        $requires[$name][] = $dep;
                    }
                }
            }
            $requires[$name] = array_values(array_unique($requires[$name]));

            foreach ((array) ($decoded['autoload']['psr-4'] ?? []) as $prefix => $dir) {
                if (is_string($prefix) && $prefix !== '') {
                    $psr4[rtrim($prefix, '\\').'\\'] = $name;
                }
            }

            return $name;
        };

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            $name = $ingest($root.'/composer.json');
            if ($name !== null) {
                $rootPackage[$root] = $name;
            }
        }

        if ($vendorPath !== null) {
            foreach ($include as $glob) {
                foreach (glob("{$vendorPath}/{$glob}/composer.json") ?: [] as $file) {
                    $ingest($file);
                }
            }
        }

        return new self($requires, $psr4, $rootPackage);
    }

    /** The package that owns a scan root (its declared composer name), if known. */
    public function packageForRoot(string $root): ?string
    {
        return $this->rootPackage[rtrim($root, '/')] ?? null;
    }

    /** The package whose PSR-4 prefix most specifically owns a fully-qualified name. */
    public function packageForFqn(string $fqn): ?string
    {
        $fqn = Target::normalize($fqn);
        $best = null;
        $bestLen = -1;

        foreach ($this->psr4 as $prefix => $package) {
            if (str_starts_with($fqn, $prefix) && strlen($prefix) > $bestLen) {
                $best = $package;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    /** Does $from already reach $to through `require` edges? (BFS; self reaches self.) */
    public function reaches(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $seen = [];
        $queue = [$from];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($this->requires[$node] ?? [] as $dep) {
                if ($dep === $to) {
                    return true;
                }
                $queue[] = $dep;
            }
        }

        return false;
    }
}
