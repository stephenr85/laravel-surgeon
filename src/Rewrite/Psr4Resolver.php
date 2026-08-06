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
