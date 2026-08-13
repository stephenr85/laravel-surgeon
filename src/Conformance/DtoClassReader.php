<?php

namespace Rushing\Surgeon\Conformance;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Rushing\Surgeon\Audit\ReferenceCollector;

/**
 * Parses one PHP class file into a {@see DtoClass} — the single AST read both upstream-DTO audits share
 * (ticket 15). It reuses the same nikic/php-parser + {@see NameResolver} machinery {@see ReferenceCollector}
 * does, so an imported short name (`use Splicewire\Beam\Ux\Models\BeamUxEntry;`) is captured as its
 * fully-qualified form — the fact b1's "do all imports point into one downstream package?" check turns on.
 *
 * It reads exactly four things (see {@see DtoClass}): the class's own FQN, its resolved `use` imports, its
 * PUBLIC shape (public props + promoted ctor params + public method signatures), and its class-level
 * attribute short names. It deliberately reads no method bodies (b2 compares public *shape*, not
 * behaviour) and no deep type graph (b1's dependency read is the shallow same-file `use` read).
 */
class DtoClassReader
{
    /**
     * Read the first class-like declaration in $file into a {@see DtoClass}, or null when the file holds
     * no class (an interface/enum/trait is read too — the twin check is name-based, shape-based for the
     * fixable/advisory split). Returns null for an unparseable or classless file.
     */
    public function read(string $file): ?DtoClass
    {
        $code = @file_get_contents($file);
        if ($code === false || $code === '') {
            return null;
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            return null;
        }

        // Resolve names so `use` imports and attribute names surface fully-qualified where possible.
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->traverse($ast);

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $imports = [];

            public ?string $fqn = null;

            /** @var list<string> */
            public array $properties = [];

            /** @var list<string> */
            public array $methods = [];

            /** @var list<string> */
            public array $attributes = [];

            public ?string $extends = null;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $this->imports[] = ltrim($use->name->toString(), '\\');
                    }
                } elseif ($node instanceof Node\Stmt\GroupUse) {
                    $prefix = $node->prefix->toString();
                    foreach ($node->uses as $use) {
                        $this->imports[] = ltrim($prefix.'\\'.$use->name->toString(), '\\');
                    }
                } elseif ($node instanceof Node\Stmt\ClassLike && $this->fqn === null && $node->namespacedName !== null) {
                    $this->fqn = $node->namespacedName->toString();
                    $this->collectClassLike($node);
                }

                return null;
            }

            private function collectClassLike(Node\Stmt\ClassLike $class): void
            {
                // The resolved parent FQN — how the audits recognize an app shell that deliberately
                // SUBCLASSES its downstream twin (the sanctioned host-facing-projection end-state).
                if ($class instanceof Node\Stmt\Class_ && $class->extends !== null) {
                    $resolved = $class->extends->getAttribute('resolvedName');
                    $parent = $resolved instanceof Node\Name ? $resolved->toString() : $class->extends->toString();
                    $this->extends = ltrim($parent, '\\');
                }

                foreach ($class->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        $this->attributes[] = $attr->name->getLast();
                    }
                }

                foreach ($class->getProperties() as $property) {
                    if ($property->isPublic()) {
                        foreach ($property->props as $prop) {
                            $this->properties[] = $prop->name->toString();
                        }
                    }
                }

                foreach ($class->getMethods() as $method) {
                    if (! $method->isPublic()) {
                        continue;
                    }
                    // A promoted PUBLIC constructor param IS a public property (spatie-data's idiom).
                    if ($method->name->toLowerString() === '__construct') {
                        foreach ($method->params as $param) {
                            if (($param->flags & Modifiers::PUBLIC) && $param->var instanceof Node\Expr\Variable
                                && is_string($param->var->name)) {
                                $this->properties[] = $param->var->name;
                            }
                        }

                        continue; // the constructor's signature isn't part of the public method surface
                    }
                    $this->methods[] = $method->name->toString().'('.count($method->params).')';
                }
            }
        };

        $walk = new NodeTraverser;
        $walk->addVisitor($visitor);
        $walk->traverse($ast);

        if ($visitor->fqn === null) {
            return null;
        }

        return new DtoClass(
            fqn: $visitor->fqn,
            file: $file,
            imports: array_values(array_unique($visitor->imports)),
            shape: new PublicShape($visitor->properties, $visitor->methods),
            attributes: array_values(array_unique($visitor->attributes)),
            extends: $visitor->extends,
        );
    }
}
