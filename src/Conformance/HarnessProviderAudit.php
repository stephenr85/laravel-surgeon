<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **a package's test harness never boots a service
 * provider its own `src/` depends on.** The suite is green and proves less than it looks like it proves.
 *
 * **The mechanism, and why nothing else in the estate sees it.** Testbench does not auto-discover. A
 * package harness boots exactly what `getPackageProviders()` names, while the package's `src/` freely
 * imports anything in its `require` block. The two lists are allowed to disagree, and before this audit
 * **nothing compared them** — no `surgeon:*` rule, no `*:doctor`, no test anywhere read
 * `getPackageProviders()`. A host is immune by construction (Laravel's package discovery boots every
 * `extra.laravel.providers` entry in `installed.json`), so the defect is exclusive to package suites and
 * invisible from the one place people look.
 *
 * **The symptom is never a failing test, which is what makes the class expensive.** Four measured
 * instances, four unrelated-looking surfaces, four separate sessions to find:
 *  - `splicewire/laravel-beam` omitted `PopcornServiceProvider`, so an auto-resolvable `RegistryIndex`
 *    was constructed fresh per `make()` and everything describing into it wrote to an object nobody
 *    read — green suite, empty index. Booting it fixed five target tests **and four unrelated
 *    pre-existing failures**.
 *  - `BeamNotificationsServiceProvider` was never booted in its harness, so the only binding of the
 *    submission notifier did not exist and every notify path in the host was inert. Report-and-swallow
 *    made an unregistered listener indistinguishable from a throwing one.
 *  - `splicewire/tower` omitted `LaravelDataServiceProvider`, so `config('data')` was null and none of
 *    its **81** input DTOs could be hydrated by its own suite — a fatal, not a failure, which is why the
 *    sweep that authored them could only verify them with `php -l`.
 *  - Thirteen sibling packages carried that same specific omission at once.
 *
 * **Booting the provider is necessary, not sufficient — and the sufficient half is deliberately NOT
 * audited here.** The tower fix also had to mirror the host's one semantic config delta
 * (laravel-data's `name_mapping_strategy.input`) or the harness was a false green. That half cannot be
 * checked statically: which host is authoritative for a package's config is not a property of the
 * package, and a package with two hosts has two answers. The estate's answer to the config half is a
 * test in the package pinning the delta, not a rule; this audit's scope note says so out loud rather
 * than letting a clean line imply more than it earned.
 *
 * **The false-positive control is the `use` statement, and it is load-bearing.** A dependency whose
 * provider the package's `src/` never touches is composer behaving correctly, not a hole — so a package
 * is only in scope once some file under `src/` **imports** a class under one of its PSR-4 prefixes. Measured on the thirteen: a raw text sweep flagged `rushing/laravel-surgeon` and
 * `rushing/laravel-codegen`, whose only mentions of the dependency are a docblock and a default
 * option string naming a base class for GENERATED code. Keying on `use` drops both and keeps all
 * eleven real ones.
 *
 * **Scope is resolved from the IMPORTS, not from the `require` block.** A package can consume a
 * namespace it never declares — the dependency arrives transitively, autoloads perfectly, and the
 * manifest says nothing; five of the thirteen packages this was first measured on were that shape, so a
 * require-scoped read under-reported by more than a third. `require` is still read, but only to stamp a
 * finding declared/undeclared, because the undeclared case carries a second repair.
 *
 * **One of a package's declared providers is enough.** A dependency may ship several; requiring the
 * harness to name all of them would flag correct harnesses, and the question this audit asks is whether
 * the dependency was booted at all.
 *
 * **Severity is Warn, not Fail, and this audit does not gate on its own.** It ships on the built-in
 * channel, which is advisory by contract — see {@see \Rushing\Surgeon\Operation\ConformanceSweep}. Warn
 * is honest about what a static read can know: the audit proves the two lists disagree, not that any
 * test is wrong. It nominates; the suite certifies. A host wanting a red build registers this class in
 * its own doctor manifest with `gate: true`.
 *
 * **Tier direction.** Surgeon is foundation-tier `rushing/*` and names no `splicewire/*` or
 * `schemastud/*` type. Every package it checks sits ABOVE it, and it reaches them the only legal way:
 * their manifests and source text read as DATA discovered at runtime, never as a compile dependency —
 * the same posture {@see UpstreamDtoAudit} and {@see UnsatisfiedNeighbourRequireAudit} already run in.
 * That is also why this is not a `*:doctor` check: a doctor is arm-scoped, and two of the thirteen
 * measured instances are `rushing/*` packages no arm doctor reaches.
 */
class HarnessProviderAudit implements SuggestsOperations
{
    public const CHECK = 'harness-provider-coverage';

    /** The one line the report always carries about what it did NOT check. */
    public const SCOPE_NOTE = 'Scope: `getPackageProviders()` reachability only, read as text across the package\'s own `tests/` tree — a base class inherited from ANOTHER package is not followed, and the CONFIG half is out of scope entirely (booting a provider is necessary, not sufficient; a harness may still diverge from its host\'s config and read as a false green). Reach is this root\'s own neighbours: a package not installed here is not seen at all, so a clean line is clean FOR THIS ROOT. And clean means no package the src imports ships a provider the harness never names, not that the harness matches its host.';

    /**
     * @param  string  $appRoot  the repo root whose `vendor/composer/installed.json` is read (the running host)
     */
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
                'No vendor/composer/installed.json in this repo — nothing is installed here, so no harness can be checked against it.',
            ))];
        }

        $findings = [];
        $checked = 0;
        $noSuite = [];

        foreach ($this->candidates($root, $installed) as $name => $path) {
            $manifest = $this->readJson($path.'/composer.json');
            if ($manifest === null || ! is_array($manifest['require'] ?? [])) {
                continue;
            }

            $requires = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
            $consumed = $this->consumedPackages($installed, $path, $name);
            $harness = $this->harnessText($path);

            // Not a testbench package — skipped, and the two reasons are NOT the same finding.
            //
            //  - PHP under `tests/` that never mentions testbench is a host (its providers come from
            //    package discovery, so a "missing provider" here would be a pure false positive) or a
            //    deliberately container-free package suite. Silent: nothing is wrong.
            //  - NO PHP under `tests/` at all, in a package whose src/ imports something this audit
            //    would have checked, is the state that cannot be un-masked. It is NAMED in the summary,
            //    because a silent skip there is how the worst instance stayed invisible — ten declared
            //    DTOs and zero test files.
            if ($harness === null) {
                if ((! is_dir($path.'/tests') || $this->phpFiles($path.'/tests') === []) && $consumed !== []) {
                    $noSuite[] = $name;
                }

                continue;
            }

            $checked++;

            foreach ($consumed as $dependency => $providers) {
                if ($this->namesAny($harness, $providers)) {
                    continue;
                }

                $findings[] = $this->uncovered($name, $path, $dependency, $providers, isset($requires[$dependency]));
            }
        }

        $findings[] = $this->summary($checked, count($findings), $noSuite);

        return $findings;
    }

    /**
     * Every package whose harness is this root's business: the path-installed neighbours (live source
     * the host runs against) plus the running root itself, so the audit answers in a package checkout
     * as well as in a host. Non-path installs are excluded — a resolved artifact's harness is not this
     * root's to repair.
     *
     * @return array<string, string> package name => absolute checkout path
     */
    protected function candidates(string $root, InstalledPackages $installed): array
    {
        $candidates = [];

        $manifest = $this->readJson($root.'/composer.json');
        $selfName = is_string($manifest['name'] ?? null) ? $manifest['name'] : $root;
        $candidates[$selfName] = $root;

        foreach ($installed->installedFromPath() as $package) {
            $candidates[$package['name']] = $package['path'];
        }

        return $candidates;
    }

    /**
     * The package's whole `tests/` tree as one string, or **null when it is not a testbench harness**
     * — no PHP under `tests/`, or PHP that never mentions testbench.
     *
     * One blob rather than the base class alone on purpose: a harness may split `getPackageProviders()`
     * across several base classes in the same package, and this audit's question is only "is the
     * provider named anywhere the suite boots", which text answers without parsing inheritance.
     *
     * **The testbench marker is what keeps a HOST out of the report.** A host boots every installed
     * package's `extra.laravel.providers` through Laravel's own discovery, so it can never carry this
     * defect and every finding against one would be false. The audit is running IN a host most of the
     * time; without this gate the loudest thing it would print is nonsense about its own root.
     */
    protected function harnessText(string $path): ?string
    {
        $dir = $path.'/tests';
        if (! is_dir($dir)) {
            return null;
        }

        $text = '';
        foreach ($this->phpFiles($dir) as $file) {
            $text .= (string) @file_get_contents($file);
        }

        if (! str_contains($text, 'Testbench') && ! str_contains($text, 'getPackageProviders')) {
            return null;
        }

        return $text;
    }

    /**
     * Every installed package this one's `src/` actually IMPORTS **and** that ships a Laravel service
     * provider, mapped to that provider list — the exact set a host boots for it automatically and a
     * testbench harness does not.
     *
     * **Resolution runs from the imports, not from the `require` block, and that is deliberate.** An
     * earlier revision walked `require` and intersected it with the imports; measured against the real
     * estate it under-reported by more than a third, because a package can consume a namespace it never
     * declares — the dependency arrives transitively and autoloads perfectly, so `src/` compiles and the
     * manifest says nothing. Five of the thirteen packages the defect was first measured on were exactly
     * that shape. Reading the imports catches both, and PSR-4 longest-prefix ownership
     * ({@see InstalledPackages::ownerOf()}) is what turns an import back into a package name. The
     * `require` block is still read — but only to STAMP a finding as declared or undeclared, because the
     * two carry different second repairs.
     *
     * @return array<string, list<string>> package name => its declared providers
     */
    protected function consumedPackages(InstalledPackages $installed, string $path, string $self): array
    {
        $consumed = [];

        foreach ($this->importedNamespaces($path) as $fqn) {
            $owner = $installed->ownerOf($fqn);
            if ($owner === null || $owner === $self || array_key_exists($owner, $consumed)) {
                continue;
            }

            $entry = $this->installedEntry($installed, $owner);
            $providers = $entry === null ? [] : $this->declaredProviders($entry['path']);
            if ($providers === []) {
                continue;
            }

            $consumed[$owner] = $providers;
        }

        ksort($consumed);

        return $consumed;
    }

    /**
     * Every FQN the package's `src/` imports with a `use` statement. This — not a text sweep — is the
     * false-positive control: a docblock or a class-string naming a dependency does not load it.
     *
     * @return list<string>
     */
    protected function importedNamespaces(string $path): array
    {
        $dir = $path.'/src';
        if (! is_dir($dir)) {
            return [];
        }

        $imports = [];
        foreach ($this->phpFiles($dir) as $file) {
            $source = (string) @file_get_contents($file);
            if (preg_match_all('/^\s*use\s+(?:function\s+|const\s+)?([A-Za-z0-9_\\\\]+)/m', $source, $matches)) {
                foreach ($matches[1] as $fqn) {
                    $imports[] = ltrim($fqn, '\\');
                }
            }
        }

        return array_values(array_unique($imports));
    }

    /**
     * The service providers a dependency declares for Laravel's package discovery — the exact list a
     * HOST boots automatically and a testbench harness does not.
     *
     * @return list<string>
     */
    protected function declaredProviders(string $path): array
    {
        $manifest = $this->readJson($path.'/composer.json');
        $providers = $manifest['extra']['laravel']['providers'] ?? null;
        if (! is_array($providers)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($p) => is_string($p) ? ltrim($p, '\\') : null, $providers),
            fn ($p) => is_string($p) && $p !== '',
        ));
    }

    /**
     * Is at least ONE of the dependency's providers named in the harness — by FQN, or by the short name
     * an import leaves behind at the call site?
     *
     * @param  list<string>  $providers
     */
    protected function namesAny(string $harness, array $providers): bool
    {
        foreach ($providers as $provider) {
            if (str_contains($harness, $provider)) {
                return true;
            }
            $short = substr((string) strrchr('\\'.$provider, '\\'), 1);
            if ($short !== '' && str_contains($harness, $short)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $providers
     */
    protected function uncovered(string $package, string $path, string $required, array $providers, bool $declared): FixableFinding
    {
        $provider = $providers[0];
        $short = substr((string) strrchr('\\'.$provider, '\\'), 1);

        $detail = sprintf(
            '%s imports %s in its `src/` but its `tests/` never names %s — testbench does not auto-discover, so the harness boots exactly what `getPackageProviders()` lists and this provider is not on it. A HOST boots it automatically through package discovery, so the two disagree and only the package suite is affected: it runs against a container missing that provider\'s bindings and config, and the failure mode is a green run that proves less than it looks like it proves (or a fatal on first use, never an assertion). Repair: add `%s::class` to `getPackageProviders()` in %s/tests.%s %s',
            $package,
            $required,
            $provider,
            $short,
            $path,
            $declared
                ? ''
                : sprintf(' Second repair, and the reason this one was invisible to a manifest read: %s does NOT declare %s in its own `require` — it reaches the namespace transitively, which autoloads perfectly and says nothing. Declare it.', $package, $required),
            self::SCOPE_NOTE,
        );

        return new FixableFinding(
            Finding::warn(self::CHECK, $detail),
            OperationSuggestion::advisory(
                sprintf('Add %s::class to %s\'s `getPackageProviders()` so its harness boots the %s it already imports.', $short, $package, $required),
                'rushing/laravel-surgeon',
            ),
        );
    }

    /**
     * @param  list<string>  $noSuite
     */
    protected function summary(int $checked, int $found, array $noSuite): FixableFinding
    {
        $skipped = $noSuite === []
            ? ''
            : sprintf(
                ' %d package(s) hold no PHP under `tests/` and were not checked — there is no harness to hold a hole, and equally nothing to un-mask: %s.',
                count($noSuite),
                implode(', ', $noSuite),
            );

        if ($found > 0) {
            return new FixableFinding(Finding::pass(
                self::CHECK.'.partial',
                sprintf('Checked %d package harness(es) against the provider-shipping packages their `src/` imports; %d provider gap(s) reported above.%s %s', $checked, $found, $skipped, self::SCOPE_NOTE),
            ));
        }

        return new FixableFinding(Finding::pass(
            self::CHECK.'.clean',
            sprintf('Every provider-shipping package imported by the %d checked harness(es) is named in that package\'s own `tests/`.%s %s', $checked, $skipped, self::SCOPE_NOTE),
        ));
    }

    /**
     * @return array{name: string, roots: array<string, string>, path: string, source: ?string, provides: list<string>}|null
     */
    protected function installedEntry(InstalledPackages $installed, string $name): ?array
    {
        $name = strtolower($name);
        foreach ($installed->packages as $package) {
            if (strtolower($package['name']) === $name) {
                return $package;
            }
        }

        return null;
    }

    /** Composer's own non-package requires: the runtime itself, extensions, libraries, and composer's APIs. */
    protected function isPlatform(string $name): bool
    {
        $name = strtolower($name);

        return $name === 'php'
            || ! str_contains($name, '/')
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || str_starts_with($name, 'composer-');
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
