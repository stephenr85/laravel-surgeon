<?php

namespace Rushing\Surgeon\Env;

/**
 * One dotenv file in a repo, classified by the role it plays at boot.
 *
 * The classification is the point. Laravel treats these three roles completely differently, and
 * conflating them is what makes "which value wins" hard to reason about:
 *
 *  - {@see DOCUMENTATION} — `.env.example`. Never loaded by anything. It is a promise to a deployer
 *    about what the app needs, and its only power is being right or wrong.
 *  - {@see BASE} — `.env`. What loads when `APP_ENV` is unset or has no matching file.
 *  - {@see ENVIRONMENT} — `.env.testing`, `.env.ci`, … Loaded **instead of** `.env` when `APP_ENV`
 *    matches the suffix (or `--env=` names it).
 */
class EnvFile
{
    public const DOCUMENTATION = 'documentation';

    public const BASE = 'base';

    public const ENVIRONMENT = 'environment';

    /**
     * @param  string  $path  absolute path
     * @param  string  $name  basename, e.g. `.env.testing`
     * @param  string  $role  one of the three role constants
     * @param  string|null  $environment  the `APP_ENV` value that selects this file (environment role only)
     * @param  list<string>  $keys  the variable names it declares (never values)
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $role,
        public ?string $environment,
        public array $keys,
    ) {}

    public function declares(string $name): bool
    {
        return in_array($name, $this->keys, true);
    }

    public function isDocumentation(): bool
    {
        return $this->role === self::DOCUMENTATION;
    }

    public function isEnvironment(): bool
    {
        return $this->role === self::ENVIRONMENT;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'environment' => $this->environment,
            'declares' => count($this->keys),
        ];
    }
}
