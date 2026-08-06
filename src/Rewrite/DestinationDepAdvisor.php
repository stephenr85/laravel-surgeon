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
        $required = $this->requiredVendorRoots($composer);
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
     * The top-level namespace segment of every package the destination `require`s / `require-dev`s.
     * Maps a `vendor/package` to its likely namespace root via composer's own convention (Studly of the
     * package tail), plus a couple of well-known irregulars. Shallow — see the class docblock.
     *
     * @param  array<string, mixed>  $composer
     * @return list<string>
     */
    private function requiredVendorRoots(array $composer): array
    {
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
                $roots[] = $this->namespaceRootFor($name);
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
            $psr4 = $composer[$section]['psr-4'] ?? [];
            if (! is_array($psr4)) {
                continue;
            }
            foreach (array_keys($psr4) as $prefix) {
                $roots[] = $this->topSegment(rtrim((string) $prefix, '\\'));
            }
        }

        return array_values(array_unique(array_filter($roots)));
    }

    /**
     * A best-effort namespace ROOT (the first FQN segment) for a `vendor/package` name. By overwhelming
     * PSR-4 convention a package's namespace root is the Studly-cased *vendor* (`laravel/mcp` →
     * `Laravel\Mcp\…` → `Laravel`; `rushing/php-graphine` → `Rushing\…`; `nikic/php-parser` is the
     * classic irregular → `PhpParser`). We can't know a package's real root without reading its own
     * composer.json (out of scope for a shallow check), so this Studly-cases the vendor with a couple of
     * known irregulars. Right for the common estate case — a `Vendor\…` import against a `vendor/*`
     * require — which is exactly the tail that bit the manual promotion.
     */
    private function namespaceRootFor(string $package): string
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
