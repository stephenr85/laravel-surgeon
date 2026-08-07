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

Backing this: `RegistrationDriftDetector`, `AuditEngine`, and `PackageGraph` in `src/Audit/`, plus an
MCP server (`src/Mcp/SurgeonMcpServer.php`) exposing the same operations to an agent. See the effort
map in the consuming app at `.scratch/refactor-tooling/MAP.md`.

## Local dev

```bash
composer install
composer test   # pest
composer pint   # style
```
