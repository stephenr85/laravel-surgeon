<?php

namespace Rushing\Surgeon\Env;

/**
 * Whether a repo has a Vite front end, and which env prefixes Vite exposes to it.
 *
 * ## Why a PHP tool cares
 * Laravel and Vite read the SAME `.env` files with OPPOSITE multi-file semantics, so one file is two
 * different things depending on who is reading it:
 *
 *  - **Laravel replaces.** `LoadEnvironmentVariables` swaps to `.env.{APP_ENV}` and never reads `.env`.
 *  - **Vite merges.** `getEnvFilesForMode()` returns `.env`, `.env.local`, `.env.{mode}`,
 *    `.env.{mode}.local`, and `loadEnv()` flat-maps all four into one object — later file wins per key,
 *    every key from `.env` survives unless overridden.
 *
 * So "missing from `.env.production`, therefore absent at boot" is true of `STRIPE_KEY` and false of
 * `VITE_APP_NAME` sitting on the next line. A report that states the Laravel rule flatly is wrong about
 * every front-end variable in the file.
 *
 * ## The prefix is not `VITE_` by definition
 * It is `envPrefix` in the Vite config, defaulting to `VITE_` — projects routinely widen it (a Laravel
 * shop that wants `APP_NAME` on the client sets `envPrefix: ['VITE_', 'APP_']`). Hardcoding `VITE_`
 * would silently misclassify exactly the variables someone went out of their way to expose.
 *
 * The config is read with a regex, not a JS parser, which is a real limit: a computed `envPrefix`
 * (`envPrefix: prefixes`) or one behind a conditional is invisible, and the default is used instead.
 * {@see $prefixSource} records which happened so the report can say so rather than implying certainty.
 * `envDir` is likewise not honoured — a repo relocating its dotenv files away from the project root is
 * rare enough that guessing wrong is worse than the omission.
 */
class ViteEnv
{
    public const DEFAULT_PREFIXES = ['VITE_'];

    /**
     * @param  bool  $present  whether this repo has a Vite front end at all
     * @param  list<string>  $prefixes  the env prefixes Vite exposes to client code
     * @param  string|null  $configPath  the vite config that was read, if any
     * @param  string  $prefixSource  `config` when envPrefix was found, `default` otherwise
     */
    public function __construct(
        public bool $present,
        public array $prefixes = self::DEFAULT_PREFIXES,
        public ?string $configPath = null,
        public string $prefixSource = 'default',
    ) {}

    /**
     * Inspect a repo root. A root with no Vite gets `present: false` and no variable is ever treated
     * as merge-semantics — a backend-only package must not inherit a front-end tool's rules.
     */
    public static function discover(string $root): self
    {
        $root = rtrim($root, '/');
        $configPath = self::configPath($root);

        if ($configPath === null && ! self::declaredInPackageJson($root)) {
            return new self(present: false);
        }

        $prefixes = $configPath === null ? null : self::prefixesIn($configPath);

        return new self(
            present: true,
            prefixes: $prefixes ?? self::DEFAULT_PREFIXES,
            configPath: $configPath,
            prefixSource: $prefixes === null ? 'default' : 'config',
        );
    }

    /**
     * Whether Vite exposes this variable to the client — and therefore whether it follows Vite's
     * merge semantics rather than Laravel's swap.
     */
    public function exposes(string $variable): bool
    {
        if (! $this->present) {
            return false;
        }

        foreach ($this->prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($variable, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'present' => $this->present,
            'prefixes' => $this->present ? $this->prefixes : [],
            'prefix_source' => $this->prefixSource,
            'config' => $this->configPath === null ? null : basename($this->configPath),
        ];
    }

    private static function configPath(string $root): ?string
    {
        foreach (['ts', 'js', 'mts', 'mjs', 'cts', 'cjs'] as $extension) {
            $path = $root.'/vite.config.'.$extension;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A repo can depend on vite without a config file at the root (a workspace, a preset). Presence is
     * enough to switch semantics on; the prefixes then fall back to the documented default.
     */
    private static function declaredInPackageJson(string $root): bool
    {
        $path = $root.'/package.json';

        if (! is_file($path)) {
            return false;
        }

        $json = json_decode((string) @file_get_contents($path), true);

        if (! is_array($json)) {
            return false;
        }

        return isset($json['dependencies']['vite']) || isset($json['devDependencies']['vite']);
    }

    /**
     * The `envPrefix` declared in a vite config, or null when it declares none (or declares one this
     * cannot statically read).
     *
     * @return list<string>|null
     */
    private static function prefixesIn(string $path): ?array
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            return null;
        }

        // Array form first — `envPrefix: ['VITE_', 'APP_']`. Checked before the string form so the
        // first quoted element is not mistaken for the whole declaration.
        if (preg_match('/envPrefix\s*:\s*\[([^\]]*)\]/', $source, $matches) === 1) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches[1], $found);

            return $found[1] === [] ? null : array_values($found[1]);
        }

        if (preg_match('/envPrefix\s*:\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1) {
            return [$matches[1]];
        }

        return null;
    }
}
