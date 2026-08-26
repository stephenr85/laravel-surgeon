<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **a package's `src/` imports a class nothing installed
 * supplies.** The manifest is silent, the suite is green, and the first thing that loads the consuming
 * class takes the process down.
 *
 * **Why none of the five supply audits can see it, and cannot be made to.** {@see DanglingPathRepoAudit},
 * {@see RequireWithoutSupplyAudit}, {@see UnsatisfiedNeighbourRequireAudit},
 * {@see UnsatisfiedRootRequireAudit} and {@see TransitiveSupplyAudit} all read **manifests** — a
 * `require` block, a `repositories` list, an `installed.json` roster. This defect is invisible in a
 * manifest by construction: **the import is not a require**, so there is nothing to be unsatisfied. The
 * evidence lives in the source text and nowhere else. That is the gap this closes, and it is why this
 * audit is a sixth instrument rather than a widening of any of the five.
 *
 * ## The discriminator is the POSITION, not the absence
 *
 * The absence alone is not a defect — this is the single most important thing about this audit, and the
 * rule the estate's original grep got wrong. A `use` statement is **compile-time aliasing only**; PHP
 * never autoloads on it. So `use Saloon\Http\Connector;` followed by nothing but `Connector::class`
 * is completely safe with saloon uninstalled, and `splicewire/laravel-beam` carries exactly that shape
 * across two codegen classes — six absent Saloon imports, zero risk, because a class-string does not
 * load a class. `instanceof`, a docblock, a parameter type-hint and an attribute argument are all in
 * the same safe bucket. Flagging them is the false positive this audit exists to avoid, and both
 * {@see HarnessProviderAudit}'s import control and the original hand grep shared it.
 *
 * What is NOT safe is an alias in a **class-load position** — `extends`, `implements`, or a trait
 * `use` inside a class body. PHP resolves all three at class-**declaration** time, before any of the
 * class's own code runs, so there is no call site to guard, no `class_exists()` that helps, and nothing
 * for a test to assert against. Measured: a discovery pass that walked all of
 * `splicewire/laravel-satellite-composition`'s `src/` killed the suite outright rather than reporting a
 * failure, and the session that hit it could only get coverage by narrowing discovery to `*Data.php`.
 * That is why the class-load-position finding is **Fail** and everything else is **Warn**.
 *
 * ## Two absences, one rule, different repairs
 *
 * Both are reported, and the finding says which, because the repair differs:
 *  - **the namespace is unowned** — no installed package declares a PSR-4 prefix covering the FQN. The
 *    package is not installed here at all. Repair: require and install it.
 *  - **the prefix is owned, the file is absent** — an installed package's PSR-4 prefix covers the FQN,
 *    but no file sits at the path PSR-4 demands under any root for that prefix. The package is present
 *    and the class is not: it moved, was renamed, or was never written. This is the half a
 *    prefix-ownership read (`InstalledPackages::ownerOf()`) reports as *satisfied*, and it is the half
 *    the motivating instance lives in — `Splicewire\Beam\Workflows\Display\Concerns\HasStatusChannel`
 *    resolves to `splicewire/laravel-beam` by longest prefix, beam is installed, and beam has no
 *    `src/Workflows/` at all. An audit built on `ownerOf()` alone would have read its own motivating
 *    instance as green.
 *
 * ## Static tokenization, deliberately — never a subprocess-per-class walk
 *
 * The obvious alternative is to fork a PHP process per class and see which ones die. It is rejected on
 * three counts, the third decisive:
 *  - **cost** — one process per class over an estate this size is minutes per root, in a check that is
 *    meant to run everywhere;
 *  - **it needs a bootable autoloader**, which a package checkout mid-co-dev frequently does not have;
 *  - **it cannot tell you what it found.** A dead subprocess reports "this file fataled" and nothing
 *    else — not which symbol was absent, not whether the position was load-bearing, and it conflates a
 *    missing trait with every other fatal in the file. The thing this audit must report is exactly the
 *    thing a subprocess cannot say.
 *
 * Tokenization ({@see \token_get_all}) reads source and loads nothing, so **the detector cannot die on
 * the defect it detects** — the property the estate has spent a night finding the absence of in other
 * clothes. What a subprocess would add is supply arriving dynamically (an `eval`, a classmap the
 * autoloader builds at install time); that is disclaimed below rather than chased.
 *
 * ## The false-positive controls, each measured
 *
 * **Only names bound by a top-level `use` import are resolved.** A same-namespace or unqualified name
 * is left alone: unqualified names fall back to the global namespace, so `extends Exception` in a
 * namespaced file is correct and would read as absent.
 *
 * **Composer's own generated classmap is consulted before anything is called absent.** A
 * classmap-autoloaded package declares no PSR-4 prefix at all, so every import into one lands in the
 * unowned bucket by construction — and `phpunit/phpunit` is classmap-autoloaded. Left uncontrolled, the
 * first thing this audit printed was a Fail against every package shipping a `src/Testing/` base class
 * extending `PHPUnit\Framework\TestCase` (measured: 2 in `rushing/php-package-topology` alone), which is
 * a correct and deliberate shape. `vendor/composer/autoload_classmap.php` is composer's authoritative
 * answer and is read as TEXT — never included, because this audit executes nothing.
 *
 * **A `classmap` autoload also makes a prefix OWNER opaque — but a `files` autoload does not.** A
 * package may declare both a PSR-4 prefix and a classmap; path arithmetic says nothing about the
 * classmap half, so any owner declaring one suppresses the finding rather than guessing. A `files`
 * autoload is a different thing entirely: it *includes* a bootstrap file at startup and resolves no
 * class by name, so it must not suppress anything. Treating the two alike silenced this audit's own
 * motivating instance — `splicewire/laravel-beam` declares `files: [src/helpers.php]` for its helper
 * functions, which made it opaque and made `Splicewire\Beam\Workflows\Display\Concerns\HasStatusChannel`
 * read as supplied by the very package that does not ship it.
 *
 * **Application-skeleton prefixes declared by an installed package are dropped.** `laravel/pint` ships a
 * Laravel app and declares `App\`, `Database\Seeders\`, `Database\Factories\`; left in, it becomes the
 * apparent owner of every `App\Models\*` reference in the estate and manufactures a Fail attributed to a
 * code formatter. Measured at `splicewire/tower` (4 findings) and `splicewire/spiders` (1). The same
 * prefixes declared by the ROOT's own manifest are kept — there they are the real supply.
 *
 * **A candidate is resolved against its OWN installed set where it has one**, the running root's
 * otherwise — {@see self::supplyFor()} carries the measurement that forced this. A clean line is clean
 * for the packages this root can reach.
 *
 * ## Tier direction
 *
 * Surgeon is foundation-tier `rushing/*` and names no `splicewire/*` or `schemastud/*` type. Every
 * package it checks sits ABOVE it, and it reaches them the only legal way: their source text and
 * manifests read as DATA discovered at runtime, never as a compile dependency — the same posture
 * {@see UpstreamDtoAudit}, {@see UnsatisfiedNeighbourRequireAudit} and {@see HarnessProviderAudit}
 * already run in. It is not a `*:doctor` check for the same reason 86 was not: a doctor is arm-scoped,
 * and this defect class spans three vendors.
 */
