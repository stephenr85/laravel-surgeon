<?php

namespace Rushing\Surgeon\Conformance;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit (particle-doctrine-followups #04): **a class-string
 * in a config file that does not resolve.** The defect shape that motivated it: a stale
 * fully-qualified name sat in a config file's `use` import, feeding a config value, which a provider
 * then resolved as a binding — two hops from any binding call site, so every audit scanning provider
 * registrations missed it, and it cascaded into `BindingResolutionException` across an entire
 * surface. "A class-string in config that does not resolve" names no host vocabulary, which is the
 * stated bar for a built-in — the defect can occur in ANY package shipping a config file — so it
 * lives here in the foundation package, not in one tier of the estate.
 *
 * **Harvest is static, from the AST — never the config helper.** Static works for packages whose
 * config was never published, survives a config file that throws at runtime (the file is parsed,
 * not executed), and yields a byte span so every finding cites a file and line. Two written forms
 * are harvested: a `Foo::class` const fetch (resolved through the file's imports — exactly where a
 * stale `use` line bites), and a multi-segment string literal shaped like an FQN (`'App\Old\Thing'`).
 *
 * **The existence predicate is the estate's one spelling of "resolves"** — class, enum, or
 * interface — lifted verbatim from the audit that already spells it
 * (`Splicewire\Beam\Surgeon\TypeScriptShortNameCollisionAudit`), not a new variant.
 *
 * **Severity is a failure**, not a warning: this genuinely broke a surface. **The fix half is
 * advisory** and hands off to the rename tool by name (`surgeon:trace` / `surgeon:move`), because
 * repairing a stale FQN means locating its real home — a trace-and-move question, never one splice.
 *
 * **The constructor takes injectable readers** — the file lister, the source reader, and the
 * existence predicate — because that is the only way to unit-test "this class does not exist"
 * without shipping a class that must not exist. Defaults are the real filesystem + the real
 * predicate; same posture as {@see LocalMcpWiringAudit}'s injected readers.
 */
class ConfigClassStringAudit implements SuggestsOperations
{
    public const CHECK = 'config-class-string';

    /** @var callable(): list<string> */
    private $configFiles;

    /** @var callable(string): (string|false) */
    private $readSource;

    /** @var callable(string): bool */
    private $resolves;

    /**
     * @param  string  $appRoot  the running host's app root (holds the config directory)
     * @param  string  $configDir  the config directory, relative to $appRoot (default `config`)
     * @param  (callable(): list<string>)|null  $configFiles  reader for the config file paths to scan (default: real `{appRoot}/{configDir}` walk)
     * @param  (callable(string): (string|false))|null  $readSource  reader for one file's source (default: real file read)
     * @param  (callable(string): bool)|null  $resolves  the existence predicate (default: the estate's class/enum/interface spelling)
     */
    public function __construct(
        public string $appRoot,
        public string $configDir = 'config',
        ?callable $configFiles = null,
        ?callable $readSource = null,
        ?callable $resolves = null,
    ) {
        $this->configFiles = $configFiles ?? fn (): array => $this->realConfigFiles();
        $this->readSource = $readSource ?? fn (string $file) => @file_get_contents($file);
        $this->resolves = $resolves
            ?? fn (string $fqn): bool => class_exists($fqn) || enum_exists($fqn) || interface_exists($fqn);
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $files = ($this->configFiles)();
        if ($files === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                "No config files under {$this->configDir} in this host — no class-strings to check.",
            ))];
        }

        $findings = [];
        $checked = 0;
        foreach ($files as $file) {
            $source = ($this->readSource)($file);
            if (! is_string($source) || $source === '') {
                continue;
            }
            $checked++;

            foreach ($this->harvestClassStrings($source) as $reference) {
                if (($this->resolves)($reference['fqn'])) {
                    continue;
                }

                $cited = $this->citePath($file);
                $findings[] = new FixableFinding(
                    Finding::fail(self::CHECK, sprintf(
                        "%s:%d references '%s' (%s), which does not resolve to a class, enum, or interface — a stale FQN in config feeds bindings two hops from any call site.",
                        $cited,
                        $reference['line'],
                        $reference['fqn'],
                        $reference['form'],
                    )),
                    OperationSuggestion::advisory(
                        "Locate {$reference['fqn']}'s real home and repoint {$cited}:{$reference['line']} with the rename tool (surgeon:trace / surgeon:move) — a stale config FQN is a trace-and-move question, not one splice.",
                        'rushing/laravel-surgeon',
                    ),
                );
            }
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                "Every class-string across {$checked} config file(s) resolves.",
            ));
        }

        return $findings;
    }

    /**
     * Statically harvest every class-string written in one config source: `Foo::class` const
     * fetches (name-resolved against the file's own imports, exactly where a stale `use` bites) and
     * FQN-shaped string literals. A source that does not parse yields nothing — an unparseable file
     * never aborts the audit run.
     *
     * @return list<array{fqn: string, line: int, form: string}>
     */
    public function harvestClassStrings(string $source): array
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        } catch (Error) {
            return [];
        }
        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->traverse($ast);

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<array{fqn: string, line: int, form: string}> */
            public array $references = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->name instanceof Node\Identifier
                    && strtolower($node->name->toString()) === 'class'
                    && $node->class instanceof Node\Name
                    && ! in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)) {
                    $resolved = $node->class->getAttribute('resolvedName');
                    $fqn = $resolved instanceof Node\Name ? $resolved->toString() : $node->class->toString();
                    $this->references[] = [
                        'fqn' => ltrim($fqn, '\\'),
                        'line' => $node->class->getStartLine(),
                        'form' => '::class',
                    ];
                } elseif ($node instanceof Node\Scalar\String_
                    && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $node->value) === 1) {
                    $this->references[] = [
                        'fqn' => ltrim($node->value, '\\'),
                        'line' => $node->getStartLine(),
                        'form' => 'string literal',
                    ];
                }

                return null;
            }
        };

        $walk = new NodeTraverser;
        $walk->addVisitor($visitor);
        $walk->traverse($ast);

        return $visitor->references;
    }

    /** The stable, human-legible path a finding cites — relative to the app root when under it. */
    private function citePath(string $file): string
    {
        $root = rtrim($this->appRoot, '/').'/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    /** @return list<string> the real `{appRoot}/{configDir}` walk, recursive, `.php` only */
    private function realConfigFiles(): array
    {
        $dir = rtrim($this->appRoot, '/').'/'.trim($this->configDir, '/');
        if (! is_dir($dir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
