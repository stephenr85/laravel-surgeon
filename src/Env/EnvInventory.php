<?php

namespace Rushing\Surgeon\Env;

/**
 * The report: every environment variable a tree reads or declares, reconciled across every dotenv
 * file in the repo, plus the config keys it reads — what `surgeon:env` renders and what an MCP
 * caller receives.
 */
class EnvInventory
{
    /**
     * @param  string  $root  the tree scanned
     * @param  list<EnvVariable>  $variables  every variable, name-sorted
     * @param  array<string, list<string>>  $config  config key => `path:line` read sites
     * @param  EnvFileSet  $files  the dotenv files found, classified by boot role
     */
    public function __construct(
        public string $root,
        public array $variables,
        public array $config,
        public EnvFileSet $files,
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

    /** @return list<EnvVariable> documented as required but absent from an environment file */
    public function environmentGaps(): array
    {
        return array_values(array_filter($this->variables, fn (EnvVariable $v) => $v->hasEnvironmentGap()));
    }

    /**
     * The gaps grouped by the environment file that lacks them — the shape the question is actually
     * asked in ("what is missing under APP_ENV=testing?").
     *
     * @return array<string, list<string>> file name => missing variable names
     */
    public function gapsByFile(): array
    {
        $gaps = [];

        foreach ($this->environmentGaps() as $variable) {
            foreach ($variable->missingFrom as $file) {
                $gaps[$file][] = $variable->name;
            }
        }

        ksort($gaps, SORT_STRING);

        return $gaps;
    }

    public function exampleExists(): bool
    {
        return $this->files->documentation() !== null;
    }

    public function envExists(): bool
    {
        return $this->files->named('.env') !== null;
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
        return $this->cacheUnsafe() !== []
            || $this->environmentGaps() !== []
            || $this->undocumented() !== []
            || $this->unused() !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'files' => array_map(fn (EnvFile $f) => $f->toArray(), $this->files->files),
            'counts' => [
                'read' => count($this->read()),
                'undocumented' => count($this->undocumented()),
                'unused' => count($this->unused()),
                'cache_unsafe' => count($this->cacheUnsafe()),
                'environment_gaps' => count($this->environmentGaps()),
                'config_keys' => count($this->config),
            ],
            'gaps_by_file' => $this->gapsByFile(),
            'env' => array_map(fn (EnvVariable $v) => $v->toArray(), $this->variables),
            'config' => $this->config,
        ];
    }
}
