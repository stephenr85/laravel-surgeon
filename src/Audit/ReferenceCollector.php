<?php

namespace Rushing\Surgeon\Audit;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Rushing\Graphine\Testing\SeamGuard;

/**
 * THE FIND-HALF — extends graphine's {@see SeamGuard} visitor pattern
 * from "does a forbidden import exist?" to "where, exactly, is this symbol referenced, and how?".
 *
 * Given one PHP file and a {@see Target}, it parses the AST once, resolves every name to its
 * fully-qualified form (so an imported short name `Fragment` used as `Fragment::class` is matched
 * against the target's canonical FQN), and emits one {@see Reference} per touch-point — each carrying
 * the exact byte span of the name so a future Tier-1 rewriter can splice without reprinting
 * (finding-01). It is strictly read-only.
 *
 * Categorisation here is *syntactic* — what node shape wraps the name (a `use`, a `::class`, a
 * typehint, a `morphMap([...])` value, an `app(\FQN::class)` runtime resolve, a `Gate::policy`
 * registration). The *positional* refinement (a reference living in a migration / test / route /
 * config file) is applied afterwards by {@see AuditEngine}, which knows the file's role in the tree.
 */
class ReferenceCollector
{
    /** Static/facade calls whose `::class` arguments are runtime container resolves (Tier 2). */
    public const RUNTIME_RESOLVE_CALLS = ['app', 'resolve', 'make', 'makeWith'];

    /** Calls whose `::class` array values are a morph / policy map keyed to a canonical FQN (Tier 2). */
    public const MORPH_MAP_CALLS = ['morphMap', 'enforceMorphMap'];

    /** Service-provider registration calls whose `::class` args are advisory-only handoffs (Tier 3). */
    public const PROVIDER_REGISTRATION_CALLS = ['policy', 'listen', 'subscribe', 'define', 'commands'];

