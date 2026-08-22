<?php

namespace Rushing\Surgeon\Env;

/**
 * One environment variable, reconciled across the three places it can appear: the code that **reads**
 * it, the `.env.example` that **documents** it, and the local `.env` that **sets** it.
 *
 * The three-way join is the whole point of the inventory. A bare list of what code reads is mildly
 * interesting; the gaps between the three lists are where the bugs live:
 *
 *  - **read, not documented** — a variable a deployer has no way to know exists. The one that gets
 *    found in production, as a null.
 *  - **documented, never read** — dead weight in `.env.example` that everyone dutifully copies
 *    forward and nobody can delete, because no one can prove it is unused.
 *  - **read outside `config/`** — see {@see $readsOutsideConfig}. Not a gap but a hazard, and the
 *    most valuable thing here.
 *
 * Values are never carried. {@see $set} says a name appears in the local `.env`; what it is set to is
 * not this object's business and never enters it.
 */
class EnvVariable
{
    /**
     * @param  string  $name  the variable name
     * @param  list<string>  $sites  `path:line` sites reading it (empty when only declared)
     * @param  bool  $documented  declared in `.env.example`
     * @param  bool  $set  present in the local `.env` (name only — never the value)
     */
    public function __construct(
        public string $name,
        public array $sites = [],
        public bool $documented = false,
        public bool $set = false,
    ) {}

    public function isRead(): bool
    {
        return $this->sites !== [];
    }

    /** Read by code but absent from `.env.example` — undocumented configuration. */
    public function isUndocumented(): bool
    {
        return $this->isRead() && ! $this->documented;
    }

    /** Declared in `.env.example` but read nowhere — a candidate for deletion. */
    public function isUnused(): bool
    {
        return ! $this->isRead() && $this->documented;
    }

    /**
     * The `env()` call sites that are NOT inside a `config/` directory — excluding tests.
     *
     * **This is the bug, not a style note.** `php artisan config:cache` serializes the config array
     * and Laravel then stops loading `.env` entirely, so every `env()` call outside a config file
     * returns null in exactly the environment that runs `config:cache` — production. The failure is
     * invisible in local development, where the cache is usually absent, which is what makes it worth
     * a mechanical check rather than a convention.
     *
     * `tests/` is excluded because the hazard does not exist there: a suite runs against a live `.env`
     * (a cached config is the thing test bootstrapping goes out of its way to avoid), so a test reading
     * `env('DB_HOST')` to build a connection is doing something legitimate. Left in, tests dominate the
     * finding list in every repo that has any — which is how a real check gets scrolled past.
     *
     * @return list<string>
     */
    public function readsOutsideConfig(): array
    {
        return array_values(array_filter(
            $this->sites,
            static fn (string $site): bool => ! str_starts_with($site, 'config/')
                && ! str_starts_with($site, 'tests/'),
        ));
    }

    public function isCacheUnsafe(): bool
    {
        return $this->readsOutsideConfig() !== [];
    }

    /**
     * A one-word status for rendering, worst first: the cache hazard outranks documentation gaps
     * because it is a live bug rather than a tidiness problem.
     */
    public function status(): string
    {
        return match (true) {
            $this->isCacheUnsafe() => 'cache-unsafe',
            $this->isUndocumented() => 'undocumented',
            $this->isUnused() => 'unused',
            default => 'ok',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status(),
            'read' => $this->isRead(),
            'documented' => $this->documented,
            'set' => $this->set,
            'sites' => $this->sites,
            'reads_outside_config' => $this->readsOutsideConfig(),
        ];
    }
}
