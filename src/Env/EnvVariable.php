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
     * @param  list<string>  $declaredIn  every dotenv file declaring it, by basename, in role order
     * @param  list<string>  $missingFrom  environment files where it is genuinely absent, judged under
     *                                     the semantics of whoever reads it ({@see EnvInventoryOperation})
     * @param  bool  $exposedToVite  matches a Vite env prefix, so the front end *could* read it
     * @param  list<string>  $clientSites  `path:line` sites in front-end sources reading it
     */
    public function __construct(
        public string $name,
        public array $sites = [],
        public bool $documented = false,
        public bool $set = false,
        public array $declaredIn = [],
        public array $missingFrom = [],
        public bool $exposedToVite = false,
        public array $clientSites = [],
    ) {}

    /**
     * Who reads this variable — `php`, `vite`, or both. A variable can be exposed to the front end
     * *and* read by config, and then both sets of multi-file rules apply to the same name.
     *
     * @return list<string>
     */
    public function consumers(): array
    {
        $consumers = [];

        if ($this->readByPhp()) {
            $consumers[] = 'php';
        }

        if ($this->clientSites !== []) {
            $consumers[] = 'vite';
        }

        return $consumers;
    }

    /** Read anywhere — PHP or the client bundle. */
    public function isRead(): bool
    {
        return $this->sites !== [] || $this->clientSites !== [];
    }

    public function readByPhp(): bool
    {
        return $this->sites !== [];
    }

    /** Read by code but absent from `.env.example` — undocumented configuration. */
    public function isUndocumented(): bool
    {
        return $this->isRead() && ! $this->documented;
    }

    /**
     * Declared in `.env.example` but read nowhere — a candidate for deletion.
     *
     * "Nowhere" spans both languages deliberately. Judged on PHP sites alone, every front-end
     * variable in a Laravel+Vite repo lands here and the report recommends deleting live config,
     * because a `VITE_MAP_TOKEN` used only from a Vue component has no PHP read site at all.
     */
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
     * Documented as required, yet absent from an environment file that exists — so it is missing in
     * that environment specifically.
     */
    public function hasEnvironmentGap(): bool
    {
        return $this->missingFrom !== [];
    }

    /**
     * A one-word status for rendering, worst first. The cache hazard outranks everything because it
     * is a live bug; an environment gap outranks a documentation gap because it breaks a running
     * environment rather than a deployer's expectations.
     */
    public function status(): string
    {
        return match (true) {
            $this->isCacheUnsafe() => 'cache-unsafe',
            $this->hasEnvironmentGap() => 'environment-gap',
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
            'consumers' => $this->consumers(),
            'declared_in' => $this->declaredIn,
            'missing_from' => $this->missingFrom,
            'sites' => $this->sites,
            'client_sites' => $this->clientSites,
            'reads_outside_config' => $this->readsOutsideConfig(),
        ];
    }
}