    /**
     * Scan one file for references to $target. Returns raw, syntactically-categorised references;
     * $root / $relativePath are stamped so the report can render stable paths.
     *
     * @return list<Reference>
     */
    public function collect(string $file, string $root, Target $target): array
    {
        $code = @file_get_contents($file);
        if ($code === false || $code === '') {
            return [];
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        if ($ast === null) {
            return [];
        }

        // Resolve names WITHOUT replacing nodes: each Name keeps its original byte span (for the
        // splice) and gains a `resolvedName` attribute (the FQN, for matching). Class-likes gain a
        // `namespacedName` — how we catch the moved file's OWN declaration.
        $resolver = new NameResolver(null, ['replaceNodes' => false]);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($resolver);
        $traverser->traverse($ast);

        $visitor = new class($target, $code) extends NodeVisitorAbstract
        {
            /** @var list<Reference> */
            public array $references = [];

            /** @var list<Node> ancestor stack (excludes the node currently being entered) */
            private array $stack = [];

            /** @var Node\Name|null the current file's `namespace X;` name node, for span capture */
            private ?Node\Name $namespaceName = null;

            public function __construct(private Target $target, private string $code) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespaceName = $node->name;
                } elseif ($node instanceof Node\Stmt\Use_) {
                    $this->collectUse($node);
                } elseif ($node instanceof Node\Stmt\GroupUse) {
                    $this->collectGroupUse($node);
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    $this->collectDeclaration($node);
                } elseif ($node instanceof Node\Name) {
                    $this->collectName($node);
                }

                $this->stack[] = $node;

                return null;
            }

            public function leaveNode(Node $node): null
            {
                array_pop($this->stack);

                return null;
            }

            /** A `use A\B\C;` — the import that couples this file at class-load time (Tier 1). */
            private function collectUse(Node\Stmt\Use_ $node): void
            {
                foreach ($node->uses as $use) {
                    $fqn = $use->name->toString();
                    if ($this->target->matches($fqn)) {
                        $this->record($fqn, $use->name, ReferenceCategory::UseImport);
                    }
                }
            }

            /** A `use A\B\{C, D};` group import — matched per member on the joined prefix. */
            private function collectGroupUse(Node\Stmt\GroupUse $node): void
            {
                $prefix = $node->prefix->toString();
                foreach ($node->uses as $use) {
                    $fqn = $prefix.'\\'.$use->name->toString();
                    if ($this->target->matches($fqn)) {
                        $this->record($fqn, $use->name, ReferenceCategory::UseImport);
                    }
                }
            }

            /**
             * The file's OWN class/interface/enum/trait declaration. When the declared symbol is the
             * moved one, the touch-point that needs rewriting is the enclosing `namespace X;` line —
             * so the reference points at the namespace name span, not the class name.
             */
            private function collectDeclaration(Node\Stmt\ClassLike $node): void
            {
                $named = $node->namespacedName;
                if ($named === null || ! $this->target->matches($named->toString())) {
                    return;
                }

                // No namespace (global class) → nothing to rewrite for a relocation's namespace line.
                if ($this->namespaceName === null) {
                    return;
                }

                $this->record(
                    $named->toString(),
                    $this->namespaceName,
                    ReferenceCategory::NamespaceDeclaration,
                );
            }

            /** Any resolved name usage — the general class-load surface. Category by surrounding syntax. */
            private function collectName(Node\Name $node): void
            {
                // Names that are themselves imports/declarations are handled above; skip the ones
                // NameResolver leaves unresolved (they are not class-load references we can match).
                $resolved = $node->getAttribute('resolvedName');
                if ($resolved instanceof Node\Name) {
                    $fqn = $resolved->toString();
                } elseif ($node instanceof Node\Name\FullyQualified) {
                    $fqn = $node->toString();
                } else {
                    return;
                }

                if (! $this->target->matches($fqn)) {
                    return;
                }

                $this->record($fqn, $node, $this->categorise());
            }

            /**
             * Classify a matched name by its ancestor context. Special syntactic contexts
             * (morph map / provider registration / runtime resolve) win over the structural default.
             */
            private function categorise(): ReferenceCategory
            {
                $callNames = $this->ancestorCallNames();

                if (array_intersect($callNames, ReferenceCollector::MORPH_MAP_CALLS)) {
                    return ReferenceCategory::MorphMapEntry;
                }
                if (array_intersect($callNames, ReferenceCollector::PROVIDER_REGISTRATION_CALLS)) {
                    return ReferenceCategory::ProviderRegistration;
                }

                $parent = $this->parent();
                $withinClassConst = $parent instanceof Node\Expr\ClassConstFetch;

                if ($withinClassConst && array_intersect($callNames, ReferenceCollector::RUNTIME_RESOLVE_CALLS)) {
                    return ReferenceCategory::RuntimeApp;
                }
                if ($withinClassConst) {
                    return ReferenceCategory::ClassConstFetch;
                }

                if ($this->inTypePosition()) {
                    return ReferenceCategory::TypeHint;
                }

                return ReferenceCategory::NameReference;
            }

            /** Is the matched name in a typehint position (param / return / property / catch type)? */
            private function inTypePosition(): bool
            {
                foreach (array_reverse($this->stack) as $ancestor) {
                    if ($ancestor instanceof Node\Param
                        || $ancestor instanceof Node\Stmt\Property
                        || $ancestor instanceof Node\NullableType
                        || $ancestor instanceof Node\UnionType
                        || $ancestor instanceof Node\IntersectionType) {
                        return true;
                    }
                    // A function's return type is a direct child of the function node.
                    if ($ancestor instanceof Node\FunctionLike) {
                        return $ancestor->getReturnType() !== null
                            && $this->spanWithin($ancestor->getReturnType());
                    }
                    // Stop climbing once we hit a statement boundary that isn't a type container.
                    if ($ancestor instanceof Node\Stmt && ! $ancestor instanceof Node\Stmt\Property) {
                        return false;
                    }
                }

                return false;
            }

            /** Method / function names of every ancestor call, for special-context detection. */
            private function ancestorCallNames(): array
            {
                $names = [];
                foreach ($this->stack as $ancestor) {
                    if (($ancestor instanceof Node\Expr\StaticCall || $ancestor instanceof Node\Expr\MethodCall)
                        && $ancestor->name instanceof Node\Identifier) {
                        $names[] = $ancestor->name->toString();
                    } elseif ($ancestor instanceof Node\Expr\FuncCall && $ancestor->name instanceof Node\Name) {
                        $names[] = $ancestor->name->getLast();
                    }
                }

                return $names;
            }

            private function parent(): ?Node
            {
                return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
            }

            private function spanWithin(Node $container): bool
            {
                return $container->getStartFilePos() >= 0;
            }

            private function record(string $fqn, Node $span, ReferenceCategory $category): void
            {
                $start = $span->getStartFilePos();
                $end = $span->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return;
                }

                $this->references[] = new Reference(
                    category: $category,
                    tier: $category->tier(),
                    root: '',
                    file: '',
                    relativePath: '',
                    line: $span->getStartLine(),
                    matchedFqn: Target::normalize($fqn),
                    startPos: $start,
                    endPos: $end,
                    snippet: substr($this->code, $start, $end - $start + 1),
                    newFqn: $this->target->destinationFor($fqn),
                );
            }
        };

        $walk = new NodeTraverser;
        $walk->addVisitor($visitor);
        $walk->traverse($ast);

        // Stamp root / relative path now that scanning found them (the visitor is file-agnostic).
        $relative = str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;

        return array_map(
            fn (Reference $ref) => new Reference(
                category: $ref->category,
                tier: $ref->tier,
                root: $root,
                file: $file,
                relativePath: $relative,
                line: $ref->line,
                matchedFqn: $ref->matchedFqn,
                startPos: $ref->startPos,
                endPos: $ref->endPos,
                snippet: $ref->snippet,
                newFqn: $ref->newFqn,
            ),
            $visitor->references,
        );
    }
}
