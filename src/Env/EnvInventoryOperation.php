<?php

namespace Rushing\Surgeon\Env;

use InvalidArgumentException;

/**
 * The engine behind `surgeon:env`: scan a tree for the environment variables and config keys it
 * reads, then reconcile the variables against `.env.example` and the local `.env`.
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

        $examplePath ??= $root.'/.env.example';
        $envPath ??= $root.'/.env';

        $scanned = (new EnvScanner)->scanTree($root, $prune);
        $documented = DotenvKeys::in($examplePath);
        $set = DotenvKeys::in($envPath);

        return new EnvInventory(
            root: $root,
            variables: $this->reconcile($scanned['env'], $documented, $set),
            config: $scanned['config'],
            exampleExists: DotenvKeys::exists($examplePath),
            envExists: DotenvKeys::exists($envPath),
        );
    }

    /**
     * Join the three sources on the variable name. The union is deliberate: a variable declared in
     * `.env.example` and read nowhere is as much a finding as one read and undeclared, so a name from
     * either side earns a row.
     *
     * A name that appears ONLY in the local `.env` is not a row. Personal `.env` files accumulate
     * machine-specific junk (an editor's token, a colleague's tunnel URL), and reporting it as a
     * finding would fill the output with things that are nobody's problem.
     *
     * @param  array<string, list<string>>  $read  name => sites
     * @param  list<string>  $documented
     * @param  list<string>  $set
     * @return list<EnvVariable>
     */
    private function reconcile(array $read, array $documented, array $set): array
    {
        $names = array_values(array_unique([...array_keys($read), ...$documented]));
        sort($names, SORT_STRING);

        $variables = [];

        foreach ($names as $name) {
            $variables[] = new EnvVariable(
                name: $name,
                sites: $read[$name] ?? [],
                documented: in_array($name, $documented, true),
                set: in_array($name, $set, true),
            );
        }

        return $variables;
    }
}
