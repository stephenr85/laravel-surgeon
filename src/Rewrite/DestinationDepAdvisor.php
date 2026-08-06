<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * The composer-dependency TAIL of a cross-repo move, emitted as a **Tier-3 advisory** (ticket 14) —
 * the exact tail that bit the manual `App\Mcp\SurgeonMcpServer` promotion, where `laravel/mcp` and
 * `rushing/laravel-mcp-registry` had to be added to surgeon's `composer.json` *by hand* or the moved
 * class would fatal at autoload time.
 *
 * When a file is promoted from the app into a package, it carries its `use` imports with it — but the
 * package it lands in may not `require` the vendors those imports come from. The app had them
 * transitively; the package, shipped on its own, would not. This advisor detects that gap and **flags
 * it**, deterministically, then hands it to the agent. It deliberately does NOT edit `composer.json`:
 * choosing the right version constraint (and whether a symbol belongs in `require` vs `require-dev`, or
 * should be a `suggest`, or points at a not-yet-published package) is genuine judgment — the
 * deterministic/agentic seam this whole toolset defends. Detect → warn → hand off.
 *
 * **Shallow by design.** It is a "is this vendor/namespace plausibly satisfied by the destination
 * package's declared deps?" check, not a transitive-closure resolver. It reads the moved file's own
 * top-level imports, drops the ones the file itself now provides (the destination package's own
 * namespaces, PHP built-ins, and same-namespace siblings), and reports the remainder that the
 * destination `composer.json` neither requires nor already covers by a PSR-4 prefix. False negatives
 * (a symbol reached only transitively) are acceptable — the point is to catch the common, load-bearing
 * "you moved a class that imports a vendor the package doesn't list" mistake before it fatals.
 *
 * **How a required package's namespace roots are resolved (tightened, ticket 14 follow-on).** A
 * `vendor/package` name does NOT reliably imply its namespace root — `guzzlehttp/guzzle` autoloads
 * `GuzzleHttp\` (not `Guzzlehttp`), `nikic/php-parser` autoloads `PhpParser\`. So this reads each
 * required package's OWN declared PSR-4/PSR-0 roots, authoritatively, from the destination's installed
 * metadata — first `vendor/composer/installed.json` (composer's own resolved manifest), then the
 * package's `vendor/<name>/composer.json` — and only falls back to the Studly-of-vendor guess when the
 * package isn't installed at all (deps not yet pulled). Installed → precise; not installed → best-effort.
 */
class DestinationDepAdvisor
{
    /**
     * A short list of vendor namespace roots that ship WITH PHP / Laravel's runtime and never need a
     * dedicated `require` line in a Laravel package. Keeps the advisory low-noise (it never nags about
     * `Illuminate\` in a package that already requires `illuminate/support`, nor about global built-ins).
     *
     * @var list<string>
     */
    private array $ambient = ['Illuminate', 'Symfony', 'Psr', 'Carbon', 'Ramsey'];

    /**
     * Inspect the moved file at its NEW home against the destination package's `composer.json`. Returns
     * one advisory line per imported vendor namespace the destination neither requires nor autoloads —
     * empty when the destination already covers everything the moved file leans on.
     *
     * @param  string  $movedFilePath  absolute path to the file at its destination
     * @param  string  $destinationRoot  the destination package root (holding its composer.json)
     * @return list<string> human-readable advisory lines (empty = no dangling dep)
     */
    public function advise(string $movedFilePath, string $destinationRoot): array
    {
        $source = @file_get_contents($movedFilePath);
        if ($source === false) {
            return [];
        }

        $composer = $this->readComposer($destinationRoot);
        $required = $this->requiredVendorRoots($composer, $destinationRoot);
        $ownPrefixes = $this->autoloadPrefixRoots($composer);
        $packageName = is_string($composer['name'] ?? null) ? $composer['name'] : basename($destinationRoot);

        $advisories = [];
        $seen = [];
        foreach ($this->importedFqns($source) as $fqn) {
            $root = $this->topSegment($fqn);
            if ($root === '' || isset($seen[$root])) {
                continue;
            }
            // Skip ambient runtime namespaces, the destination package's own namespaces, and imports
            // whose vendor root is already required. What's left is a genuine gap.
            if (in_array($root, $this->ambient, true)
                || in_array($root, $ownPrefixes, true)
                || in_array($root, $required, true)) {
                continue;
            }
            $seen[$root] = true;
            $advisories[] = sprintf(
                'moved file references %s\\… which %s does not require — add the owning package to its composer.json (or the class will fatal at autoload).',
                $root,
                $packageName,
            );
        }

        return $advisories;
    }

    /**
     * The fully-qualified names imported by `use` statements in the moved file. A shallow line-oriented
     * read of the file's imports — enough for the vendor-root check, and dependency-free (no AST needed
     * for a top-segment classification). Group-uses and aliases are handled by taking the leading FQN.
     *
     * @return list<string>
     */
    private function importedFqns(string $source): array
    {
        $fqns = [];
        foreach (preg_split('/\r?\n/', $source) ?: [] as $line) {
            $line = trim($line);
            if (! preg_match('/^use\s+(?:function\s+|const\s+)?([^\s;{]+)/', $line, $m)) {
                continue;
            }
            $fqns[] = ltrim($m[1], '\\');
        }

        return $fqns;
    }

    private function topSegment(string $fqn): string
    {
        $pos = strpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, 0, $pos);
    }

    /** @return array<string, mixed> */
    private function readComposer(string $root): array
    {
        $file = rtrim($root, '/').'/composer.json';
        if (! is_file($file)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    /**
     * The top-level namespace segments of every package the destination `require`s / `require-dev`s —
     * read from each package's OWN declared PSR-4/PSR-0 roots (authoritative), falling back to the
     * Studly-of-vendor guess only for a package that isn't installed. See the class docblock.
     *
     * @param  array<string, mixed>  $composer
     * @return list<string>
     */
    private function requiredVendorRoots(array $composer, string $destinationRoot): array
    {
        $installed = $this->installedNamespaceMap($destinationRoot);

        $roots = [];
        foreach (['require', 'require-dev'] as $section) {
            $deps = $composer[$section] ?? [];
            if (! is_array($deps)) {
                continue;
            }
            foreach (array_keys($deps) as $name) {
                $name = (string) $name;
                if (! str_contains($name, '/')) {
                    continue; // php / ext-* — not a namespace-bearing dep.
                }
                foreach ($this->namespaceRootsForPackage($name, $installed, $destinationRoot) as $root) {
                    $roots[] = $root;
                }
            }
        }

        return array_values(array_unique(array_filter($roots)));
    }

    /**
     * The PSR-4 top-level namespace segments the destination package itself declares — a moved file that
     * imports one of its own namespaces (or a sibling being moved alongside) needs no new dep.
     *
     * @param  array<string, mixed>  $composer
     * @return list<string>
     */
    private function autoloadPrefixRoots(array $composer): array
    {
        $roots = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];
            if (is_array($autoload)) {
                foreach ($this->rootsFromAutoload($autoload) as $root) {
                    $roots[] = $root;
                }
            }
        }

        return array_values(array_unique(array_filter($roots)));
    }

    /**
     * The real namespace roots a `vendor/package` declares, resolved in order of authority:
     *   1. composer's installed manifest (`vendor/composer/installed.json`) — the resolved truth;
     *   2. the package's own `vendor/<name>/composer.json` autoload block;
     *   3. the Studly-of-vendor guess (with the `nikic` irregular) — only when the package isn't
     *      installed, so the check degrades to best-effort rather than vanishing.
     *
     * @param  array<string, list<string>>  $installed  name => namespace roots, from installed.json
     * @return list<string>
     */
    private function namespaceRootsForPackage(string $package, array $installed, string $destinationRoot): array
    {
        if (isset($installed[$package]) && $installed[$package] !== []) {
            return $installed[$package];
        }

        $own = $this->readComposer(rtrim($destinationRoot, '/').'/vendor/'.$package);
        if ($own !== []) {
            $roots = $this->rootsFromAutoload(is_array($own['autoload'] ?? null) ? $own['autoload'] : []);
            if ($roots !== []) {
                return $roots;
            }
        }

        return [$this->studlyFallbackRoot($package)];
    }

    /**
     * Parse `vendor/composer/installed.json` into a package-name => namespace-roots map — composer's own
     * record of what is actually installed and how each package autoloads. Handles both the composer v2
     * shape (`{"packages": [...]}`) and the legacy bare-list shape. Empty when the manifest is absent
     * (deps not installed) — callers then fall back per package.
     *
     * @return array<string, list<string>>
     */
    private function installedNamespaceMap(string $destinationRoot): array
    {
        $file = rtrim($destinationRoot, '/').'/vendor/composer/installed.json';
        if (! is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (! is_array($decoded)) {
            return [];
        }
        $packages = $decoded['packages'] ?? $decoded; // v2 wraps in "packages"; v1 is a bare list.
        if (! is_array($packages)) {
            return [];
        }

        $map = [];
        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }
            $autoload = is_array($package['autoload'] ?? null) ? $package['autoload'] : [];
            $roots = $this->rootsFromAutoload($autoload);
            if ($roots !== []) {
                $map[$package['name']] = $roots;
            }
        }

        return $map;
    }

    /**
     * The top-level namespace segments declared by an `autoload` block's PSR-4 and PSR-0 prefixes.
     *
     * @param  array<string, mixed>  $autoload
     * @return list<string>
     */
    private function rootsFromAutoload(array $autoload): array
    {
        $roots = [];
        foreach (['psr-4', 'psr-0'] as $scheme) {
            $prefixes = $autoload[$scheme] ?? [];
            if (! is_array($prefixes)) {
                continue;
            }
            foreach (array_keys($prefixes) as $prefix) {
                $root = $this->topSegment(rtrim((string) $prefix, '\\'));
                if ($root !== '') {
                    $roots[] = $root;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * The last-resort namespace-root guess for a package that isn't installed: the Studly-cased vendor
     * (`laravel/mcp` → `Laravel`; `rushing/php-graphine` → `Rushing`), with the classic `nikic` irregular.
     * Only reached when neither installed.json nor a `vendor/<name>/composer.json` is present — see the
     * class docblock. Right often enough to keep the check useful pre-install; the installed reads above
     * are what make it precise.
     */
    private function studlyFallbackRoot(string $package): string
    {
        [$vendor] = array_pad(explode('/', $package, 2), 2, '');

        return match ($vendor) {
            'nikic' => 'PhpParser',
            default => $this->studly($vendor),
        };
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
