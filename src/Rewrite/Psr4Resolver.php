<?php

namespace Rushing\Surgeon\Rewrite;

/**
 * Derives a class's on-disk file path from its FQN via a root's composer PSR-4 map — the fact the
 * tool-owned physical move (ticket 02, decision 5) turns on. Reads `autoload` + `autoload-dev`
 * `psr-4` from each root's `composer.json`; the longest matching namespace prefix wins (PSR-4's own
 * rule), so `App\Data\Foo` under `App\ => src/` maps to `src/Data/Foo.php`.
 *
 * A symbol whose namespace matches no prefix is *non-PSR-4 ancillary* — {@see pathFor()} returns
 * null and the caller reports the file for a manual move rather than guessing a location.
 */
class Psr4Resolver
{
    /** @var array<string, list<array{prefix: string, dir: string}>> root => prefix/dir pairs */
    private array $maps = [];

    /**
     * @param  list<string>  $roots  absolute roots whose composer.json PSR-4 maps to consult
     */
    public function __construct(array $roots = [])
    {
        foreach ($roots as $root) {
            $this->maps[rtrim($root, '/')] = $this->loadMap(rtrim($root, '/'));
        }
    }

    /**
     * Absolute file path a PSR-4 class *should* live at under the given root, or null if no prefix
     * of that root's map covers it. Prefers the longest matching namespace prefix.
     */
    public function pathFor(string $fqn, string $root): ?string
    {
        $fqn = ltrim($fqn, '\\');
        $root = rtrim($root, '/');
        $map = $this->maps[$root] ?? ($this->maps[$root] = $this->loadMap($root));

        $best = null;
        foreach ($map as $entry) {
            $prefix = $entry['prefix'];
            if ($prefix === '' || str_starts_with($fqn, $prefix)) {
                if ($best === null || strlen($prefix) > strlen($best['prefix'])) {
                    $best = $entry;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        $relative = substr($fqn, strlen($best['prefix']));
        $subPath = str_replace('\\', '/', $relative).'.php';

        return $root.'/'.trim($best['dir'], '/').'/'.$subPath;
    }

    /**
     * Find WHICH configured root a class belongs to (ticket 14). This is the fact a cross-repo move
     * turns on: the moved file's *new* FQN may resolve into a **different** root than the source (the
     * app → package promotion — the co-dev estate's most common relocation). The single-repo
     * {@see pathFor()} resolves against one caller-named root; this scans every root the resolver was
     * built with and returns the one whose PSR-4 map covers the FQN, so the planner can locate the
     * destination package without the caller pre-knowing which root owns the target namespace.
     *
     * Ambiguity resolution mirrors PSR-4's own longest-prefix rule but *across* roots: the root whose
     * matching prefix is the longest wins (a specific `Vendor\Pkg\` prefix beats a catch-all `App\`),
     * so an app that also autoloads a vendor namespace can't shadow the real destination package.
     *
     * @return array{root: string, path: string}|null the owning root + the file's path under it
     */
    public function resolve(string $fqn): ?array
    {
        $fqn = ltrim($fqn, '\\');

        $bestRoot = null;
        $bestPrefixLength = -1;
        foreach (array_keys($this->maps) as $root) {
            foreach ($this->maps[$root] as $entry) {
                $prefix = $entry['prefix'];
                $covers = $prefix === '' || str_starts_with($fqn, $prefix);
                if ($covers && strlen($prefix) > $bestPrefixLength) {
                    $bestPrefixLength = strlen($prefix);
                    $bestRoot = $root;
                }
            }
        }

        if ($bestRoot === null) {
            return null;
        }

        $path = $this->pathFor($fqn, $bestRoot);

        return $path === null ? null : ['root' => $bestRoot, 'path' => $path];
    }

    /**
     * @return list<array{prefix: string, dir: string}>
     */
    private function loadMap(string $root): array
    {
        $file = $root.'/composer.json';
        if (! is_file($file)) {
            return [];
        }

        $json = json_decode((string) @file_get_contents($file), true);
        if (! is_array($json)) {
            return [];
        }

        $pairs = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $json[$section]['psr-4'] ?? [];
            if (! is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $prefix => $dir) {
                // A prefix may map to several dirs; the first is the canonical write target.
                $dir = is_array($dir) ? ($dir[0] ?? null) : $dir;
                if (is_string($dir)) {
                    $pairs[] = ['prefix' => (string) $prefix, 'dir' => $dir];
                }
            }
        }

        return $pairs;
    }
}
