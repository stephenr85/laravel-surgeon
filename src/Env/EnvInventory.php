<?php

namespace Rushing\Surgeon\Env;

/**
 * The report: every environment variable a tree reads or declares, reconciled, plus the config keys
 * it reads — what `surgeon:env` renders and what an MCP caller receives.
 */
class EnvInventory
{
    /**
     * @param  string  $root  the tree scanned
     * @param  list<EnvVariable>  $variables  every variable, name-sorted
     * @param  array<string, list<string>>  $config  config key => `path:line` read sites
     * @param  bool  $exampleExists  whether a `.env.example` was found (absent ≠ documents nothing)
     * @param  bool  $envExists  whether a local `.env` was found
     */
    public function __construct(
        public string $root,
        public array $variables,
        public array $config,
        public bool $exampleExists,
        public bool $envExists,
    ) {}

    /** @return list<EnvVariable> */
    public function read(): array
    {
        return array_values(array_filter($this->variables, fn (EnvVariable $v) => $v->isRead()));
    }

    /** @return list<EnvVariable> read by code but missing from `.env.example` */
    public function undocumented(): array
    {
        return array_values(array_filter($this->variables, fn (EnvVariable $v) => $v->isUndocumented()));
    }

    /** @return list<EnvVariable> declared in `.env.example` but read nowhere */
    public function unused(): array
    {
        return array_values(array_filter($this->variables, fn (EnvVariable $v) => $v->isUnused()));
    }

    /** @return list<EnvVariable> read outside `config/` — null under `config:cache` */
    public function cacheUnsafe(): array
    {
        return array_values(array_filter($this->variables, fn (EnvVariable $v) => $v->isCacheUnsafe()));
    }

    /** @return list<string> the config keys read, sorted */
    public function configKeys(): array
    {
        return array_keys($this->config);
    }

    /**
     * Whether the inventory found anything worth acting on — what `--strict` exits non-zero for.
     * "Unused" is deliberately included: a `.env.example` nobody can prune is the slow half of the
     * same problem.
     */
    public function hasFindings(): bool
    {
        return $this->cacheUnsafe() !== [] || $this->undocumented() !== [] || $this->unused() !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'sources' => [
                'env_example' => $this->exampleExists,
                'env' => $this->envExists,
            ],
            'counts' => [
                'read' => count($this->read()),
                'undocumented' => count($this->undocumented()),
                'unused' => count($this->unused()),
                'cache_unsafe' => count($this->cacheUnsafe()),
                'config_keys' => count($this->config),
            ],
            'env' => array_map(fn (EnvVariable $v) => $v->toArray(), $this->variables),
            'config' => $this->config,
        ];
    }
}
