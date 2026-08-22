<?php

namespace Rushing\Surgeon\Env;

/**
 * Reads the variable NAMES declared in a `.env`-format file — `.env`, `.env.example`, `.env.testing`.
 *
 * **Names only. The value side of the `=` is never retained**, not even in memory beyond the split,
 * so a report built on this cannot leak a credential however it is rendered. That constraint is why
 * this exists at all rather than reaching for `vlucas/phpdotenv`: the parser Laravel already ships
 * returns names *and* values, and every caller of it would then be one careless `dd()` away from a
 * secret in a CI log.
 *
 * The format is simple enough that a line walk is honest: `KEY=value`, `export KEY=value`, blank
 * lines, and `#` comments. Multi-line quoted values are the one shape this does not model — a
 * continuation line has no `=` and is skipped, which is the right failure (a missed name, never an
 * invented one).
 */
class DotenvKeys
{
    /**
     * The variable names declared in the file, sorted and de-duplicated. A missing file yields an
     * empty list — an absent `.env.example` is a fact about the repo, not an error.
     *
     * @return list<string>
     */
    public static function in(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $names = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $name = self::nameIn($line);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Whether the file exists at all — the difference between "declares nothing" and "there is no
     * `.env.example` in this repo", which the report states differently.
     */
    public static function exists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * The variable name declared on one line, or null for a blank, a comment, or a continuation.
     */
    private static function nameIn(string $line): ?string
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $equals = strpos($line, '=');

        if ($equals === false) {
            return null;
        }

        $name = trim(substr($line, 0, $equals));

        if (str_starts_with($name, 'export ')) {
            $name = trim(substr($name, 7));
        }

        // A shell-legal name only; anything else is a line this walk has misread (a quoted value's
        // continuation, say) and inventing a variable from it would be worse than missing one.
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : null;
    }
}
