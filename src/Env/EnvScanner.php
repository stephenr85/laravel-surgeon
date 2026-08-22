<?php

namespace Rushing\Surgeon\Env;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds the environment variables and config keys a tree literally reads.
 *
 * Nothing in Laravel answers this. `config:show` prints the *values* of one config file (and would
 * spray secrets doing it), `env` prints `APP_ENV` and stops, and `vlucas/phpdotenv` parses `.env`
 * without ever enumerating what reads it — Symfony's `debug:dotenv` has no Laravel counterpart. The
 * only source of truth for "what does this codebase actually read" is the call sites, so this reads
 * them.
 *
 * **Token-level, not regex-level.** The estate settled this in
 * `Splicewire\Beam\Doctor\Support\ConfigKeyScanner`: a `config('beam-mdx.content_path')` inside a
 * docblock explaining an old key is prose, not a read, and a text match reports the file that
 * *documents* a fix as the file that needs one. `token_get_all()` draws the line where PHP does, and
 * comments never reach the collector because only `T_STRING` tokens are considered.
 *
 * Only a **literal first argument** is read. `env($name)` and `config("beam.{$domain}.path")` are
 * invisible. That is the accuracy ceiling and it is worth stating plainly: this inventory is a
 * **floor, not a census** — everything it lists is really read, but a tree that builds key names
 * dynamically reads more than it can show. Guessing at an interpolated key would produce the false
 * entries that get a report ignored.
 *
 * ## Values are never read
 * It collects variable **names** from call sites and never resolves them, so nothing secret-derived
 * can reach stdout, a CI log, or an MCP response.
 */
class EnvScanner
{
    /**
     * Directory names never descended into. Dependency and build trees read their own variables,
     * which are not this application's configuration surface.
     *
     * @var list<string>
     */
    public const PRUNED = ['vendor', 'node_modules', '.git', '.idea', 'storage', 'build', 'dist', '.surgeon'];

    /**
     * Config-facade methods that take a key as their first argument. Laravel 11's typed getters are
     * included; without them a `Config::string('a.b')` read would be invisible.
     *
     * @var list<string>
     */
    private const CONFIG_METHODS = ['get', 'has', 'string', 'integer', 'float', 'boolean', 'array', 'collection'];

    /**
     * Scan a tree. Returns env names and config keys, each mapped to the `path:line` sites reading
     * them — paths relative to the root, everything sorted, so two runs over the same tree agree.
     *
     * @param  list<string>  $prune  extra directory names to skip, on top of {@see PRUNED}
     * @return array{env: array<string, list<string>>, config: array<string, list<string>>}
     */
    public function scanTree(string $root, array $prune = []): array
    {
        $root = rtrim($root, '/');
        $pruned = array_values(array_unique([...self::PRUNED, ...$prune]));

        $env = [];
        $config = [];

        foreach ($this->phpFiles($root, $pruned) as $absolute) {
            $source = @file_get_contents($absolute);

            if ($source === false) {
                continue;
            }

            // Cheap pre-filter: most files name neither helper.
            if (! str_contains($source, 'env') && ! str_contains($source, 'onfig')) {
                continue;
            }

            $relative = substr($absolute, strlen($root) + 1);
            $found = self::scanSource($source);

            foreach ($found['env'] as $name => $lines) {
                foreach ($lines as $line) {
                    $env[$name][] = $relative.':'.$line;
                }
            }

            foreach ($found['config'] as $key => $lines) {
                foreach ($lines as $line) {
                    $config[$key][] = $relative.':'.$line;
                }
            }
        }

        return [
            'env' => self::normalize($env),
            'config' => self::normalize($config),
        ];
    }

    /**
     * The symbols read in one file's source, mapped to the lines reading them.
     *
     * Recognized shapes, all requiring a literal string first argument:
     *   `env('NAME')` · `Env::get('NAME')` · `config('a.b')` · `Config::get('a.b')` and its typed
     *   siblings.
     *
     * `get`/`has`/`string`/… are only a config read when reached through the `Config` facade —
     * otherwise every `$collection->get('a.b')` in the estate becomes an entry.
     *
     * @return array{env: array<string, list<int>>, config: array<string, list<int>>}
     */
    public static function scanSource(string $source): array
    {
        $tokens = @token_get_all($source);
        $env = [];
        $config = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = $token[1];
            $line = $token[2];

            if ($name === 'env' && self::isFreeCall($tokens, $i)) {
                $literal = self::firstStringArgument($tokens, $i);

                if ($literal !== null) {
                    $env[$literal][] = $line;
                }

                continue;
            }

            if ($name === 'config' && self::isFreeCall($tokens, $i)) {
                $literal = self::firstStringArgument($tokens, $i);

                if ($literal !== null) {
                    $config[$literal][] = $line;
                }

                continue;
            }

            if ($name === 'get' && self::precededByFacade($tokens, $i, 'Env')) {
                $literal = self::firstStringArgument($tokens, $i);

                if ($literal !== null) {
                    $env[$literal][] = $line;
                }

                continue;
            }

            if (in_array($name, self::CONFIG_METHODS, true) && self::precededByFacade($tokens, $i, 'Config')) {
                $literal = self::firstStringArgument($tokens, $i);

                if ($literal !== null) {
                    $config[$literal][] = $line;
                }
            }
        }

