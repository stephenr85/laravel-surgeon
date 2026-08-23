<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Surgeon\Rewrite\DestinationDepAdvisor;

/**
 * A read of a host's `vendor/composer/installed.json` — composer's own record of every REQUIRED downstream
 * package, its declared PSR-4 roots, and its on-disk install path. This is the substrate both upstream-DTO
 * audits (ticket 15) resolve against, in HOST context (ticket-13 decision 6): surgeon is foundation-tier
 * and can't reach up to a `Splicewire\*` package, but when it RUNS inside a host it may legally read what
 * that host has installed — the beam-ux twin is DATA discovered at runtime, not a compile dependency.
 *
 * It answers the questions the audits ask of the installed estate:
 *  - **b1:** which downstream package OWNS a given FQN? (its longest matching PSR-4 prefix) — so an
 *    app DTO whose every import lands in one package can be nominated for promotion into it.
 *  - **b2:** does a package ship a class with a given namespace TAIL, and where does that file live? —
 *    so a twin of `App\Data\X` shipped as `Splicewire\Beam\Ux\Data\X` is found by matching the
 *    `Data\X` tail against each package's PSR-4 root + expected relative path.
 *  - **which packages are installed from a local `path` source, and what does this root's installed set
 *    already contain?** — {@see self::installedFromPath()} and {@see self::satisfies()}, the substrate
 *    {@see UnsatisfiedNeighbourRequireAudit} resolves against (ticket 69).
 *
 * **Every installed package is kept, PSR-4 or not.** An earlier revision dropped packages declaring no
 * `psr-4` autoload, which was harmless while the only questions were namespace-shaped and is a defect the
 * moment the question is "is this package present?": a `files`-autoload package (every `symfony/polyfill-*`)
 * would read as absent and manufacture a supply finding against a root that has it installed. `ownerOf()`
 * and `findTwin()` are unaffected — a package with no roots contributes no prefixes to either.
 *
 * It reads the SAME authoritative source {@see DestinationDepAdvisor} reads
 * (installed.json → each package's real PSR-4 roots), so vendor≠namespace-root packages (GuzzleHttp,
 * PhpParser) resolve precisely rather than by a Studly-of-vendor guess.
 */
class InstalledPackages
{
    /**
     * @param  list<array{name: string, roots: array<string, string>, path: string, source: ?string, provides: list<string>}>  $packages
     *                                                                                                                                    each: composer name, a psr-4 map (prefix-with-trailing-`\` => absolute src dir), install path, install
     *                                                                                                                                    source type (`path` for a local checkout), and everything it `replace`s or `provide`s
     */
    protected function __construct(
        public array $packages,
    ) {}

    /**
     * Build from a host root holding `vendor/composer/installed.json`. Empty (no packages) when the
     * manifest is absent — the audits then simply find no twin / no downstream home, and stay silent.
     */
    public static function fromHostRoot(string $hostRoot): self
    {
        $vendorDir = rtrim($hostRoot, '/').'/vendor';
        $file = $vendorDir.'/composer/installed.json';
        if (! is_file($file)) {
            return new self([]);
        }

        $decoded = json_decode((string) @file_get_contents($file), true);
        if (! is_array($decoded)) {
            return new self([]);
        }
        $raw = $decoded['packages'] ?? $decoded; // v2 wraps in "packages"; v1 is a bare list.
        if (! is_array($raw)) {
            return new self([]);
        }

        // composer v2 records install-path relative to vendor/composer/; v1 has none, so fall back to
        // vendor/<name>. We resolve to an absolute src dir per PSR-4 prefix so b2 can stat the twin file.
        $packages = [];
        foreach ($raw as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }
            $name = $package['name'];
            $installPath = is_string($package['install-path'] ?? null)
                ? self::absolutize($vendorDir.'/composer/'.$package['install-path'])
                : $vendorDir.'/'.$name;

            $roots = [];
            $psr4 = $package['autoload']['psr-4'] ?? [];
            if (is_array($psr4)) {
                foreach ($psr4 as $prefix => $dir) {
                    if (! is_string($prefix) || $prefix === '') {
                        continue;
                    }
                    $dir = is_array($dir) ? ($dir[0] ?? null) : $dir;
                    if (is_string($dir)) {
                        $roots[rtrim($prefix, '\\').'\\'] = rtrim($installPath, '/').'/'.trim($dir, '/');
                    }
                }
            }
            $provides = [];
            foreach (['replace', 'provide'] as $block) {
                $names = $package[$block] ?? [];
                if (is_array($names)) {
                    foreach (array_keys($names) as $provided) {
                        $provides[] = strtolower((string) $provided);
                    }
                }
            }

            $packages[] = [
                'name' => $name,
                'roots' => $roots,
                'path' => $installPath,
                'source' => self::sourceType($package),
                'provides' => array_values(array_unique($provides)),
            ];
        }

