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
- `surgeon:fingerprint` — the identity read: hash every file under a root and enumerate every config key
  and env var name it reads, as digests two checkouts compare by.

### `surgeon:fingerprint`

```bash
php artisan surgeon:fingerprint --path=~/Workspaces/php/packages/rushing/laravel-surgeon
```

```
surgeon:fingerprint (…/rushing/laravel-surgeon)

  digest   d0e1f9420835196030653f9e1fe72568 (xxh128)
  files    903c94dc7c7b570b2f588259dba962b8  166 file(s)
  symbols  e2b90edd1208d797d983dc8ab4107c6c  1 config key(s), 0 env var(s)
```

Three digests, because they answer different questions: **files** moves when any tracked byte changes;
**symbols** moves when the set of config keys / env var names the tree reads changes — the signal that
catches "something started reading a variable nobody has set in production", which a content hash can
only report as *something* changed. The combined **digest** is the one to paste into a ticket.

Paths are relative to the root and everything is sorted, so the same content at two different absolute
paths produces the same digest. `vendor/`, `node_modules/`, `.git/`, `storage/`, `build/`, `dist/` are
pruned before descent (add more with `--prune=`), so the digest answers "has this repo changed", not
"have I run `composer install`".

| | |
| --- | --- |
| `--path=` | Root to fingerprint (default: the app base path) |
| `--expect=` | Exit non-zero unless the combined digest matches — a CI drift gate with no sidecar state |
| `--files` / `--symbols` | List the per-file hashes, or the config keys and env names |
| `--files-only` | Skip the `config()`/`env()` scan (the expensive half; also the PHP-only half) |
| `--algo=` | Any `hash_algos()` name; the `xxh128` default is fast and non-cryptographic |
| `--json` | Machine-readable output — always includes the file list and the symbol surface |

**Env values are never read.** The scan collects variable *names* from `env()` call sites and never
resolves them, so nothing secret-derived can reach stdout, a CI log, or an MCP response.

Backing this: `RegistrationDriftDetector`, `AuditEngine`, and `PackageGraph` in `src/Audit/`, plus an
MCP server (`src/Mcp/SurgeonMcpServer.php`) exposing the same operations to an agent. See the effort
map in the consuming app at `.scratch/refactor-tooling/MAP.md`.

## Local dev

```bash
composer install
composer test   # pest
composer pint   # style
```
