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
 * It answers the two questions the audits ask of the installed estate:
 *  - **b1:** which downstream package OWNS a given FQN? (its longest matching PSR-4 prefix) — so an
 *    app DTO whose every import lands in one package can be nominated for promotion into it.
 *  - **b2:** does a package ship a class with a given namespace TAIL, and where does that file live? —
 *    so a twin of `App\Data\X` shipped as `Splicewire\Beam\Ux\Data\X` is found by matching the
 *    `Data\X` tail against each package's PSR-4 root + expected relative path.
 *
 * It reads the SAME authoritative source {@see DestinationDepAdvisor} reads
 * (installed.json → each package's real PSR-4 roots), so vendor≠namespace-root packages (GuzzleHttp,
 * PhpParser) resolve precisely rather than by a Studly-of-vendor guess.
 */
class InstalledPackages
{
    /**
     * @param  list<array{name: string, roots: array<string, string>, path: string}>  $packages
     *                                                                                           each: composer name, a psr-4 map (prefix-with-trailing-`\` => absolute src dir), and install path
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
            if ($roots !== []) {
                $packages[] = ['name' => $name, 'roots' => $roots, 'path' => $installPath];
            }
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
