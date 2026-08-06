<?php

namespace Rushing\Surgeon\Overlay;

/**
 * The mutation half of the overlay family (ticket 10) — builds a previewable {@see OverlayMutation} for
 * each writing verb, computing exactly the `composer.local.json` (or file-copy) change it would make
 * without writing anything. The command decides whether to {@see OverlayMutation::apply()} it.
 *
 * It performs ONLY the file-level change; running `composer update` to actually (de)materialize the
 * symlinks is the command's separate, gated follow-up (ticket 10: "`composer.local.json` edits +
 * `composer update` are the mutations"). Keeping the JSON edit and the composer run distinct is what
 * lets the touch-manifest review story stay honest — the sidecar records the file change; the composer
 * effect is announced, never silently folded in.
 */
class OverlayEditor
{
    public function __construct(
        public string $baseDir,
    ) {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /** Add a `type: path` repo (+ optional `require`) to the active overlay. */
    public function add(string $url, ?string $constraint = null, ?string $name = null): OverlayMutation
    {
        $file = $this->activeFileOrFail();
        $before = (string) file_get_contents($file);
        $data = $this->decode($before);

        $repositories = $data['repositories'] ?? [];
        $exists = false;
        foreach ($repositories as $repo) {
            if (is_array($repo) && ($repo['url'] ?? null) === $url) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            $repositories[] = ['type' => 'path', 'url' => $url, 'options' => ['symlink' => true]];
        }
        $data['repositories'] = array_values($repositories);

        if ($constraint !== null && $name !== null) {
            $require = $data['require'] ?? [];
            $require[$name] = $constraint;
            $data['require'] = $require;
        }

        $mutation = new OverlayMutation(OverlayVerb::Add, $this->baseDir, "add path repo {$url}"
            .($name !== null ? " (require {$name})" : ''));
        $mutation->change($file, $before, $this->encode($data));

        return $mutation;
    }

    /** Drop a path repo (matched by url or resolved package name) + its `require` from the active overlay. */
    public function remove(string $nameOrUrl): OverlayMutation
    {
        $file = $this->activeFileOrFail();
        $before = (string) file_get_contents($file);
        $data = $this->decode($before);

        $urls = $this->urlsFor($nameOrUrl);
        $names = [$nameOrUrl];
        foreach ($this->manifest()->entries as $entry) {
            if (in_array($entry->url, $urls, true) && $entry->name !== null) {
                $names[] = $entry->name;
            }
        }

        $data['repositories'] = array_values(array_filter(
            $data['repositories'] ?? [],
            fn ($repo) => ! (is_array($repo) && in_array($repo['url'] ?? null, $urls, true)),
        ));

        if (isset($data['require'])) {
            foreach ($names as $name) {
                unset($data['require'][$name]);
            }
            if ($data['require'] === []) {
                unset($data['require']);
            }
        }

        $mutation = new OverlayMutation(OverlayVerb::Remove, $this->baseDir, "remove path repo {$nameOrUrl}");
        $mutation->change($file, $before, $this->encode($data));

        return $mutation;
    }

    /** Turn the overlay on: copy the committed template to `composer.local.json`. */
    public function materialize(): OverlayMutation
    {
        $manifest = $this->manifest();
        if ($manifest->templateFilePath === null) {
            throw new \InvalidArgumentException('No overlay template (composer.local.json.dist / .off) to materialize from.');
        }

        $target = $this->baseDir.'/composer.local.json';
        $before = is_file($target) ? (string) file_get_contents($target) : null;
        $after = (string) file_get_contents($manifest->templateFilePath);

        $rel = basename($manifest->templateFilePath);
        $mutation = new OverlayMutation(OverlayVerb::Materialize, $this->baseDir, "materialize overlay from {$rel}");
        $mutation->change($target, $before, $after);

        return $mutation;
    }

    /** Turn the overlay off: remove `composer.local.json`, back to template-only. */
    public function tearDown(): OverlayMutation
    {
        $target = $this->activeFileOrFail();
        $before = (string) file_get_contents($target);

        $mutation = new OverlayMutation(OverlayVerb::TearDown, $this->baseDir, 'tear down overlay (remove composer.local.json)');
        $mutation->change($target, $before, null);

        return $mutation;
    }

    /**
     * Reconcile this project's overlay toward a sibling's canonical path-repo set (ticket 10 — "the
     * fleet shares the same path-repo set"). Additive: adds every canonical `url` this overlay lacks;
     * never removes (a removal is a per-repo judgment, left to explicit `remove`). Returns an empty
     * mutation when already in sync.
     *
     * @param  list<string>  $canonicalUrls  the sibling's path-repo urls
     */
    public function sync(array $canonicalUrls): OverlayMutation
    {
        $file = $this->activeFileOrFail();
        $before = (string) file_get_contents($file);
        $data = $this->decode($before);

        $have = [];
        foreach ($data['repositories'] ?? [] as $repo) {
            if (is_array($repo) && isset($repo['url'])) {
                $have[(string) $repo['url']] = true;
            }
        }

        $added = [];
        $repositories = $data['repositories'] ?? [];
        foreach ($canonicalUrls as $url) {
            if (! isset($have[$url])) {
                $repositories[] = ['type' => 'path', 'url' => $url, 'options' => ['symlink' => true]];
                $added[] = $url;
            }
        }
        $data['repositories'] = array_values($repositories);

        $mutation = new OverlayMutation(OverlayVerb::Sync, $this->baseDir,
            $added === [] ? 'overlay already in sync with the canonical set' : 'sync: add '.count($added).' path repo(s)');
        if ($added !== []) {
            $mutation->change($file, $before, $this->encode($data));
        }

        return $mutation;
    }

    // --- internals ---------------------------------------------------------------------------------

    public function manifest(): OverlayManifest
    {
        return OverlayManifest::fromDirectory($this->baseDir);
    }

    /** @return list<string> the declared url(s) matching a package name or a literal url */
    private function urlsFor(string $nameOrUrl): array
    {
        $urls = [];
        foreach ($this->manifest()->entries as $entry) {
            if ($entry->name === $nameOrUrl || $entry->url === $nameOrUrl) {
                $urls[] = $entry->url;
            }
        }

        // A literal url that resolved to no entry (e.g. a broken one not yet listed) still matches itself.
        if ($urls === [] && str_contains($nameOrUrl, '/')) {
            $urls[] = $nameOrUrl;
        }

        return array_values(array_unique($urls));
    }

    private function activeFileOrFail(): string
    {
        $path = $this->baseDir.'/composer.local.json';
        if (! is_file($path)) {
            throw new \InvalidArgumentException('No active composer.local.json overlay — run `surgeon:overlay materialize` first.');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
}
