<?php

namespace Rushing\Surgeon\Env;

/**
 * Discovers the dotenv files in a repo and classifies each by the role it plays at boot.
 *
 * ## Laravel REPLACES; Symfony merges
 * This is the fact the whole multi-file view turns on, and it is the opposite of what anyone
 * arriving from Symfony expects. `Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::
 * checkForSpecificEnvironmentFile()` calls `$app->loadEnvironmentFrom($file)`, and that setter
 * assigns `$this->environmentFile = $file` — it *swaps the single file*. There is no layering, no
 * `.env.local` overlaying `.env`, no per-variable fallback.
 *
 * So under `APP_ENV=testing` with a `.env.testing` present, `.env` is **not read at all**, and every
 * variable that lives only in `.env` is simply absent — `env('STRIPE_KEY')` returns its default, or
 * null. That failure has no error message and no stack trace; it looks like a feature quietly not
 * working in one environment. Symfony's `debug:dotenv` cannot express it because Symfony's own
 * semantics are a merge cascade, so the question does not arise there.
 *
 * Only the `.env*` family is read here. `phpunit.xml`'s `<env>` elements are a real fourth source
 * for the test environment and are deliberately out of scope for now — they are XML, they carry a
 * `force` flag with its own precedence rule, and folding them in without modelling that faithfully
 * would produce a confident wrong answer.
 */
class EnvFileSet
{
    /**
     * @param  list<EnvFile>  $files
     */
    public function __construct(public array $files) {}

    /**
     * Discover every `.env*` file at the root.
     *
     * `$examplePath` / `$basePath` let a caller point the documentation and base roles somewhere
     * else; anything else matching `.env.*` is an environment file named by its suffix.
     */
    public static function discover(string $root, ?string $examplePath = null, ?string $basePath = null): self
    {
        $root = rtrim($root, '/');
        $examplePath ??= $root.'/.env.example';
        $basePath ??= $root.'/.env';

        $paths = [$examplePath, $basePath, ...self::siblings($root)];
        $seen = [];
        $files = [];

        foreach ($paths as $path) {
            if (isset($seen[$path]) || ! is_file($path)) {
                continue;
            }

            $seen[$path] = true;
            $files[] = self::classify($path, $examplePath, $basePath);
        }

        usort($files, static fn (EnvFile $a, EnvFile $b) => [self::order($a), $a->name] <=> [self::order($b), $b->name]);

        return new self($files);
    }

    /** @return list<EnvFile> the files actually loaded at boot — base + per-environment, no documentation */
    public function loadable(): array
    {
        return array_values(array_filter($this->files, fn (EnvFile $f) => ! $f->isDocumentation()));
    }

    /** @return list<EnvFile> the `APP_ENV`-selected files, e.g. `.env.testing` */
    public function environments(): array
    {
        return array_values(array_filter($this->files, fn (EnvFile $f) => $f->isEnvironment()));
    }

    public function documentation(): ?EnvFile
    {
        foreach ($this->files as $file) {
            if ($file->isDocumentation()) {
                return $file;
            }
        }

        return null;
    }

    public function named(string $name): ?EnvFile
    {
        foreach ($this->files as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }

        return null;
    }

    /** @return list<string> the names of the files declaring this variable, in role order */
    public function declaring(string $variable): array
    {
        $names = [];

        foreach ($this->files as $file) {
            if ($file->declares($variable)) {
                $names[] = $file->name;
            }
        }

        return $names;
    }

    /**
     * Every variable name the repo *declares*, sorted — the documentation and environment files, but
     * deliberately **not** the local `.env`.
     *
     * `.env` is one machine's state, not a statement about the application. Personal files accumulate
     * junk (an editor's token, a colleague's tunnel URL), and a name that appears only there is
     * nobody's problem; folding it in would fill the report with rows no one can act on. `.env.example`
     * and `.env.testing` are the opposite — committed, shared, and meant as declarations. A local-only
     * name still shows in its file's declare count and in `--matrix`; it just does not earn a row of
     * its own.
     *
     * @return list<string>
     */
    public function declaredKeys(): array
    {
        $keys = [];

        foreach ($this->files as $file) {
            if ($file->role === EnvFile::BASE) {
                continue;
            }

            $keys = [...$keys, ...$file->keys];
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Other `.env.*` files beside the root's `.env`.
     *
     * Editor and tooling residue (`.env.bak`, `.env.save~`, `.env.testing.orig`) is skipped — it is
     * not something `APP_ENV` can ever select, and reporting `.env.bak` as an environment named
     * "bak" would be a confident lie.
     *
     * @return list<string>
     */
    private static function siblings(string $root): array
    {
        $paths = glob($root.'/.env.*') ?: [];
        $ignored = ['bak', 'save', 'orig', 'backup', 'swp', 'tmp', 'old'];

        $kept = [];

        foreach ($paths as $path) {
            $suffix = substr(basename($path), 5);

            if ($suffix === '' || str_ends_with($path, '~') || in_array(strtolower($suffix), $ignored, true)) {
                continue;
            }

            $kept[] = $path;
        }

        sort($kept, SORT_STRING);

        return $kept;
    }

    private static function classify(string $path, string $examplePath, string $basePath): EnvFile
    {
        $name = basename($path);
        $keys = DotenvKeys::in($path);

        if ($path === $examplePath || str_ends_with($name, '.example')) {
            return new EnvFile($path, $name, EnvFile::DOCUMENTATION, null, $keys);
        }

        if ($path === $basePath) {
            return new EnvFile($path, $name, EnvFile::BASE, null, $keys);
        }

        return new EnvFile($path, $name, EnvFile::ENVIRONMENT, substr($name, 5), $keys);
    }

    private static function order(EnvFile $file): int
    {
        return match ($file->role) {
            EnvFile::DOCUMENTATION => 0,
            EnvFile::BASE => 1,
            default => 2,
        };
    }
}
