<?php

namespace Rushing\Surgeon\Env;

use InvalidArgumentException;

/**
 * The engine behind `surgeon:env`: scan a tree for the environment variables and config keys it
 * reads, then reconcile the variables against every dotenv file in the repo.
 *
 * A **read** in surgeon's read/write vocabulary — it opens files and writes nothing, which is what
 * lets the MCP surface expose it ungated.
 */
class EnvInventoryOperation
{
    /**
     * @param  list<string>  $prune  extra directory names to skip
     */
    public function run(string $root, ?string $examplePath = null, ?string $envPath = null, array $prune = []): EnvInventory
    {
        $root = rtrim($root, '/');

        if (! is_dir($root)) {
            throw new InvalidArgumentException("Cannot scan [{$root}]: not a directory.");
        }

        $files = EnvFileSet::discover($root, $examplePath, $envPath);
        $scanned = (new EnvScanner)->scanTree($root, $prune);

        return new EnvInventory(
            root: $root,
            variables: $this->reconcile($scanned['env'], $files),
            config: $scanned['config'],
            files: $files,
        );
    }

    /**
     * Join the reads against every dotenv file, on the variable name.
     *
     * The union of "read by code" and "declared by the repo" is deliberate: a variable declared in
     * `.env.example` and read nowhere is as much a finding as one read and undeclared, so a name from
     * either side earns a row. {@see EnvFileSet::declaredKeys()} is what keeps a purely local `.env`
     * name out of that union.
     *
     * @param  array<string, list<string>>  $read  name => sites
     * @return list<EnvVariable>
     */
    private function reconcile(array $read, EnvFileSet $files): array
    {
        $documentation = $files->documentation();
        $base = $files->named('.env');
        $environments = $files->environments();

        $names = array_values(array_unique([...array_keys($read), ...$files->declaredKeys()]));
        sort($names, SORT_STRING);

        $variables = [];

        foreach ($names as $name) {
            $documented = $documentation?->declares($name) ?? false;

            $variables[] = new EnvVariable(
                name: $name,
                sites: $read[$name] ?? [],
                documented: $documented,
                set: $base?->declares($name) ?? false,
                declaredIn: $files->declaring($name),
                missingFrom: $this->missingFrom($name, $documented, $environments),
            );
        }

        return $variables;
    }

    /**
     * The environment files that fail to declare a variable the repo says is required.
     *
     * Gated on `.env.example` declaring it, and that gate is doing real work. Without it, every
     * variable in a 200-line `.env` that a deliberately-minimal `.env.testing` omits becomes a
     * finding — hundreds of rows, almost all intentional, which is how a check gets ignored.
     * `.env.example` is the repo's own statement of what it needs; a variable it names, missing from
     * an environment that exists, is a gap the repo itself defined.
     *
     * @param  list<EnvFile>  $environments
     * @return list<string>
     */
    private function missingFrom(string $name, bool $documented, array $environments): array
    {
        if (! $documented) {
            return [];
        }

        $missing = [];

        foreach ($environments as $file) {
            if (! $file->declares($name)) {
                $missing[] = $file->name;
            }
        }

        return $missing;
    }
}
