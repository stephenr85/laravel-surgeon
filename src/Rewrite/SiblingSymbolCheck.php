<?php

namespace Rushing\Surgeon\Rewrite;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * The dangling-sibling check — the gate stage that catches the one thing `php -l` structurally cannot.
 *
 * ## The defect it exists for
 *
 * Relocating members of ONE namespace one symbol at a time silently breaks the ones left behind, and
 * every other signal reports success. Found in `rushing/php-popcorn` (registry-kernel ticket 33 D6/C1):
 *
 *   - `src/Strategy/` held `Strategy`, `StrategyResult` and `StrategyLadder`, all in
 *     `Rushing\Popcorn\Strategy`. Same-namespace siblings need no `use`, so `Strategy` referenced a
 *     bare `?StrategyResult`.
 *   - Move #1 relocated `Strategy` → `Rushing\Popcorn\Ladders\Rung`, rewriting that file's
 *     `namespace` line. The bare `StrategyResult` on it **silently re-bound** to
 *     `Rushing\Popcorn\Ladders\StrategyResult`, which does not exist.
 *   - Move #2, hunting references to `Rushing\Popcorn\Strategy\StrategyResult`, therefore **correctly**
 *     resolved it as not-a-match and left it dangling.
 *
 * The first move broke the code and the breakage made the second move blind to it. `php -l` passed on
 * every file — an unresolvable class name is a RUNTIME failure, not a parse error — `composer
 * dump-autoload` regenerated happily, and the writer reported *"applied: 10 splice(s)"*. The dangling
 * return type reached a commit.
 *
 * ## What it checks, and why only this
 *
 * For each touched file: every class-like name that resolves into **the file's own namespace** must
 * actually exist. That is exactly the population a `namespace`-line rewrite re-binds — a name reached
 * through a `use` import or written fully-qualified resolves the same before and after the move, so it
 * cannot be broken this way and is deliberately not checked here. Narrow on purpose: a gate that fails
 * on things the write-op did not cause is a gate people pass `--no-verify` to.
 *
 * ## Two-step existence test, so it cannot cry wolf
 *
 * 1. **PSR-4 sibling scan** — parse the declarations in the file's own directory. Under PSR-4 a
 *    same-namespace sibling lives beside it, and this needs no autoloader, which is what makes the
 *    stage deterministic in a root whose `vendor/` is absent or stale.
 * 2. **Autoloader fallback** — only if step 1 misses, ask `class_exists` and friends (without
 *    triggering `__autoload` side effects beyond a normal resolve). Catches the legitimate non-PSR-4
 *    layout, a sibling declared in a parent directory, or a class the composer stage has just made
 *    resolvable.
 *
 * A name fails only when BOTH say no. Runs AFTER `composer dump-autoload` for that reason.
 */
class SiblingSymbolCheck
{
    /** @var array<string, list<string>> declared FQCNs per scanned directory, memoised for one run */
    private array $declaredByDir = [];

    /**
     * @param  list<string>  $touchedFiles  absolute paths, post-apply
     * @return list<array{file: string, name: string, line: int}> the dangling references, if any
     */
    public function dangling(array $touchedFiles): array
    {
        $found = [];

        foreach ($touchedFiles as $file) {
            if (! str_ends_with($file, '.php') || ! is_file($file)) {
                continue;
            }

            foreach ($this->sameNamespaceReferences($file) as $ref) {
                if ($this->exists($ref['name'], dirname($file))) {
                    continue;
                }
                $found[] = ['file' => $file, 'name' => $ref['name'], 'line' => $ref['line']];
            }
        }

        return $found;
    }

    /**
     * Class-like references on this file that resolve into the file's OWN namespace.
     *
     * `NameResolver` gives every {@see Node\Name} its fully-qualified form, so an unqualified sibling
     * arrives already stamped with whatever namespace the file currently declares — which, after a
     * `namespace`-line rewrite, is the new one. That re-stamping IS the defect, and reading it here is
     * how the check sees it.
     *
     * @return list<array{name: string, line: int}>
     */
    private function sameNamespaceReferences(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false || $code === '') {
            return [];
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        } catch (\Throwable) {
            // A parse failure is `php -l`'s stage to report, not this one's.
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract
        {
            public string $namespace = '';

            /** @var list<string> FQCNs this file itself declares — a self-reference is never dangling. */
            public array $declares = [];

            /** @var list<array{name: string, line: int}> */
            public array $names = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';

                    return null;
                }

                if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
                    $this->declares[] = ltrim($this->namespace.'\\'.$node->name->toString(), '\\');

                    return null;
                }

                // Only NAME nodes — a `use` import is a Stmt\UseUse and never reaches here as a
                // resolved Name, which is why imported symbols are outside this check by construction.
                if ($node instanceof Node\Name) {
                    $this->names[] = [
                        'name' => $node->toString(),
                        'line' => $node->getStartLine(),
                    ];
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $namespace = $visitor->namespace;
        if ($namespace === '') {
            return [];
        }

        $prefix = $namespace.'\\';
        $seen = [];
        $out = [];

        foreach ($visitor->names as $ref) {
            $name = $ref['name'];

            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            // A name nested deeper than one segment belongs to a CHILD namespace, not this one, and
            // could not have been an unqualified sibling.
            if (str_contains(substr($name, strlen($prefix)), '\\')) {
                continue;
            }
            if (in_array($name, $visitor->declares, true) || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $out[] = ['name' => $name, 'line' => $ref['line']];
        }

        return $out;
    }

    /** Step 1 (PSR-4 sibling scan), then step 2 (autoloader). Dangling only when both miss. */
    private function exists(string $fqcn, string $dir): bool
    {
        if (in_array($fqcn, $this->declaredIn($dir), true)) {
            return true;
        }

        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    }

    /**
     * Every FQCN declared by a `.php` file directly in $dir — no recursion, because a PSR-4 sibling is
     * by definition beside the file, and a deeper walk would start reporting child namespaces.
     *
     * @return list<string>
     */
    private function declaredIn(string $dir): array
    {
        if (isset($this->declaredByDir[$dir])) {
            return $this->declaredByDir[$dir];
        }

        $declared = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $code = @file_get_contents($file);
            if ($code === false || $code === '') {
                continue;
            }
            // Cheap and sufficient: a declaration line, not a full parse. This runs per touched
            // directory inside a blocking gate, and the shapes are `namespace X;` + `class Y`.
            if (! preg_match('/^\s*namespace\s+([^;{\s]+)/m', $code, $ns)) {
                continue;
            }
            if (! preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $types)) {
                continue;
            }
            foreach ($types[1] as $type) {
                $declared[] = $ns[1].'\\'.$type;
            }
        }

        return $this->declaredByDir[$dir] = $declared;
    }
}
