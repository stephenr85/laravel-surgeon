<?php

namespace Rushing\Surgeon\Overlay;

/**
 * The read-only discovery half of the overlay operation family (ticket 10) — parses a project's
 * `composer.json` + its co-dev overlay into a resolved {@see OverlayEntry} list. This is what
 * `surgeon:overlay list` reports and what {@see OverlayAudit} diagnoses; it never mutates.
 *
 * **Overlay-file resolution (ticket 10 — "fall back to `.dist`/`.off`").** A project may carry the live
 * overlay, only its committed template, or nothing:
 *   1. `composer.local.json`       → {@see OverlayState::Active}   (the overlay is on)
 *   2. `composer.local.json.dist`  → {@see OverlayState::Template} (only the template — overlay off)
 *   3. `composer.local.json.off`   → {@see OverlayState::Template} (the starters' template name)
 *   4. none                        → {@see OverlayState::Absent}
 * When active *and* a template both exist we still parse the active file (it is the source of truth for
 * what is materialized); the template path is tracked so `materialize` knows what to copy from.
 *
 * **"Required by the project"** is the union of the base `require` + `require-dev` keys and the overlay's
 * own `require` keys. The composer-merge-plugin only materializes a path repo whose package is required,
 * so a resolvable path repo outside that set is a *harmless extra* (the `.dist` template says as much),
 * NOT a defect — {@see OverlayEntry::$required} is how the audit keeps the two apart.
 */
class OverlayManifest
{
    /**
     * @param  list<OverlayEntry>  $entries  resolved `type: path` repositories
     * @param  array<string, string>  $overlayRequire  the overlay's own `require` map (name => constraint)
     * @param  list<string>  $requiredNames  base require/require-dev ∪ overlay require (package names)
     */
    public function __construct(
        public string $baseDir,
        public OverlayState $state,
        public ?string $activeFilePath,
        public ?string $templateFilePath,
        public ?string $sourceFilePath,
        public array $entries,
        public array $overlayRequire,
        public array $requiredNames,
    ) {}

    public static function fromDirectory(string $baseDir): self
    {
        $baseDir = rtrim($baseDir, '/');

        $active = self::fileIfExists($baseDir.'/composer.local.json');
        $template = self::fileIfExists($baseDir.'/composer.local.json.dist')
            ?? self::fileIfExists($baseDir.'/composer.local.json.off');

        $state = $active !== null
            ? OverlayState::Active
            : ($template !== null ? OverlayState::Template : OverlayState::Absent);

        $source = $active ?? $template;

        $base = self::readJson($baseDir.'/composer.json');
        $overlay = $source !== null ? self::readJson($source) : [];

        $overlayRequire = self::stringMap($overlay['require'] ?? []);
        $requiredNames = self::requiredNames($base, $overlayRequire);

        $entries = self::resolveEntries($baseDir, $overlay['repositories'] ?? [], $requiredNames);

        return new self($baseDir, $state, $active, $template, $source, $entries, $overlayRequire, $requiredNames);
    }

    /** @return list<OverlayEntry> the resolvable path repos whose package is actually required */
    public function requiredEntries(): array
    {
        return array_values(array_filter($this->entries, fn (OverlayEntry $e) => $e->required));
    }

    /** @return list<OverlayEntry> declared path repos whose target checkout is missing on disk */
    public function brokenEntries(): array
    {
        return array_values(array_filter($this->entries, fn (OverlayEntry $e) => $e->isBroken()));
    }

    /** @return list<OverlayEntry> resolvable path repos no `require` engages (harmless extras) */
    public function extraEntries(): array
    {
        return array_values(array_filter($this->entries, fn (OverlayEntry $e) => $e->isExtra()));
    }

    /**
     * Overlay `require` entries with no matching `type: path` repo to satisfy them — a co-dev intent the
     * overlay can't fulfil (the package would resolve from git, not the local checkout). A genuine
     * mal-adaptation the audit flags.
     *
     * @return list<string> package names
     */
    public function requiresWithoutPathRepo(): array
    {
        $resolvedNames = array_filter(array_map(fn (OverlayEntry $e) => $e->name, $this->entries));

        return array_values(array_filter(
            array_keys($this->overlayRequire),
            fn (string $name) => ! in_array($name, $resolvedNames, true),
        ));
    }

    /** @return array<string, mixed> the structured `surgeon:overlay list` payload */
    public function toArray(): array
    {
        return [
            'base_dir' => $this->baseDir,
            'state' => $this->state->value,
            'active_file' => $this->activeFilePath,
            'template_file' => $this->templateFilePath,
            'counts' => [
                'entries' => count($this->entries),
                'required' => count($this->requiredEntries()),
                'extra' => count($this->extraEntries()),
                'broken' => count($this->brokenEntries()),
            ],
            'entries' => array_map(fn (OverlayEntry $e) => $e->toArray(), $this->entries),
        ];
    }

    // --- resolution internals ----------------------------------------------------------------------

    /**
     * @param  list<mixed>  $repositories  the overlay's `repositories` array
     * @param  list<string>  $requiredNames
     * @return list<OverlayEntry>
     */
    private static function resolveEntries(string $baseDir, array $repositories, array $requiredNames): array
    {
        $entries = [];
        foreach ($repositories as $repo) {
            if (! is_array($repo) || ($repo['type'] ?? null) !== 'path' || ! isset($repo['url'])) {
                continue;
            }

            $url = (string) $repo['url'];
            $path = self::resolvePath($baseDir, $url);
            $exists = is_dir($path);
            $name = $exists ? self::packageNameAt($path) : null;
            $symlink = (bool) ($repo['options']['symlink'] ?? true);
            $required = $name !== null && in_array($name, $requiredNames, true);

            $entries[] = new OverlayEntry($name, $url, $path, $exists, $symlink, $required);
        }

        return $entries;
    }

    private static function resolvePath(string $baseDir, string $url): string
    {
        if (str_starts_with($url, '/')) {
            return rtrim($url, '/');
        }

        $joined = $baseDir.'/'.$url;
        $real = realpath($joined);

        return $real !== false ? $real : self::normalize($joined);
    }

    /** Collapse `.`/`..` segments without hitting disk (so a missing path still normalizes cleanly). */
    private static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        return '/'.implode('/', $out);
    }

    private static function packageNameAt(string $path): ?string
    {
        $composer = $path.'/composer.json';
        if (! is_file($composer)) {
            return null;
        }
        $data = self::readJson($composer);

        return isset($data['name']) ? (string) $data['name'] : null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, string>  $overlayRequire
     * @return list<string>
     */
    private static function requiredNames(array $base, array $overlayRequire): array
    {
        $names = array_merge(
            array_keys(self::stringMap($base['require'] ?? [])),
            array_keys(self::stringMap($base['require-dev'] ?? [])),
            array_keys($overlayRequire),
        );

        $names = array_values(array_unique(array_filter($names, fn ($n) => $n !== 'php')));

        return $names;
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    private static function stringMap($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = (string) $v;
        }

        return $out;
    }

    private static function fileIfExists(string $path): ?string
    {
        return is_file($path) ? $path : null;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