        return [
            'env' => self::sortLines($env),
            'config' => self::sortLines($config),
        ];
    }

    /**
     * Every PHP file under the root, pruned dirs skipped.
     *
     * The prune decision is a *descent* filter, not a post-hoc path match: `vendor/` and
     * `node_modules/` are the two largest trees in an estate, and a walk that enumerates them only to
     * discard them is the difference between a report that feels instant and one nobody runs twice.
     *
     * @param  list<string>  $prune
     * @return list<string>
     */
    private function phpFiles(string $root, array $prune): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $filtered = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $item) use ($prune): bool {
                if ($item->isLink()) {
                    return false;
                }

                return ! ($item->isDir() && in_array($item->getFilename(), $prune, true));
            },
        );

        $paths = [];

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator($filtered) as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $paths[] = $item->getPathname();
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * The literal string passed as the first argument to the call at `$i`, or null when the next
     * meaningful token is not `(` immediately followed by a quoted string — which covers `env()`,
     * `env($name)`, and any interpolated `"…{$x}…"` (PHP tokenizes those as several tokens, never as
     * one `T_CONSTANT_ENCAPSED_STRING`).
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function firstStringArgument(array $tokens, int $i): ?string
    {
        $next = self::nextMeaningful($tokens, $i);

        if ($next === null || $tokens[$next] !== '(') {
            return null;
        }

        $arg = self::nextMeaningful($tokens, $next);

        if ($arg === null || ! is_array($tokens[$arg]) || $tokens[$arg][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $literal = trim($tokens[$arg][1], "'\"");

        return $literal === '' ? null : $literal;
    }

    /**
     * Whether the token at `$i` is the global helper and not something that merely shares its name:
     * `$this->config('x')`, `Foo::env('x')` and `function env(...)` are all excluded. Without this a
     * class with its own `env()` accessor contributes phantom variable names to the inventory.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function isFreeCall(array $tokens, int $i): bool
    {
        $previous = self::previousMeaningful($tokens, $i);

        if ($previous === null || ! is_array($tokens[$previous])) {
            return true;
        }

        return ! in_array($tokens[$previous][0], [
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_FUNCTION,
        ], true);
    }

    /**
     * Whether the token at `$i` is a static call on the named facade — `Facade::method(`. The class
     * token is matched on its last segment so a fully-qualified
     * `\Illuminate\Support\Facades\Config` counts, and an aliased import needs no resolving.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function precededByFacade(array $tokens, int $i, string $facade): bool
    {
        $operator = self::previousMeaningful($tokens, $i);

        if ($operator === null || ! is_array($tokens[$operator]) || $tokens[$operator][0] !== T_DOUBLE_COLON) {
            return false;
        }

        $class = self::previousMeaningful($tokens, $operator);

        if ($class === null || ! is_array($tokens[$class])) {
            return false;
        }

        if (! in_array($tokens[$class][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $segments = explode('\\', $tokens[$class][1]);

        return end($segments) === $facade;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            if (self::isMeaningful($tokens[$j])) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function previousMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (self::isMeaningful($tokens[$j])) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private static function isMeaningful(array|string $token): bool
    {
        if (! is_array($token)) {
            return true;
        }

        return ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param  array<string, list<int>>  $found
     * @return array<string, list<int>>
     */
    private static function sortLines(array $found): array
    {
        foreach ($found as $symbol => $lines) {
            $lines = array_values(array_unique($lines));
            sort($lines);
            $found[$symbol] = $lines;
        }

        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * @param  array<string, list<string>>  $sites
     * @return array<string, list<string>>
     */
    private static function normalize(array $sites): array
    {
        foreach ($sites as $symbol => $found) {
            $found = array_values(array_unique($found));
            sort($found, SORT_STRING);
            $sites[$symbol] = $found;
        }

        ksort($sites, SORT_STRING);

        return $sites;
    }
}
