<?php

namespace Rushing\Surgeon\Lint;

/**
 * The stack-agnostic registry + discovery engine (ticket 12). It holds the registered {@see LintStack}
 * adapters and answers the one engine question: **which stacks apply to this root?** — via
 * **sniff-with-override** (ticket 08 decision 4).
 *
 * Discovery order, per root:
 *  1. **Sniff** — each adapter's pure {@see LintStack::detect()} signal (a `pint.json`, a `package.json`
 *     + eslint config, …). Pure filesystem, no engine assumption about PHP.
 *  2. **Override** — the root's `composer.json` `extra.surgeon.lint` declaration corrects the sniff (the
 *     estate's declared-manifest pattern, à la topology/doctor). Two failure modes it repairs: pure
 *     declaration = fleet toil (every repo must list its stacks); pure sniffing mis-fires (a `package.json`
 *     with no lint script, a repo that deliberately skips a stack). The override is authoritative where
 *     present, the sniff is the default where it is silent.
 *
 * `extra.surgeon.lint` grammar (all optional):
 *   - `false` or `{ "enabled": false }` — suppress **all** lint for this root (opt-out entirely).
 *   - `{ "stacks": ["pint"] }` — an explicit allow-list: run exactly these registered stacks, sniff ignored.
 *   - `{ "only": [...] }` — alias for `stacks`.
 *   - `{ "except": ["eslint"] }` — sniff as normal, then subtract these (suppress a specific mis-fire).
 *   - `{ "add": ["eslint"] }` — sniff as normal, then force-add these even if the sniff was silent.
 * `stacks`/`only` and `except`/`add` compose: `only` wins as a hard allow-list; otherwise sniff ± add/except.
 *
 * Kept a distinct, pure class (no subprocess) so discovery is exhaustively unit-testable — the same
 * pure-logic-here / subprocess-in-the-command split tickets 10/11 keep.
 */
class LintStackRegistry
{
    /** @var list<LintStack> */
    private array $stacks = [];

    /** @param  list<LintStack>  $stacks */
    public function __construct(array $stacks = [])
    {
        foreach ($stacks as $stack) {
            $this->register($stack);
        }
    }

    /** The shipped proof-of-two: Pint (PHP) + eslint (JS). Hosts add more by registering adapters. */
    public static function withDefaults(): self
    {
        return new self([new PintAdapter, new EslintAdapter]);
    }

    public function register(LintStack $stack): self
    {
        $this->stacks[] = $stack;

        return $this;
    }

    /** @return list<LintStack> every registered adapter (for enumeration/inspection). */
    public function all(): array
    {
        return $this->stacks;
    }

    /**
     * The stacks that apply to a root, after sniff-with-override.
     *
     * @return list<LintStack>
     */
    public function stacksFor(string $root): array
    {
        $override = $this->overrideFor($root);

        if ($override['suppressAll']) {
            return [];
        }

        $byName = [];
        foreach ($this->stacks as $stack) {
            $byName[$stack->name()] = $stack;
        }

        // Hard allow-list: run exactly the named, registered stacks (sniff ignored).
        if ($override['only'] !== null) {
            return array_values(array_filter(
                array_map(fn (string $n) => $byName[$n] ?? null, $override['only']),
            ));
        }

        // Otherwise: sniff, then apply add/except.
        $selected = [];
        foreach ($this->stacks as $stack) {
            $name = $stack->name();
            if (in_array($name, $override['except'], true)) {
                continue;
            }
            if ($stack->detect($root) || in_array($name, $override['add'], true)) {
                $selected[] = $stack;
            }
        }

        return $selected;
    }

    /**
     * Parse a root's `extra.surgeon.lint` declaration into a normalized override.
     *
     * @return array{suppressAll: bool, only: list<string>|null, except: list<string>, add: list<string>}
     */
    public function overrideFor(string $root): array
    {
        $none = ['suppressAll' => false, 'only' => null, 'except' => [], 'add' => []];

        $composer = rtrim($root, '/').'/composer.json';
        if (! is_file($composer)) {
            return $none;
        }
        $data = json_decode((string) file_get_contents($composer), true);
        if (! is_array($data)) {
            return $none;
        }

        $lint = $data['extra']['surgeon']['lint'] ?? null;

        if ($lint === null) {
            return $none;
        }
        if ($lint === false) {
            return ['suppressAll' => true, 'only' => null, 'except' => [], 'add' => []];
        }
        if (! is_array($lint)) {
            return $none;
        }
        if (($lint['enabled'] ?? true) === false) {
            return ['suppressAll' => true, 'only' => null, 'except' => [], 'add' => []];
        }

        $only = $lint['only'] ?? $lint['stacks'] ?? null;

        return [
            'suppressAll' => false,
            'only' => is_array($only) ? array_values(array_map('strval', $only)) : null,
            'except' => is_array($lint['except'] ?? null) ? array_values(array_map('strval', $lint['except'])) : [],
            'add' => is_array($lint['add'] ?? null) ? array_values(array_map('strval', $lint['add'])) : [],
        ];
    }
}