        return new self($packages);
    }

    /**
     * The package whose PSR-4 prefix most specifically owns an FQN (longest-prefix wins, PSR-4's own
     * rule), or null when no installed package covers it — b1's "which downstream package does this
     * import point into?" resolution.
     */
    public function ownerOf(string $fqn): ?string
    {
        $fqn = ltrim($fqn, '\\');
        $best = null;
        $bestLen = -1;
        foreach ($this->packages as $package) {
            foreach ($package['roots'] as $prefix => $dir) {
                if (str_starts_with($fqn, $prefix) && strlen($prefix) > $bestLen) {
                    $best = $package['name'];
                    $bestLen = strlen($prefix);
                }
            }
        }

        return $best;
    }

    /**
     * Find a downstream twin of $shortName + $tail: a class a required package ships under its own PSR-4
     * root whose namespace tail (its final $tailSegments segments) equals $tail AND whose file exists on
     * disk at the PSR-4-expected path. Returns the twin's FQN + file, or null when no package ships it —
     * b2's deterministic twin detection.
     *
     * @param  string  $tail  the app class's namespace tail (e.g. `Data\BeamUxEntryBodyData`)
     * @return array{fqn: string, file: string, package: string}|null
     */
    public function findTwin(string $tail, int $tailSegments = 2): ?array
    {
        $tail = ltrim($tail, '\\');
        $tailPath = str_replace('\\', '/', $tail).'.php';

        foreach ($this->packages as $package) {
            foreach ($package['roots'] as $prefix => $dir) {
                // The candidate FQN is the package root prefix + the app class's tail; its file is the
                // src dir + the tail-as-path. Both must line up AND the file must exist.
                $candidateFqn = $prefix.$tail;
                if (self::tailOf($candidateFqn, $tailSegments) !== $tail) {
                    continue;
                }
                $candidateFile = rtrim($dir, '/').'/'.$tailPath;
                if (is_file($candidateFile)) {
                    return ['fqn' => $candidateFqn, 'file' => $candidateFile, 'package' => $package['name']];
                }
            }
        }

        return null;
    }

    /**
     * Every installed package whose composer name starts with a vendor prefix (e.g. `splicewire/`),
     * mapped to its absolute install path — for directory-scan discovery (which packages does this
     * vendor own, and where do they live on disk), distinct from `ownerOf()`'s FQN resolution.
     *
     * @return array<string, string> package name => absolute install path
     */
    public function namedLike(string $vendorPrefix): array
    {
        $matches = [];
        foreach ($this->packages as $package) {
            if (str_starts_with($package['name'], $vendorPrefix)) {
                $matches[$package['name']] = $package['path'];
            }
        }

        return $matches;
    }

    /**
     * Every package composer installed from a local `path` source — a symlink or copy of a checkout on
     * this machine, i.e. live source the host is running against rather than a resolved artifact.
     *
     * @return list<array{name: string, roots: array<string, string>, path: string, source: ?string, provides: list<string>}>
     */
    public function installedFromPath(): array
    {
        return array_values(array_filter($this->packages, fn (array $package) => $package['source'] === 'path'));
    }

    /**
     * Does this root's installed set contain the named package — directly, or through something that
     * `replace`s or `provide`s it? Composer's own resolution rule, and the only one an offline read of
     * `installed.json` admits. Case-insensitive: composer names are.
     */
    public function satisfies(string $name): bool
    {
        $name = strtolower($name);

        foreach ($this->packages as $package) {
            if (strtolower($package['name']) === $name || in_array($name, $package['provides'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How composer got this package. A local checkout is recorded as `path` on the dist half, the source
     * half, or both depending on whether it was symlinked or mirrored — so `path` wins whenever either
     * says so, and anything else reports whichever type is recorded.
     *
     * @param  array<string, mixed>  $package
     */
    private static function sourceType(array $package): ?string
    {
        $dist = is_array($package['dist'] ?? null) ? ($package['dist']['type'] ?? null) : null;
        $source = is_array($package['source'] ?? null) ? ($package['source']['type'] ?? null) : null;

        if ($dist === 'path' || $source === 'path') {
            return 'path';
        }

        return is_string($dist) ? $dist : (is_string($source) ? $source : null);
    }

    private static function tailOf(string $fqn, int $segments): string
    {
        $parts = explode('\\', ltrim($fqn, '\\'));

        return implode('\\', array_slice($parts, -$segments));
    }

    /** Collapse `a/b/../c` install paths to a real absolute path (best-effort; falls back to the input). */
    private static function absolutize(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }
}
