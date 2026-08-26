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
 *    is its url's repo name against the package's stem. This estate makes that necessary rather than
 *    merely convenient: `rushing/*` packages are supplied from the `stephenr85` GitHub org, so the vendor
 *    half of the name appears in no url anywhere. The match is **boundary-aware, not a substring**, and
 *    that is a correction rather than a refinement: a bare `str_contains` lets
 *    `.../laravel-beam-notifications.git` read as supply for `splicewire/laravel-beam`, which is the
 *    UNDER-reporting direction — a supply check that silently declares a gap filled. Found by resolving
 *    `~/Herd/stephenrushing` after seeding its closure (beam-facade ticket 131): every nominated entry
 *    was written, the check then read clean, and composer still walled on `splicewire/laravel-beam`. The
 *    rule now demands the repo name **equal** the stem or differ from it only by a `-`-delimited prefix
 *    (`laravel-package-topology.git` supplying `rushing/php-package-topology` stays out of reach, which
 *    over-reports — the safe direction for an instrument that nominates and never certifies);
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
     * repository url's own repo name against the package's stem, which is the only offline reading a vcs
     * entry admits — boundary-aware, for the reason in the class docblock.
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
            if ($this->matches($this->repoNameOf($url), $stem)) {
                return true;
            }
        }

        return false;
    }

    /** The repo name a url names: its last path segment, without a `.git` suffix or a trailing slash. */
    public function repoNameOf(string $url): string
    {
        $trimmed = rtrim($url, '/');
        $slash = strrpos($trimmed, '/');
        $repo = $slash === false ? $trimmed : substr($trimmed, $slash + 1);

        return str_ends_with($repo, '.git') ? substr($repo, 0, -4) : $repo;
    }

    /**
     * A repo name supplies a package stem when the two are equal, or differ only by a `-`-delimited
     * prefix on one side — the estate renames a package's vendor half far more often than its tail
     * (`schemastud/php-json-ns` ships from `php-json-ns.git`, `splicewire/laravel-satellite` from
     * `laravel-satellite.git`). What it must NOT accept is a longer name that merely STARTS with the
     * stem: `laravel-beam-notifications` is a different package from `laravel-beam`, and treating it as
     * supply is how a supply check reports a gap filled that composer then walls on.
     */
    private function matches(string $repo, string $stem): bool
    {
        return $repo === $stem
            || str_ends_with($repo, '-'.$stem)
            || str_ends_with($stem, '-'.$repo);
    }

    /** The package half of a `vendor/name`, which is what a supply url actually carries. */
    public function stemOf(string $name): string
    {
        $slash = strpos($name, '/');

        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
