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
 *
 * A SECOND, comment-token pass follows the AST walk: docblock/comment references (a `{@see Foo}`,
 * an `@var Foo`, a prose mention of `Foo`) are structurally invisible to the node walk, so every
 * rename used to leave them behind for a human to grep. The pass scans raw comment SPANS (never the
 * parser's attached docblocks — an orphan `@var` above a plain assignment is attached
 * inconsistently), and its false-positive gate is load-bearing: a bare short name is recorded only
 * when the file's own import table or current namespace — both already in hand from the walk —
 * resolves it to a matching FQN. A common word in an unrelated file does not resolve, so it never
 * enters the set.
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

            /** @var array<string, string> the file's own import table: short alias => FQN (class-like imports only) */
            public array $imports = [];

            /** @var string the file's current namespace ('' when global) */
            public string $namespace = '';

            /** @var list<Node> ancestor stack (excludes the node currently being entered) */
            private array $stack = [];

            /** @var Node\Name|null the current file's `namespace X;` name node, for span capture */
            private ?Node\Name $namespaceName = null;

            public function __construct(private Target $target, private string $code) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespaceName = $node->name;
                    $this->namespace = $node->name?->toString() ?? '';
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
                    $this->tabulateImport($node->type, $use, $fqn);
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
                    $this->tabulateImport($node->type, $use, $fqn);
                    if ($this->target->matches($fqn)) {
                        $this->record($fqn, $use->name, ReferenceCategory::UseImport);
                    }
                }
            }

            /**
             * Keep the file's own import table (alias => FQN) for the comment-token pass — class-like
             * imports only; function/const imports can never be a docblock type reference.
             */
            private function tabulateImport(int $statementType, Node\UseItem $use, string $fqn): void
            {
                $effective = $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $statementType;
                if ($effective !== Node\Stmt\Use_::TYPE_UNKNOWN && $effective !== Node\Stmt\Use_::TYPE_NORMAL) {
                    return;
                }

                $this->imports[$use->getAlias()->toString()] = $fqn;
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

                $this->recordSpan($fqn, $start, $end, $span->getStartLine(), $category);
            }

            /**
             * The raw-offset overload of {@see record()} — the comment-token pass has byte offsets
             * (a span inside a comment token), never a Node to read them from.
             */
            public function recordSpan(string $fqn, int $start, int $end, int $line, ReferenceCategory $category): void
            {
                $this->references[] = new Reference(
                    category: $category,
                    tier: $category->tier(),
                    root: '',
                    file: '',
                    relativePath: '',
                    line: $line,
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

        // Second pass — comment tokens. Docblock tags and prose mentions are invisible to the node
        // walk; merged into the reference set here, BEFORE path stamping, so they ride the same
        // stamping/refinement pipeline as every AST-shaped reference. Comment SPANS from the raw
        // token stream, deliberately not node-attached docblocks (orphan `@var` attachment is
        // parser-inconsistent).
        foreach ($parser->getTokens() as $token) {
            if ($token->id !== \T_DOC_COMMENT && $token->id !== \T_COMMENT) {
                continue;
            }

            foreach ($this->commentOccurrences($token->text, $target, $visitor->imports, $visitor->namespace) as $occurrence) {
                $start = $token->pos + $occurrence['offset'];
                $visitor->recordSpan(
                    $occurrence['fqn'],
                    $start,
                    $start + strlen($occurrence['written']) - 1,
                    $token->line + substr_count($token->text, "\n", 0, $occurrence['offset']),
                    $occurrence['category'],
                );
            }
        }

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

    /**
     * Scan ONE comment token's text for references to the target — the docblock/prose detection the
     * AST walk cannot see. Two written forms are hunted:
     *
     *  - a (fully-)qualified name (`\A\B\C` or `A\B\C`), matched against the target directly;
     *  - a bare short name, admitted ONLY through the false-positive gate: it must resolve via the
     *    file's own import table, or as a sibling in the file's current namespace, to an FQN the
     *    target matches. An unrelated common word resolves to nothing relevant and never enters.
     *
     * Category is decided by POSITION: an occurrence inside the type slot of a docblock tag
     * (`{@see …}` / `@var` / `@param` / `@return` / `@throws`) is a {@see ReferenceCategory::DocblockTagReference}
     * (Tier 1 — tag grammar makes the span unambiguous); anywhere else in the comment it is a
     * {@see ReferenceCategory::DocblockProseReference} (Tier 2 — reported, never auto-spliced).
     *
     * @param  array<string, string>  $imports  the file's own import table (short alias => FQN)
     * @return list<array{offset: int, written: string, fqn: string, category: ReferenceCategory}>
     */
    private function commentOccurrences(string $text, Target $target, array $imports, string $namespace): array
    {
        $occurrences = [];
        $tagRegions = $this->tagTypeRegions($text);

        $categorise = function (int $offset, int $length) use ($tagRegions): ReferenceCategory {
            foreach ($tagRegions as [$start, $end]) {
                if ($offset >= $start && $offset + $length <= $end) {
                    return ReferenceCategory::DocblockTagReference;
                }
            }

            return ReferenceCategory::DocblockProseReference;
        };

        // Qualified forms — two or more segments, optional leading backslash.
        preg_match_all('/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $text, $qualified, PREG_OFFSET_CAPTURE);
        foreach ($qualified[0] as [$written, $offset]) {
            if ($target->matches($written)) {
                $occurrences[] = [
                    'offset' => $offset,
                    'written' => $written,
                    'fqn' => Target::normalize($written),
                    'category' => $categorise($offset, strlen($written)),
                ];
            }
        }

        // Bare short names — never part of a qualified run (the lookarounds), never a `$variable`.
        // THE GATE: resolve against the file's import table, then its namespace; only a resolution
        // the target matches is recorded.
        preg_match_all('/(?<![\w\\\\$])[A-Za-z_][A-Za-z0-9_]*(?![\w\\\\])/', $text, $bare, PREG_OFFSET_CAPTURE);
        foreach ($bare[0] as [$written, $offset]) {
            $fqn = $imports[$written] ?? ($namespace === '' ? $written : $namespace.'\\'.$written);
            if ($target->matches($fqn)) {
                $occurrences[] = [
                    'offset' => $offset,
                    'written' => $written,
                    'fqn' => $fqn,
                    'category' => $categorise($offset, strlen($written)),
                ];
            }
        }

        usort($occurrences, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return $occurrences;
    }

    /**
     * The byte regions of every docblock tag's TYPE SLOT in one comment text — `{@see X}` inline
     * tags and `@var`/`@param`/`@return`/`@throws`/`@see` line tags. The slot starts after the tag
     * keyword's whitespace and runs across type-expression characters only (`Foo`, `\A\B`, `?Foo`,
     * `Foo|Bar`, `Foo[]`, `Collection<Foo>`, `Foo::bar()`), so the first prose word ends it — a
     * `@param int $x the Widget count` puts only `int` in the slot, leaving `Widget` to prose.
     *
     * @return list<array{0: int, 1: int}> [start, end) byte offsets into $text
     */
    private function tagTypeRegions(string $text): array
    {
        $regions = [];
        preg_match_all('/(?:\{@(?:see|link)|@(?:var|param|return|throws|see))[ \t]+/', $text, $tags, PREG_OFFSET_CAPTURE);

        foreach ($tags[0] as [$tag, $offset]) {
            $start = $offset + strlen($tag);
            $end = $start;
            $length = strlen($text);
            while ($end < $length && preg_match('/[A-Za-z0-9_\\\\|&?<>,\[\]():]/', $text[$end]) === 1) {
                $end++;
            }
            if ($end > $start) {
                $regions[] = [$start, $end];
            }
        }

        return $regions;
    }
}
