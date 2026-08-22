<?php

namespace Rushing\Surgeon\Fingerprint;

/**
 * Finds the config keys and environment variable names a tree literally reads — the *configuration
 * surface* half of a {@see Fingerprint}.
 *
 * **Token-level, not regex-level.** The estate settled this in
 * `Splicewire\Beam\Doctor\Support\ConfigKeyScanner`: a `config('beam-mdx.content_path')` inside a
 * docblock explaining an old key is prose, not a read, and a text match reports the file that
 * *documents* a fix as the file that needs one. `token_get_all()` draws the line where PHP does, and
 * comments never reach the collector because only `T_STRING` tokens are considered.
 *
 * Only a **literal first argument** is read. `config($key)` and `env("APP_{$suffix}")` are invisible —
 * a deliberate false negative, because a dynamic key cannot be statically resolved and guessing at one
 * produces exactly the noise that gets a check switched off.
 *
 * ## What this deliberately does not do
 * It collects env var **names**, never **values**. A digest over values would carry secret-derived
 * material into logs, CI output and MCP tool responses, and it would answer a question nobody asked:
 * the drift worth detecting is "does this tree read a variable it did not read before", which names
 * answer on their own.
 *
 * Unlike its beam ancestor this keeps the **full** key rather than the root (a fingerprint is
 * interested in `surgeon.mcp.granted_abilities`, not in `surgeon`) and keeps single-segment reads
 * (`config('surgeon')` is a real read of a real root), because neither of the narrowings that audit
 * makes — it hunts renamed roots — applies to an identity digest.
 */
class SymbolScanner
{
    /**
     * Config-facade methods that take a key as their first argument. Laravel 11's typed getters are
     * included; without them a `Config::string('a.b')` read would be invisible.
     *
     * @var list<string>
     */
    private const CONFIG_METHODS = ['get', 'has', 'string', 'integer', 'float', 'boolean', 'array', 'collection'];

    /**
     * The config keys and env names read across a set of PHP files.
     *
     * @param  list<array{path: string, absolute: string}>  $files  relative display path + path to read
     * @return array{config: array<string, list<string>>, env: array<string, list<string>>}
     *                                                                                      symbol => `path:line` sites, everything sorted for a byte-identical re-run
     */
    public static function scan(array $files): array
    {
        $config = [];
        $env = [];

        foreach ($files as $file) {
            $source = @file_get_contents($file['absolute']);

            if ($source === false) {
                continue;
            }

            // Cheap pre-filter: the overwhelming majority of files name neither.
            if (! str_contains($source, 'config') && ! str_contains($source, 'env') && ! str_contains($source, 'Config')) {
                continue;
            }

            $found = self::scanSource($source);

            foreach ($found['config'] as $key => $lines) {
                foreach ($lines as $line) {
                    $config[$key][] = $file['path'].':'.$line;
                }
            }

            foreach ($found['env'] as $name => $lines) {
                foreach ($lines as $line) {
                    $env[$name][] = $file['path'].':'.$line;
                }
            }
        }

        return [
            'config' => self::normalize($config),
            'env' => self::normalize($env),
        ];
    }

    /**
     * The symbols read in one file's source, mapped to the lines reading them.
     *
     * Recognized shapes, all requiring a literal string first argument:
     *   `config('a.b')` · `Config::get('a.b')` and its typed siblings · `env('NAME')` ·
     *   `Env::get('NAME')`.
     *
     * `get`/`has`/`string`/… are only a config read when reached through the `Config` facade —
     * otherwise every `$collection->get('a.b')` in the estate becomes a finding.
     *
     * @return array{config: array<string, list<int>>, env: array<string, list<int>>}
     */
    public static function scanSource(string $source): array
    {
        $tokens = @token_get_all($source);
        $config = [];
        $env = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = $token[1];
            $line = $token[2];

            if ($name === 'config' && self::isFreeCall($tokens, $i)) {
                $literal = self::firstStringArgument($tokens, $i);

                if ($literal !== null) {
                    $config[$literal][] = $line;
                }

                continue;
            }

            if ($name === 'env' && self::isFreeCall($tokens, $i)) {
                $literal = self::firstStringArgument($tokens, $i);

                if ($literal !== null) {
                    $env[$literal][] = $line;
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
            'config' => self::sortLines($config),
            'env' => self::sortLines($env),
        ];
    }

    /**
     * The digest over a scanned surface: one hash of the sorted `config a.b` / `env NAME` lines.
     *
     * **Sites are excluded on purpose.** Moving a `config()` read from line 12 to line 40 changes the
     * file's content hash and nothing else has changed about the tree's configuration surface; folding
     * sites in here would make the symbols digest a strictly-worse copy of the file digest instead of
     * an independent signal.
     *
     * @param  array{config: array<string, list<string>>, env: array<string, list<string>>}  $symbols
     */
    public static function rollup(array $symbols, string $algo): string
    {
        $lines = '';

        foreach (array_keys($symbols['config']) as $key) {
            $lines .= 'config '.$key."\n";
        }

        foreach (array_keys($symbols['env']) as $name) {
            $lines .= 'env '.$name."\n";
        }

        return hash($algo, $lines);
    }

    /**
     * The literal string passed as the first argument to the call at `$i`, or null when the next
     * meaningful token is not `(` immediately followed by a quoted string — which covers `config()`,
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
     * `$this->config('x')`, `Foo::env('x')` and `function config(...)` are all excluded. Without this
     * a class with its own `env()` accessor contributes phantom variable names to the digest.
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
     * token is matched on its last segment so a fully-qualified `\Illuminate\Support\Facades\Config`
     * counts, and an aliased import does not need resolving.
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
