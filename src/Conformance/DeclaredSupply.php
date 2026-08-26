<?php

namespace Rushing\Surgeon\Conformance;

/**
 * One offline read of a repo's **declared, fetchable supply** — everything its composer configs say
 * about where a package can be obtained from, minus `type: path` entries, which travel with nothing.
 *
 * Extracted so the two supply audits that ask about `repositories` cannot drift apart:
 * {@see RequireWithoutSupplyAudit} (does this repo's OWN require have a source that survives leaving
 * this machine?) and {@see TransitiveSupplyAudit} (does every private package in the repo's transitive
 * closure have one?). Both were written against the same three readings, and a second hand-rolled copy
 * of them is how an estate ends up with two supply checks that disagree about the same manifest.
 *
 * **`repositories` is legally a list OR a name-keyed object, and this estate uses both.** Iterating the
 * decoded value covers both shapes; a reader that indexes positionally silently returns nothing at the
 * object-keyed roots (`~/Herd/stephenrushing` is one), which reads as "no supply declared at all" — the
 * most dangerous possible false reading for a supply check, since it is indistinguishable from a root
 * that genuinely declares none.
 *
 * **The three readings, and why there are exactly three:**
 *  - a `package` repository inlines a package name → matched **exactly**;
 *  - a `vcs`/`git`/`github`-style entry names a **remote, never a package** → the only offline reading
 *    is its url carrying the package's stem. This estate makes that necessary rather than merely
 *    convenient: `rushing/*` packages are supplied from the `stephenr85` GitHub org, so the vendor half
 *    of the name appears in no url anywhere;
 *  - a `composer` registry is **opaque offline** — it may or may not carry the package and this class
 *    does not go and look, so it travels as a caveat on the finding rather than silently suppressing it.
 */
class DeclaredSupply
{
    /**
     * @param  list<string>  $names  package names inlined by `type: package` entries
     * @param  list<string>  $urls  urls of every other non-path entry (matched on stem)
     * @param  list<string>  $opaque  non-packagist `type: composer` registry urls — unread, reported
     */
    public function __construct(
        public array $names = [],
        public array $urls = [],
        public array $opaque = [],
    ) {}

    /**
     * Read the supply declared across any number of decoded composer configs (the manifest, then the
     * overlay in force — later configs add supply, they never remove it).
     *
     * @param  array<string, mixed>  ...$configs
     */
    public static function read(array ...$configs): self
    {
        $names = [];
        $urls = [];
        $opaque = [];

        foreach ($configs as $config) {
            $repositories = $config['repositories'] ?? [];
            if (! is_array($repositories)) {
                continue;
            }

            // List or name-keyed object — iteration covers both; the key is never read here.
            foreach ($repositories as $repo) {
                if (! is_array($repo)) {
                    continue; // `"packagist.org": false` and friends
                }

                $type = $repo['type'] ?? null;
                $url = isset($repo['url']) && is_string($repo['url']) ? $repo['url'] : null;

                if ($type === 'path') {
                    continue;
                }

                if ($type === 'package') {
                    $inlined = $repo['package']['name'] ?? null;
                    if (is_string($inlined)) {
                        $names[] = $inlined;
                    }

                    continue;
                }

                if ($type === 'composer' && $url !== null && ! str_contains($url, 'packagist.org')) {
                    $opaque[] = $url;

                    continue;
                }

                if ($url !== null) {
                    $urls[] = $url;
                }
            }
        }

        return new self(
            array_values(array_unique($names)),
            array_values(array_unique($urls)),
            array_values(array_unique($opaque)),
        );
    }

    /**
     * Does anything non-path here claim to supply the package? Exact on an inlined name; otherwise the
     * package's stem appearing in a repository url, which is the only offline reading a vcs entry admits.
     */
    public function declares(string $name): bool
    {
        if (in_array($name, $this->names, true)) {
            return true;
        }

        $stem = $this->stemOf($name);
        if ($stem === '') {
            return false;
        }

        foreach ($this->urls as $url) {
            if (str_contains($url, $stem)) {
                return true;
            }
        }

        return false;
    }

    /** The package half of a `vendor/name`, which is what a supply url actually carries. */
    public function stemOf(string $name): string
    {
        $slash = strpos($name, '/');

        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
