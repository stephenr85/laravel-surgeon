<?php

namespace Rushing\Surgeon\Audit;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * THE MOVE-AUDIT ENGINE (ticket 05) — the read-only heart of the surgeon.
 *
 * Given a {@see Target} and a set of roots, it enumerates EVERY touch-point across the configured
 * tree, classifies each by category + tier, and flags the ones a relocation would turn into an
 * upward composer edge. It is the anti-false-premise step: the FR campaign's "11-file" move was
 * really 19 files + 7 composers because nobody ran this first.
 *
 * It writes nothing (ticket 03's read-only insight path takes no git-clean precondition, and this
 * engine never touches the resolved stratum, let alone source). It is target-general and
 * operation-agnostic: relocation is the only *operation* the map builds on top of it, but the
 * finding-set it returns serves judgment-only insight hunts (middleware necessity, controller
 * redundancy, `chat`→`threads` stragglers) exactly as well — those just never run an operation.
 */
class AuditEngine
{
    /** Directory segments never worth scanning (dependencies + VCS + build noise). */
    private const IGNORED_SEGMENTS = ['vendor', 'node_modules', '.git', 'storage', '.tests', '.scratch'];

    public function __construct(
        private ReferenceCollector $collector = new ReferenceCollector,
    ) {}

    /**
     * Run the audit.
     *
     * @param  list<string>  $roots  absolute directory roots to scan (each is one repo/package)
     * @param  PackageGraph|null  $graph  the package graph for cycle-risk (skipped when null / no move)
     */
    public function audit(array $roots, Target $target, ?PackageGraph $graph = null): AuditReport
    {
        $roots = array_values(array_map(fn (string $r) => rtrim($r, '/'), $roots));

        // A directory target has no FQN predicate of its own: expand it to a pattern over the
        // symbols DECLARED under that directory, so "enumerate this dir's surface" becomes "who
        // references anything this dir declares" — the inbound-reference hunt that shrinks a triage.
        $matchTarget = $target->kind === TargetKind::Directory
            ? $this->expandDirectoryTarget($target)
            : $target;

        // A directory target derives *which symbols* to match from its own declarations, but the
        // hunt for references to them still sweeps every root (the inbound-reference question).
        $references = [];
        foreach ($roots as $root) {
            foreach ($this->phpFilesIn($root) as $file) {
                foreach ($this->collector->collect($file, $root, $matchTarget) as $ref) {
                    $references[] = $this->refine($ref, $graph, $target);
                }
            }
        }

        return new AuditReport($target, $roots, $references);
    }

    /**
     * Positional + graph refinement: reclassify a generic reference by the role of the file it lives
     * in (migration / test / route / config), then attach owning package + cycle-risk for a move.
     */
    private function refine(Reference $ref, ?PackageGraph $graph, Target $target): Reference
    {
        $category = $this->refineByFileRole($ref);
        $ref->category = $category;
        $ref->tier = $category->tier();

        if ($graph === null || ! $target->relocates() || $ref->category === ReferenceCategory::NamespaceDeclaration) {
            return $ref;
        }

        $ref->package = $graph->packageForRoot($ref->root);
        $destination = $ref->newFqn !== null ? $graph->packageForFqn($ref->newFqn) : null;

        if ($ref->package !== null && $destination !== null
            && $ref->package !== $destination
            && $graph->reaches($destination, $ref->package)) {
            $ref->cycleRisk = "relocating into {$destination} needs {$ref->package} → {$destination}, "
                ."but {$destination} already reaches {$ref->package} (upward composer edge)";
        }

        return $ref;
    }

    /** Only the generic class-load categories move by file role; the special syntactic ones stay put. */
    private function refineByFileRole(Reference $ref): ReferenceCategory
    {
        $generic = [
            ReferenceCategory::UseImport,
            ReferenceCategory::ClassConstFetch,
            ReferenceCategory::TypeHint,
            ReferenceCategory::NameReference,
        ];

        if (! in_array($ref->category, $generic, true)) {
            return $ref->category;
        }

        $path = '/'.ltrim($ref->relativePath, '/');
        $base = basename($path);

        if (str_contains($path, '/migrations/')) {
            return ReferenceCategory::MigrationReference;
        }
        if (str_contains($path, '/tests/') || str_contains($path, '/factories/') || str_contains($path, '/seeders/')
            || str_ends_with($base, 'Test.php') || str_ends_with($base, 'Factory.php') || str_ends_with($base, 'Seeder.php')) {
            return ReferenceCategory::TestFixtureReference;
        }
        if (str_contains($path, '/routes/') || str_contains($path, '/config/')) {
            return ReferenceCategory::RouteConfigReference;
        }

        return $ref->category;
    }

    /**
     * Read the fully-qualified names declared under a directory and fold them into one anchored
     * alternation pattern, so a {@see TargetKind::Directory} audit finds references to any of them.
     */
    private function expandDirectoryTarget(Target $target): Target
    {
        $symbols = [];
        foreach ($this->phpFilesIn($target->value) as $file) {
            foreach ($this->declaredSymbolsIn($file) as $fqn) {
                $symbols[] = preg_quote($fqn, '/');
            }
        }

        if ($symbols === []) {
            // Nothing declared → a pattern that matches nothing, keeping the run well-formed.
            return Target::pattern('/\A\z(?!)/');
        }

        return Target::pattern('/^(?:'.implode('|', array_unique($symbols)).')$/');
    }

    /**
     * The class-like FQNs declared in one file (via `namespacedName`), for directory expansion.
     *
     * @return list<string>
     */
    private function declaredSymbolsIn(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false || $code === '') {
            return [];
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->traverse($ast);

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $symbols = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null) {
                    $this->symbols[] = $node->namespacedName->toString();
                }

                return null;
            }
        };

        $walk = new NodeTraverser;
        $walk->addVisitor($visitor);
        $walk->traverse($ast);

        return $visitor->symbols;
    }

    /**
     * Recursively list `.php` files under a path (or the single file itself), skipping dependency /
     * VCS / build directories. Mirrors graphine's SeamGuard file walk with an ignore-list.
     *
     * @return list<string>
     */
    private function phpFilesIn(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [$path] : [];
        }
        if (! is_dir($path)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), self::IGNORED_SEGMENTS, true);
                    }

                    return $current->getExtension() === 'php';
                },
            ),
        );

        $files = [];
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