class UnresolvableImportAudit implements SuggestsOperations
{
    public const CHECK = 'unresolvable-import';

    /**
     * Namespaces that belong to a Laravel APPLICATION skeleton. Declared by the root, they are real
     * supply; declared by an installed package (`laravel/pint` ships an app), they are a vendored
     * application whose `app/` is nobody else's supply — see the class docblock.
     */
    public const SKELETON_PREFIXES = ['App\\', 'Database\\', 'Tests\\'];

    /** The one line the report always carries about what it did NOT check. */
    public const SCOPE_NOTE = 'Scope: names bound by a top-level `use` import in the package\'s own `src/`, resolved against PSR-4 path arithmetic over this root\'s installed set. Same-namespace and unqualified names are not resolved (an unqualified name falls back to the global namespace). An owner declaring a `classmap` or `files` autoload is opaque and suppresses the finding, because those map class to file by a table rather than by path. Nothing is loaded, executed or reflected, so supply arriving dynamically is not seen. Reach is this root\'s own installed set: a clean line is clean FOR THIS ROOT.';

    /**
     * @param  string  $appRoot  the repo root whose `vendor/composer/installed.json` is read (the running host)
     */
    /** @var array{index: array<string, list<array{dir: ?string, owner: string, opaque: bool}>>, classmap: array<string, true>}|null */
    protected ?array $rootSupply = null;

