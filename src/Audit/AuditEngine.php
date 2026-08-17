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

    /**
     * Filename suffixes this engine reads. `.php.stub` is here because a **publish-only migration
     * template is real source that references real symbols** — the estate ships its migrations as
     * timestamp-less `.php.stub` and re-stamps them into a host on `vendor:publish`, so a class the
     * template imports is a live reference that a relocation breaks exactly like any other.
     *
     * Before this, the walk tested `getExtension() === 'php'`, which is `'stub'` for
     * `create_threads_table.php.stub` — so the engine was structurally blind to that whole population.
     * The cost was measured during the beam-facade effort: 39 templates had to be swept by hand
     * (ticket 15) and, worse, `surgeon:trace` could not *see* them, which matters more than the write
     * — ticket 18 concluded it is trace, not move, that earns this engine its place, since tracing is
     * how an entirely unswept package was found at all. A find step with a permanent blind spot is a
     * find step you cannot trust to be exhaustive.
     *
     * **Matched as a full suffix, never as an extension.** The estate ships other `.stub` kinds —
     * `nav.ts.stub`, `prototype-host.tsx.stub`, and bare `particle-resource.stub` templates carrying
     * placeholder tokens — none of which are parseable PHP. `.php.stub` is the only stub dialect that
     * is valid PHP verbatim (39/39 `php -l` clean, and 62/62 on the dated population it publishes to).
     *
     * ## What this does NOT change
     * Nothing about writing. `refineByFileRole()` maps any path under `/migrations/` to
     * {@see ReferenceCategory::MigrationReference} → tier 2, and every `.php.stub` in the estate lives
     * under `database/migrations/` — so a stub reference surfaces in the report and the writer declines
     * it, with no per-finding tier override to force. That is the wall ticket 16 hit empirically on the
     * published copies, and it is why the beam-facade effort chose hand edits (option B3) over teaching
     * the engine this extension: the extension was never what blocked the write. This closes the
     * discovery gap only, which is the half that was actually costing.
     *
     * @var list<string>
     */
    private const SCANNABLE_SUFFIXES = ['.php', '.php.stub'];

    public function __construct(
        private ReferenceCollector $collector = new ReferenceCollector,
    ) {}

    /**
     * Run the audit.
     *
     * @param  list<string>  $roots  absolute directory roots to scan (each is one repo/package)
     * @param  PackageGraph|null  $graph  the package graph for cycle-risk (skipped when null / no move)
     * @param  MovingSet|null  $movingSet  the atomic cluster this target belongs to, when tracing a
     *                                     SET of symbols that relocate together — so an intra-set
     *                                     reference is evaluated against the POST-MOVE graph (both
     *                                     endpoints land in the destination) instead of being flagged
     *                                     as an upward edge. Null for a lone single-symbol relocation.
     */
    public function audit(array $roots, Target $target, ?PackageGraph $graph = null, ?MovingSet $movingSet = null): AuditReport
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
                    $references[] = $this->refine($ref, $graph, $target, $movingSet);
                }
            }
        }

        return new AuditReport($target, $roots, $references);
    }

    /**
     * Positional + graph refinement: reclassify a generic reference by the role of the file it lives
     * in (migration / test / route / config), then attach owning package + cycle-risk for a move.
     */
    private function refine(Reference $ref, ?PackageGraph $graph, Target $target, ?MovingSet $movingSet): Reference
    {
        $category = $this->refineByFileRole($ref);
        $ref->category = $category;
        $ref->tier = $category->tier();

        if ($graph === null || ! $target->relocates() || $ref->category === ReferenceCategory::NamespaceDeclaration) {
            return $ref;
        }

        // Cycle-risk is a question about the POST-MOVE graph: after the move lands, would repointing
        // this referrer at the new home need an upward composer edge? The referrer's post-move package
        // is normally the package that owns its file today — BUT in an atomic cluster-move the referrer
        // may ITSELF be a moving member (its own file relocates into the destination too). In that
        // case its post-move package IS the destination, so a reference from one moving member to
        // another is intra-destination, never a cross-tier edge. Only a reference whose referrer stays
        // put (not in the set) is tier-checked against the source graph.
        $referrerPackage = $movingSet !== null && $this->referrerIsMovingMember($ref, $movingSet)
            ? $graph->packageForFqn((string) $ref->newFqn)
            : $graph->packageForRoot($ref->root);

        $ref->package = $referrerPackage;
        $destination = $ref->newFqn !== null ? $graph->packageForFqn($ref->newFqn) : null;

        if ($referrerPackage !== null && $destination !== null
            && $referrerPackage !== $destination
            && $graph->reaches($destination, $referrerPackage)) {
            $ref->cycleRisk = "relocating into {$destination} needs {$referrerPackage} → {$destination}, "
                ."but {$destination} already reaches {$referrerPackage} (upward composer edge)";
        }

        return $ref;
    }

    /**
     * Is the file this reference lives in itself a moving member of the cluster? A file is a moving
     * member when the symbol it declares is in the set — detected by re-reading its own declarations
     * and asking the set. When true, the file relocates into the destination, so a reference it hosts
     * has its POST-MOVE package resolved at the destination, not at its source root.
     */
    private function referrerIsMovingMember(Reference $ref, MovingSet $movingSet): bool
    {
        foreach ($this->declaredSymbolsIn($ref->file) as $declared) {
            if ($movingSet->contains($declared)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only the generic class-load categories move by file role; the special syntactic ones stay put.
     *
     * STATED DECISION (particle-doctrine-followups #03): the docblock categories are deliberately
     * NOT in the generic list, so a Tier-1 docblock tag reference inside a test/migration/route
     * directory is NOT demoted the way an import there is. A docblock in a test is still a docblock
     * — its tag grammar is what makes the span safe to splice, and the file's role changes neither
     * that grammar nor who repoints it. This is a choice, not an omission.
     */
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
     * Recursively list scannable source files ({@see SCANNABLE_SUFFIXES}) under a path (or the single
     * file itself), skipping dependency / VCS / build directories. Mirrors graphine's SeamGuard file
     * walk with an ignore-list.
     *
     * @return list<string>
     */
    private function phpFilesIn(string $path): array
    {
        if (is_file($path)) {
            return $this->isScannable($path) ? [$path] : [];
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

                    return $this->isScannable($current->getFilename());
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

    /**
     * Whether a filename carries one of {@see SCANNABLE_SUFFIXES}. A full-suffix match, deliberately —
     * see that constant on why `.ts.stub` / `.tsx.stub` / bare `.stub` must not qualify.
     */
    private function isScannable(string $filename): bool
    {
        foreach (self::SCANNABLE_SUFFIXES as $suffix) {
            if (str_ends_with($filename, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
