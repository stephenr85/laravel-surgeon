<?php

namespace Rushing\Surgeon\Env;

/**
 * One dotenv file, classified by the role it plays at boot — **twice**, because Laravel and Vite read
 * the same file with opposite multi-file semantics and both answers are true at once.
 *
 * `.env.production` is, simultaneously:
 *  - to Laravel, the file loaded **instead of** `.env` when `APP_ENV=production` ({@see $role});
 *  - to Vite, one of four files **merged together** under `--mode production` ({@see $viteRole}).
 *
 * `.env.local` is the sharpest case: Laravel sees the `APP_ENV=local` file, Vite sees its
 * always-loaded local override. Neither reading is wrong, and a tool that picks one silently is
 * wrong for half the variables in the file.
 */
class EnvFile
{
    /** Laravel roles. */
    public const DOCUMENTATION = 'documentation';

    public const BASE = 'base';

    public const ENVIRONMENT = 'environment';

    /** Vite roles: loaded on every build, loaded only for a named mode, or never. */
    public const VITE_ALWAYS = 'always';

    public const VITE_MODE = 'mode';

    public const VITE_NONE = 'none';

    /**
     * @param  string  $path  absolute path
     * @param  string  $name  basename, e.g. `.env.production`
     * @param  string  $role  Laravel role — one of DOCUMENTATION / BASE / ENVIRONMENT
     * @param  string|null  $environment  the `APP_ENV` value that selects this file
     * @param  string  $viteRole  Vite role — one of VITE_ALWAYS / VITE_MODE / VITE_NONE
     * @param  string|null  $viteMode  the `--mode` that selects this file (VITE_MODE only)
     * @param  bool  $viteLocal  a `.local` file — Vite's gitignored override layer
     * @param  list<string>  $keys  the variable names it declares (never values)
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $role,
        public ?string $environment,
        public string $viteRole,
        public ?string $viteMode,
        public bool $viteLocal,
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

    /** Loaded by Vite on every build regardless of mode — `.env` and `.env.local`. */
    public function isViteAlways(): bool
    {
        return $this->viteRole === self::VITE_ALWAYS;
    }

    /** How Laravel reaches this file, in words fit for a report. */
    public function roleDescription(): string
    {
        return match ($this->role) {
            self::DOCUMENTATION => 'documentation, never loaded',
            self::BASE => 'loaded by default',
            default => 'loaded INSTEAD of .env when APP_ENV='.$this->environment,
        };
    }

    /** How Vite reaches this file — merge semantics, so the wording is deliberately different. */
    public function viteRoleDescription(): string
    {
        return match ($this->viteRole) {
            self::VITE_ALWAYS => $this->viteLocal
                ? 'merged into every build (local override)'
                : 'merged into every build',
            self::VITE_MODE => 'merged in on --mode='.$this->viteMode.($this->viteLocal ? ' (local override)' : ''),
            default => 'not read by Vite',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'environment' => $this->environment,
            'vite_role' => $this->viteRole,
            'vite_mode' => $this->viteMode,
            'vite_local' => $this->viteLocal,
            'declares' => count($this->keys),
        ];
    }
}
