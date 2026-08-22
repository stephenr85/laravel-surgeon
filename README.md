# rushing/laravel-surgeon

A **deterministic code-insight + operation engine** for Laravel package estates.

*Doctor diagnoses; the surgeon operates.* This package builds on the `rushing/laravel-doctor`
conformance registry and adds the **fix half** — where a mal-adaptation `Finding` nominates a
precise, reversible `surgeon:*` operation. Its acceptance proof (not its scope ceiling) is
cross-tier **FQN-relocation**: collapsing the grep-and-repoint hand-work of a real move into an
**audited, verified, mechanical pass** plus a structured handoff to an agent for the judgment tail.

## Architecture (the load-bearing decisions)

- **Substrate: `nikic/php-parser`**, not Rector. Rewrite = **AST-locate + byte-offset splice**
  (format preserved by construction); the format-preserving printer is reserved for structural
  Tier-2 ops. The reusable find-half scanner is graphine's `Rushing\Graphine\Testing\SeamGuard`.
- **Commands:** `surgeon:trace` (target-driven, read-only reference hunt / relocation pre-pass),
  `surgeon:audit` (conformance sweep over the registered doctor manifest — findings + the deterministic
  operations that fix them), and `surgeon:move`
  (writer; `--apply` refuses raw declared input, applying only an explicit *resolved* operation set).
- **Verification:** the `rushing/php-package-topology` cycle-guard runs as a post-rewrite gate.
- **Tiering:** foundation vendor — no `splicewire/*` / `schemastud/*` deps. It ships artisan
  commands, so any tier that composes it inherits the `surgeon:*` family.

## Status

Past scaffold — the full command surface is live:

- `surgeon:ping` — smoke check that the provider registers and the AST substrate (nikic + SeamGuard) resolves.
- `surgeon:audit` — the conformance sweep over the host's registered doctor manifest (via `ConformanceManifest`): findings + the deterministic operations that fix them.
- `surgeon:trace` — target-driven, read-only reference hunt / relocation pre-pass.
- `surgeon:move` / `surgeon:rewrite` — the writer commands; `--apply` refuses raw declared input, applying only an explicit *resolved* operation set.
- `surgeon:lint`, `surgeon:overlay`, `surgeon:canonicalize`, `surgeon:replay` — the rest of the operation/verification family.
- `surgeon:env` — the environment inventory: every env var the codebase reads or declares, reconciled
  against `.env.example` and `.env`, plus the config keys it reads.

### `surgeon:env`

Nothing in Laravel answers "what does this thing actually need in its environment". `config:show`
prints one config file's *values* (and would spray secrets doing it), `env` prints `APP_ENV`, and
`vlucas/phpdotenv` parses `.env` without ever enumerating what reads it — Symfony's `debug:dotenv` has
no Laravel counterpart. So the question gets answered by grepping, which is why `.env.example` drifts
from the code the moment anyone stops being careful.

```bash
php artisan surgeon:env --path=~/Workspaces/php/packages/splicewire/tower
```

```
surgeon:env (…/splicewire/tower)

  16 env var(s) read  ·  16 known  ·  117 config key(s) read

  read outside config/ (3) — these return NULL once config:cache runs — i.e. in production
    APP_PROD_URL          src/Data/JsonSchemaData.php:30, src/Data/JsonSchemaRefData.php:16
    MCP_LOCAL_TENANT      src/Mcp/Servers/SplicewireServer.php:92
    MCP_LOCAL_USER_EMAIL  src/Mcp/Servers/SplicewireServer.php:107
```

The join across reads and every dotenv file is what makes it worth running:

- **read outside `config/`** — the live bug. `config:cache` stops `.env` from loading, so these return
  null in production and nowhere else. `tests/` is excluded; a suite runs against a live `.env`, so the
  hazard genuinely does not apply there.
- **missing from an environment file** — see below.
- **read but not in `.env.example`** — a deployer has no way to know the variable exists.
- **declared but never read** — dead weight in `.env.example` nobody can prove is safe to delete.

#### Multi-file: Laravel replaces, Symfony merges

This is the one everyone gets wrong on the way in from Symfony.
`LoadEnvironmentVariables::checkForSpecificEnvironmentFile()` calls `$app->loadEnvironmentFrom($file)`,
and that setter assigns `$this->environmentFile = $file`. It **swaps the single file**. There is no
layering, no `.env.local` overlaying `.env`, no per-variable fallback.

So under `APP_ENV=testing` with a `.env.testing` present, `.env` is not read *at all*, and anything
living only in `.env` is simply absent. That failure has no error message — it looks like a feature
quietly not working in one environment.

```
  dotenv files
    .env.example       documentation, never loaded · declares 4
    .env               loaded by default · declares 3
    .env.testing       loaded INSTEAD of .env when APP_ENV=testing · declares 1

  missing from .env.testing (3) — .env.testing REPLACES .env, so these are absent at boot, not inherited
    MAILGUN_DOMAIN
    STRIPE_KEY
    STRIPE_WEBHOOK_SECRET
```

The gap check is gated on `.env.example` declaring the variable. Without that gate, every variable a
deliberately-minimal `.env.testing` omits becomes a finding — hundreds of rows, almost all intentional.
`.env.example` is the repo's own statement of what it needs; a variable it names, missing from an
environment that exists, is a gap the repo itself defined.

`--matrix` shows the full picture, one row per variable:

```
  declaration matrix  .env.example · .env · .env.testing
    ✓ ✓ ✓  DB_HOST
    ✓ · ·  MAILGUN_DOMAIN
    · · ·  QUEUE_TUNNEL_URL
    ✓ ✓ ·  STRIPE_KEY
```

A name that appears *only* in the local `.env` gets no row — that file is one machine's state, not a
statement about the application, and personal junk (an editor token, a colleague's tunnel URL) would
bury the real findings. `phpunit.xml`'s `<env>` elements are a real fourth source for the test
environment and are deliberately out of scope: they carry a `force` flag with its own precedence rule,
and folding them in without modelling that faithfully would produce a confident wrong answer.

| | |
| --- | --- |
| `--path=` | Root to scan (default: the app base path) |
| `--example=` / `--env-file=` | Point at other dotenv files (default: `<path>/.env.example`, `<path>/.env`) |
| `--all` | List every variable with `[rds]` read/documented/set markers, not just findings |
| `--matrix` | Show which dotenv file declares each variable |
| `--config` | Also list the config keys read |
| `--sites` | Show `file:line` for every variable |
| `--strict` | Exit non-zero on any finding — undocumented, unused, cache-unsafe, or an environment gap |
| `--json` | Machine-readable output |

**Accuracy is a floor, not a census.** Only literal first arguments are read, so `env($name)` and
`config("beam.{$domain}.path")` are invisible — everything listed is really read, but a tree that
builds key names dynamically reads more than this can show. Guessing at an interpolated key would
produce the false entries that get a report ignored. The scan is token-level, so a `config()` call
inside a docblock is prose, not a read.

**Values are never read** — names come from call sites and from the left of the `=` in a dotenv file,
so nothing secret-derived can reach stdout, a CI log, or an MCP response.

## Local dev

```bash
composer install
composer test   # pest
composer pint   # style
```