    public function __construct(
        public string $appRoot,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $root = realpath($this->appRoot) ?: rtrim($this->appRoot, '/');
        $installed = InstalledPackages::fromHostRoot($root);

        if ($installed->packages === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No vendor/composer/installed.json in this repo — nothing is installed here, so no import can be shown to be unsupplied.',
            ))];
        }

        $findings = [];
        $checked = 0;

        foreach ($this->candidates($root, $installed) as $name => $path) {
            $dir = $path.'/src';
            if (! is_dir($dir)) {
                continue;
            }

            // A candidate carrying its own installed set is resolved against IT — see self::supplyFor().
            $supply = $this->supplyFor($path, $root, $installed);
            if ($supply === null) {
                continue;
            }
            $checked++;

            foreach ($this->phpFiles($dir) as $file) {
                foreach ($this->unresolvable($file, $supply['index'], $supply['classmap']) as $unresolved) {
                    $findings[] = $this->absent($name, $path, $file, $unresolved);
                }
            }
        }

        $findings[] = $this->summary($checked, $findings);

        return $findings;
    }

    /**
     * The supply a candidate's imports are resolved against: **its own installed set when it carries
     * one**, the running root's otherwise.
     *
     * This is not a detail. A path-installed neighbour is symlinked into the root's `vendor/`, so it is
     * tempting to resolve it against the root — and that is what an earlier revision did. Measured
     * across 106 roots it turned every lean root into a noise generator: `rushing/php-popcorn` is a
     * plain PHP package with no illuminate installed, `rushing/laravel-popcorn` is path-linked into it,
     * and the audit dutifully reported that laravel-popcorn's console commands cannot extend
     * `Illuminate\Console\Command`. True at that root, and a statement about the root rather than about
     * the package — while the SAME package read clean from its own checkout, where illuminate is
     * installed. A package that carries its own `vendor/composer/installed.json` has answered the
     * question authoritatively for itself, so that answer is used and the finding becomes a property of
     * the package rather than of whichever root happened to run the sweep.
     *
     * Returns null when neither the candidate nor the root has an installed set to resolve against.
     *
     * @return array{index: array<string, list<array{dir: ?string, owner: string, opaque: bool}>>, classmap: array<string, true>}|null
     */
    protected function supplyFor(string $path, string $root, InstalledPackages $rootInstalled): ?array
    {
        if ($path !== $root && is_file($path.'/vendor/composer/installed.json')) {
            $own = InstalledPackages::fromHostRoot($path);
            if ($own->packages !== []) {
                return [
                    'index' => $this->prefixIndex($path, $own),
                    'classmap' => $this->classmap($path),
                ];
            }
        }

        $this->rootSupply ??= [
            'index' => $this->prefixIndex($root, $rootInstalled),
            'classmap' => $this->classmap($root),
        ];

        return $this->rootSupply;
    }

    /**
     * Every package whose `src/` is this root's business: the path-installed neighbours (live source the
     * host runs against) plus the running root itself. Non-path installs are excluded — a resolved
     * artifact's source is not this root's to repair, and a released package's imports were resolvable
     * at the version composer picked.
     *
     * @return array<string, string> package name => absolute checkout path
     */
    protected function candidates(string $root, InstalledPackages $installed): array
    {
        $manifest = $this->readJson($root.'/composer.json');
        $candidates = [is_string($manifest['name'] ?? null) ? $manifest['name'] : $root => $root];

        foreach ($installed->installedFromPath() as $package) {
            $candidates[$package['name']] = $package['path'];
        }

        return $candidates;
    }

    /**
     * PSR-4 prefix => the roots that supply it, each carrying its owning package and whether that owner
     * is **opaque** (declares a classmap or `files` autoload, so path arithmetic says nothing about it).
     *
     * Both the root's own manifest and every installed package contribute. Skeleton prefixes from an
     * installed package are dropped; from the root they are kept — see {@see self::SKELETON_PREFIXES}.
     *
     * @return array<string, list<array{dir: ?string, owner: string, opaque: bool}>>
     */
    protected function prefixIndex(string $root, InstalledPackages $installed): array
    {
        $index = [];

        $manifest = $this->readJson($root.'/composer.json') ?? [];
        $selfName = is_string($manifest['name'] ?? null) ? $manifest['name'] : $root;
        $selfOpaque = $this->declaresOpaqueAutoload($manifest);
        foreach (['autoload', 'autoload-dev'] as $block) {
            foreach ($this->psr4($manifest[$block] ?? null) as $prefix => $dirs) {
                foreach ($dirs as $dir) {
                    $index[$prefix][] = ['dir' => rtrim($root, '/').'/'.trim($dir, '/'), 'owner' => $selfName, 'opaque' => $selfOpaque];
                }
            }
        }

        foreach ($installed->packages as $package) {
            $manifest = $this->readJson($package['path'].'/composer.json');
            $opaque = $this->declaresOpaqueAutoload($manifest ?? []);

            // The package's OWN manifest is read for its psr-4 map, not `$package['roots']`, because
            // InstalledPackages keeps only the FIRST directory when a prefix maps to a list — correct
            // for the ownership question it answers, fatal for path arithmetic. `laravel/framework`
            // maps `Illuminate\Support\` to four directories; keeping only the first
            // (`src/Illuminate/Macroable/`) makes `Illuminate\Support\Collection` unresolvable, and
            // the audit reported it at three roots before this was found. The `roots` map is the
            // fallback for a package whose manifest cannot be read.
            $psr4 = $manifest === null ? [] : $this->psr4($manifest['autoload'] ?? null);
            if ($psr4 === []) {
                foreach ($package['roots'] as $prefix => $dir) {
                    $psr4[$prefix] = [$dir];
                }
                $absolute = true;
            } else {
                $absolute = false;
            }

            foreach ($psr4 as $prefix => $dirs) {
                if ($this->isSkeleton($prefix)) {
                    continue;
                }
                foreach ($dirs as $dir) {
                    $index[$prefix][] = [
                        'dir' => $absolute ? $dir : rtrim($package['path'], '/').'/'.trim($dir, '/'),
                        'owner' => $package['name'],
                        'opaque' => $opaque,
                    ];
                }
            }

            // A psr-0 root resolves by different path rules (underscores are separators), so it is
            // carried as an opaque owner rather than arithmetic'd against.
            foreach ($this->psr0Prefixes($package['path']) as $prefix) {
                $index[$prefix][] = ['dir' => null, 'owner' => $package['name'], 'opaque' => true];
            }
        }

        return $index;
    }

    /** Is this one of the Laravel application-skeleton namespaces, at any depth under them? */
    protected function isSkeleton(string $prefix): bool
    {
        foreach (self::SKELETON_PREFIXES as $skeleton) {
            if (str_starts_with($prefix, $skeleton)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every imported name in one file that resolves to no file on disk, with the position it is used in.
     *
     * @param  array<string, list<array{dir: ?string, owner: string, opaque: bool}>>  $index
     * @param  array<string, true>  $classmap
     * @return list<array{fqn: string, alias: string, loadBearing: bool, owner: ?string}>
     */
    protected function unresolvable(string $file, array $index, array $classmap): array
    {
        $source = (string) @file_get_contents($file);
        if ($source === '') {
            return [];
        }

        $scan = $this->scan($source);
        $unresolved = [];

        foreach ($scan['imports'] as $alias => $fqn) {
            if (isset($classmap[$fqn])) {
                continue;
            }
            $owner = $this->resolve($fqn, $index);
            if ($owner['satisfied']) {
                continue;
            }
            $unresolved[] = [
                'fqn' => $fqn,
                'alias' => $alias,
                'loadBearing' => isset($scan['loadBearing'][strtolower($alias)]),
                'owner' => $owner['owner'],
            ];
        }

        return $unresolved;
    }

    /**
     * Every class composer's generated classmap names, as a set. This is the authoritative supply for a
     * classmap-autoloaded package, which declares no PSR-4 prefix and would otherwise read as absent for
     * every one of its classes — `phpunit/phpunit` is the measured case.
     *
     * Read as TEXT, never included: the file is composer-generated and returns a plain array, but an
     * audit that executes vendor code stops being safe to run everywhere, and this one executes nothing
     * by design.
     *
     * @return array<string, true>
     */
    protected function classmap(string $root): array
    {
        $file = $root.'/vendor/composer/autoload_classmap.php';
        if (! is_file($file)) {
            return [];
        }

        $source = (string) @file_get_contents($file);
        if (! preg_match_all("/^\\s*'((?:[^'\\\\]|\\\\.)+)'\\s*=>/m", $source, $matches)) {
            return [];
        }

        $classes = [];
        foreach ($matches[1] as $class) {
            $classes[stripslashes($class)] = true;
        }

        return $classes;
    }

    /**
     * Is an FQN supplied?
     *
     * **PSR-4 FALLS THROUGH, and getting this wrong is not a rounding error.** The spec has the
     * autoloader try every matching prefix, longest first, and continue to shorter ones when the file
     * is not there — a prefix mapping is a *candidate*, not a claim of exclusive ownership. Implemented
     * as longest-prefix-wins (which is what `InstalledPackages::ownerOf()` answers, correctly, for the
     * different question it is asked) this audit reported 80 fatals across 62 packages, essentially all
     * of them `Illuminate\Support\ServiceProvider`: `laravel/framework` maps `Illuminate\Support\` to
     * four subtree directories (Macroable, Collections, Conditionable, Reflection) that do not contain
     * `ServiceProvider.php`, and maps the shorter `Illuminate\` to the tree that does. Every service
     * provider in the estate read as unloadable. Trying every matching prefix in descending length
     * order is both the spec and the difference between a usable instrument and a useless one.
     *
     * Every root for a prefix is tried, because a prefix may be declared by more than one package —
     * `Splicewire\Composition\` is declared by two in this estate — and a prefix may itself map to
     * several directories. An opaque owner (a `classmap` autoload) satisfies unconditionally.
     *
     * The reported owner is the longest matching prefix's, which is the package a reader will look in.
     *
     * @param  array<string, list<array{dir: ?string, owner: string, opaque: bool}>>  $index
     * @return array{satisfied: bool, owner: ?string}
     */
    protected function resolve(string $fqn, array $index): array
    {
        $matching = [];
        foreach ($index as $prefix => $entries) {
            if (str_starts_with($fqn, $prefix)) {
                $matching[$prefix] = $entries;
            }
        }

        if ($matching === []) {
            return ['satisfied' => false, 'owner' => null];
        }

        uksort($matching, fn ($a, $b) => strlen($b) <=> strlen($a));

        $owners = [];
        $first = true;
        foreach ($matching as $prefix => $entries) {
            $relative = str_replace('\\', '/', substr($fqn, strlen($prefix))).'.php';
            foreach ($entries as $entry) {
                if ($entry['opaque'] || $entry['dir'] === null) {
                    return ['satisfied' => true, 'owner' => $entry['owner']];
                }
                if (is_file($entry['dir'].'/'.$relative)) {
                    return ['satisfied' => true, 'owner' => $entry['owner']];
                }
                if ($first) {
                    $owners[$entry['owner']] = true;
                }
            }
            $first = false;
        }

        return ['satisfied' => false, 'owner' => implode(', ', array_keys($owners))];
    }

    /**
     * Tokenize one file into its top-level `use` imports (alias => FQN) and the set of aliases used in a
     * **class-load position** — `extends`, `implements`, or a trait `use` inside a class body.
     *
     * Brace depth is what separates the two kinds of `use`: at depth 0 it is an import, inside a class
     * body it is a trait use. `use` in a closure (`function () use ($x)`) is followed by `(` and is
     * skipped by the same walk. Grouped imports (`use A\B\{C, D};`) are expanded.
     *
     * @return array{imports: array<string, string>, loadBearing: array<string, true>}
     */
    protected function scan(string $source): array
    {
        $tokens = token_get_all($source);
        $imports = [];
        $loadBearing = [];
        $depth = 0;
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                $i++;

                continue;
            }

            if ($token[0] === T_USE) {
                $i = $depth === 0
                    ? $this->readImport($tokens, $i + 1, $imports)
                    : $this->readTraitUse($tokens, $i + 1, $loadBearing);

                continue;
            }

            if ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS) {
                $i = $this->readNameList($tokens, $i + 1, $loadBearing);

                continue;
            }

            $i++;
        }

        return ['imports' => $imports, 'loadBearing' => $loadBearing];
    }

    /**
     * A top-level `use` statement, from just after the keyword to just after its `;`. Handles
     * `use A\B;`, `use A\B as C;`, `use function a\b;` (skipped — not a class), and `use A\B\{C, D as E};`.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    protected function readImport(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);
        $buffer = '';
        $alias = null;
        $group = null;
        $isClass = true;
        $expectAlias = false;

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === ';') {
                    $this->commitImport($group, $buffer, $alias, $isClass, $imports);

                    return $i + 1;
                }
                if ($token === '{') {
                    $group = $buffer;
                    $buffer = '';
                    $alias = null;
                } elseif ($token === '}') {
                    $this->commitImport($group, $buffer, $alias, $isClass, $imports);
                    $buffer = '';
                    $alias = null;
                    $group = null;
                } elseif ($token === ',') {
                    $this->commitImport($group, $buffer, $alias, $isClass, $imports);
                    $buffer = '';
                    $alias = null;
                    $expectAlias = false;
                } elseif ($token === '(') {
                    return $i + 1; // a closure's `use (...)` — nothing to import
                }
                $i++;

                continue;
            }

            if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
                $isClass = false;
            } elseif ($token[0] === T_AS) {
                $expectAlias = true;
            } elseif ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR
                || (defined('T_NAME_QUALIFIED') && ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NAME_RELATIVE))) {
                if ($expectAlias) {
                    $alias = $token[1];
                } else {
                    $buffer .= $token[1];
                }
            }

            $i++;
        }

        return $i;
    }

    /**
     * @param  array<string, string>  $imports
     */
    protected function commitImport(?string $group, string $buffer, ?string $alias, bool $isClass, array &$imports): void
    {
        if (! $isClass || trim($buffer) === '') {
            return;
        }

        $fqn = ltrim(($group ?? '').$buffer, '\\');
        if (! str_contains($fqn, '\\')) {
            return; // a root-namespace import supplies nothing this audit can resolve
        }

        $imports[$alias ?? substr((string) strrchr('\\'.$fqn, '\\'), 1)] = $fqn;
    }

    /**
     * A trait `use` inside a class body: `use A, B { ... }` or `use A;`. Every name is load-bearing —
     * PHP resolves a trait at class-declaration time.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, true>  $loadBearing
     */
    protected function readTraitUse(array $tokens, int $i, array &$loadBearing): int
    {
        return $this->readNameList($tokens, $i, $loadBearing);
    }

    /**
     * Collect the leading identifier of every name in a comma-separated list, up to `;`, `{` or the end
     * of the clause. The **leading** segment is what an import binds, so `use Foo\Bar` in a class body
     * and `extends Foo` both key on `Foo`.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, true>  $loadBearing
     */
    protected function readNameList(array $tokens, int $i, array &$loadBearing): int
    {
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === ';' || $token === '{' || $token === '(') {
                    return $i; // leave the brace for the depth walk to count
                }
                $i++;

                continue;
            }

            if ($token[0] === T_STRING
                || (defined('T_NAME_QUALIFIED') && ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED))) {
                $head = explode('\\', ltrim($token[1], '\\'))[0];
                if ($head !== '') {
                    $loadBearing[strtolower($head)] = true;
                }
            }

            $i++;
        }

        return $i;
    }

    /**
     * Does this manifest declare a `classmap` autoload — the one that defeats path arithmetic?
     *
     * **`files` is deliberately not in this test.** It includes a bootstrap file at startup and resolves
     * no class by name, so it cannot supply a missing class; counting it silenced this audit's own
     * motivating instance (see the class docblock).
     */
    protected function declaresOpaqueAutoload(array $manifest): bool
    {
        foreach (['autoload', 'autoload-dev'] as $block) {
            if (! empty($manifest[$block]['classmap'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $block
     * @return array<string, list<string>>
     */
    protected function psr4($block): array
    {
        $roots = [];
        if (! is_array($block) || ! is_array($block['psr-4'] ?? null)) {
            return $roots;
        }
        foreach ($block['psr-4'] as $prefix => $dirs) {
            if (! is_string($prefix) || $prefix === '') {
                continue;
            }
            foreach ((array) $dirs as $dir) {
                if (is_string($dir)) {
                    $roots[rtrim($prefix, '\\').'\\'][] = $dir;
                }
            }
        }

        return $roots;
    }

    /** @return list<string> */
    protected function psr0Prefixes(string $path): array
    {
        $manifest = $this->readJson($path.'/composer.json') ?? [];
        $prefixes = [];
        foreach (['autoload', 'autoload-dev'] as $block) {
            $map = $manifest[$block]['psr-0'] ?? null;
            if (! is_array($map)) {
                continue;
            }
            foreach (array_keys($map) as $prefix) {
                if (is_string($prefix) && $prefix !== '') {
                    $prefixes[] = rtrim($prefix, '\\').'\\';
                }
            }
        }

        return $prefixes;
    }

    /**
     * @param  array{fqn: string, alias: string, loadBearing: bool, owner: ?string}  $unresolved
     */
    protected function absent(string $package, string $path, string $file, array $unresolved): FixableFinding
    {
        $relative = str_starts_with($file, $path.'/') ? substr($file, strlen($path) + 1) : $file;

        $absence = $unresolved['owner'] === null
            ? 'No installed package declares a PSR-4 prefix covering that namespace, so the class is not present here at any path — the package supplying it is not installed. Repair: require and install it (or drop the import).'
            : sprintf(
                '%s IS installed and its PSR-4 prefix covers that namespace, but no file sits at the path PSR-4 demands under any root for that prefix — the package is present and the class is not, so it moved, was renamed, or was never written. Repair: point the import at where the class actually lives, or restore it. Note a prefix-ownership read (InstalledPackages::ownerOf()) reports this case as SATISFIED, which is why an audit built on ownership alone cannot see it.',
                $unresolved['owner'],
            );

        if (! $unresolved['loadBearing']) {
            return new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    '%s imports %s in %s and nothing supplies it. %s This is a DEFERRED fatal, not a live one: the alias is used only in positions that do not autoload — a `::class` string, an `instanceof`, a docblock, a type-hint or an attribute argument — and a `use` statement is compile-time aliasing that never loads a class on its own. It becomes a runtime error the moment something instantiates, extends or calls it. %s',
                    $package,
                    $unresolved['fqn'],
                    $relative,
                    $absence,
                    self::SCOPE_NOTE,
                )),
                OperationSuggestion::advisory(
                    sprintf('Supply %s for %s, or drop the unused import from %s.', $unresolved['fqn'], $package, $relative),
                    'rushing/laravel-surgeon',
                ),
            );
        }

        return new FixableFinding(
            Finding::fail(self::CHECK, sprintf(
                '%s imports %s in %s and USES IT IN A CLASS-LOAD POSITION (extends, implements, or a trait `use`), and nothing supplies it. %s PHP resolves a parent, an interface and a trait at class-DECLARATION time, before any of the class\'s own code runs, so there is no call site to guard, no `class_exists()` that helps and nothing for a test to assert against: the first thing that touches this class takes the process down. Measured on this estate — a discovery pass over a package carrying this shape killed the whole suite rather than reporting a failure, and coverage was only obtained by narrowing discovery away from the file. %s',
                $package,
                $unresolved['fqn'],
                $relative,
                $absence,
                self::SCOPE_NOTE,
            )),
            OperationSuggestion::advisory(
                sprintf('Supply %s for %s, or stop inheriting from it in %s — until one of the two, that class cannot be loaded at all.', $unresolved['fqn'], $package, $relative),
                'rushing/laravel-surgeon',
            ),
        );
    }

    /**
     * @param  list<FixableFinding>  $findings
     */
    protected function summary(int $checked, array $findings): FixableFinding
    {
        $fatal = 0;
        $deferred = 0;
        foreach ($findings as $finding) {
            if ($finding->finding->status === \Rushing\Doctor\DoctorStatus::Fail) {
                $fatal++;
            } else {
                $deferred++;
            }
        }

        if ($fatal === 0 && $deferred === 0) {
            return new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                sprintf('Every `use` import in the %d package `src/` tree(s) reachable from this root resolves to a file on disk. %s', $checked, self::SCOPE_NOTE),
            ));
        }

        return new FixableFinding(Finding::pass(
            self::CHECK.'.partial',
            sprintf(
                'Checked %d package `src/` tree(s) reachable from this root: %d import(s) used in a class-load position resolve to nothing (reported as Fail — those classes cannot be loaded at all) and %d resolve to nothing but are only referenced in non-loading positions (Warn — deferred). %s',
                $checked,
                $fatal,
                $deferred,
                self::SCOPE_NOTE,
            ),
        ));
    }

    /** @return list<string> */
    protected function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<string, mixed>|null */
    protected function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
