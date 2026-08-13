<?php

use Rushing\Surgeon\Audit\AuditEngine;
use Rushing\Surgeon\Audit\Reference;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Rewrite\Psr4Resolver;
use Rushing\Surgeon\Rewrite\RewritePlanner;

/** Build a Reference for the decide() matrix — only the fields the planner reads matter. */
function planRef(ReferenceCategory $category, string $snippet, string $matched, ?string $new): Reference
{
    return new Reference(
        category: $category,
        tier: $category->tier(),
        root: '/tmp',
        file: '/tmp/x.php',
        relativePath: 'x.php',
        line: 1,
        matchedFqn: $matched,
        startPos: 0,
        endPos: strlen($snippet) - 1,
        snippet: $snippet,
        newFqn: $new,
    );
}

function estatePlan(string $old = 'Vendor\\Old\\Widget', string $new = 'Vendor\\New\\Widget')
{
    $report = (new AuditEngine)->audit(
        [dirname(__DIR__).'/Fixtures/estate'],
        Target::relocatingTo($old, $new),
    );

    return (new RewritePlanner)->plan($report);
}

it('rewrites a fully-written reference (use import / fully-qualified inline) to the whole new FQN', function () {
    $useImport = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::UseImport, 'Vendor\\Old\\Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );
    expect($useImport)->toBe(['action' => 'edit', 'text' => 'Vendor\\New\\Widget']);

    // A leading backslash on a fully-qualified inline name is preserved (its span includes the `\`).
    $fq = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::ClassConstFetch, '\\Vendor\\Old\\Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );
    expect($fq)->toBe(['action' => 'edit', 'text' => '\\Vendor\\New\\Widget']);
});

it('leaves an imported short name untouched when the basename is unchanged (the import repoint covers it)', function () {
    $decision = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::TypeHint, 'Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );

    expect($decision)->toBe(['action' => 'noop']);
});

it('splices a docblock short-name span basename-only, with no rewriter change (ticket 03)', function () {
    // A same-namespace rename: the {@see Widget} span holds only the short name, so it takes only
    // the new basename — decide()'s existing trailing-segment rule, no docblock special-casing.
    $rename = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::DocblockTagReference, 'Widget', 'Vendor\\Old\\Widget', 'Vendor\\Old\\Gadget'),
    );
    expect($rename)->toBe(['action' => 'edit', 'text' => 'Gadget']);

    // A pure relocation (basename unchanged): the short docblock span is a no-op, exactly like an
    // imported short name in code — the use-line repoint carries it.
    $relocation = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::DocblockTagReference, 'Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );
    expect($relocation)->toBe(['action' => 'noop']);

    // A fully-qualified docblock span takes the whole new FQN, leading backslash preserved.
    $fq = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::DocblockTagReference, '\\Vendor\\Old\\Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );
    expect($fq)->toBe(['action' => 'edit', 'text' => '\\Vendor\\New\\Widget']);
});

it('reports a prose docblock reference without ever planning a splice for it', function () {
    // Tier 2 by category — the planner's tier gate drops it before decide() is even consulted.
    $plan = estatePlan();

    $proseEdits = collect($plan->edits)
        ->filter(fn ($e) => $e->category === ReferenceCategory::DocblockProseReference);

    expect($proseEdits)->toBeEmpty()
        ->and($plan->skips)->toBeEmpty();
});

it('rewrites the namespace-declaration span to the destination namespace, never the FQN', function () {
    $decision = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::NamespaceDeclaration, 'Vendor\\Old', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );

    expect($decision)->toBe(['action' => 'edit', 'text' => 'Vendor\\New']);
});

it('refuses a group-use member relocated across namespaces (guided path, not a byte-splice)', function () {
    $decision = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::UseImport, 'Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );

    expect($decision['action'])->toBe('skip')
        ->and($decision['reason'])->toContain('group-use member');
});

it('refuses a partially-qualified inline name leaning on an unmanaged import', function () {
    $decision = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::NameReference, 'Old\\Widget', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );

    expect($decision['action'])->toBe('skip')
        ->and($decision['reason'])->toContain('partially-qualified');
});

it('leaves an aliased inline name alone (the use ... as line carries the move)', function () {
    $decision = (new RewritePlanner)->decide(
        planRef(ReferenceCategory::ClassConstFetch, 'W', 'Vendor\\Old\\Widget', 'Vendor\\New\\Widget'),
    );

    expect($decision)->toBe(['action' => 'noop']);
});

it('plans exactly the Tier-1 edits for a relocation across the estate, Tier 2/3 untouched', function () {
    $plan = estatePlan();

    expect($plan->edits)->toHaveCount(5)
        ->and($plan->skips)->toBe([])
        ->and($plan->unsupported)->toBeNull();

    // The three full-FQN reference sites (two use imports + a test-fixture use), the moved file's own
    // namespace line, and Documented's fully-qualified {@see} tag — the imported short names
    // (typehint, ::class, `new Widget`, short docblock tags) are no-ops.
    $categories = collect($plan->edits)->map(fn ($e) => $e->category->value)->sort()->values()->all();
    expect($categories)->toBe(['docblock-tag-reference', 'namespace-decl', 'test-fixture-reference', 'use-import', 'use-import']);

    $nsEdit = collect($plan->edits)->firstWhere('category', ReferenceCategory::NamespaceDeclaration);
    expect($nsEdit->oldText)->toBe('Vendor\\Old')->and($nsEdit->newText)->toBe('Vendor\\New');
});

it('never touches a Tier-2 or Tier-3 reference', function () {
    $plan = estatePlan();

    foreach ($plan->edits as $edit) {
        expect($edit->category->tier()->value)->toBe('tier-1');
    }
    // The morph-map (Tier 2), runtime app() (Tier 2), migration (Tier 2), provider registration and
    // route/config (Tier 3) sites in the estate produce no edits.
    $files = collect($plan->edits)->pluck('relativePath')->all();
    expect($files)->not->toContain('src/EstateProvider.php')
        ->and($files)->not->toContain('routes/web.php');
});

it('plans a same-namespace class rename: declaration token + short-name repoints, no refusal', function () {
    // Foo in NsA\Sub, referenced from a sibling by `use NsA\Sub\Foo; new Foo(); Foo::class; fn(Foo $f)`,
    // plus a control reference to an unrelated NsA\Sub\Foobar that must stay untouched.
    $root = surgeon_tmp('rename-same-ns');
    try {
        surgeon_write($root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['NsA\\' => 'src/']]]));
        surgeon_write($root.'/src/Sub/Foo.php', "<?php\n\nnamespace NsA\\Sub;\n\nclass Foo {}\n");
        surgeon_write($root.'/src/Sub/Foobar.php', "<?php\n\nnamespace NsA\\Sub;\n\nclass Foobar {}\n");
        surgeon_write(
            $root.'/src/Consumer.php',
            "<?php\n\nnamespace NsA;\n\nuse NsA\\Sub\\Foo;\nuse NsA\\Sub\\Foobar;\n\n"
            ."class Consumer\n{\n    public function x(Foo \$f): Foo { return new Foo(); }\n"
            ."    public function id() { return Foo::class; }\n"
            ."    public function control() { return new Foobar(); }\n}\n",
        );

        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('NsA\\Sub\\Foo', 'NsA\\Sub\\Bar'));
        $plan = (new RewritePlanner(new Psr4Resolver([$root])))->plan($report);

        expect($plan->isUnsupported())->toBeFalse()
            ->and($plan->skips)->toBe([]);

        // The declaration token `class Foo` → `class Bar` is planned (a NameReference edit on Foo.php).
        $declEdit = collect($plan->edits)->first(fn ($e) => $e->relativePath === 'src/Sub/Foo.php' && $e->oldText === 'Foo');
        expect($declEdit)->not->toBeNull()
            ->and($declEdit->newText)->toBe('Bar');

        // The consumer's `use` line takes the whole new FQN; every short-name usage becomes Bar.
        $consumerEdits = collect($plan->edits)->where('relativePath', 'src/Consumer.php');
        expect($consumerEdits->firstWhere('oldText', 'NsA\\Sub\\Foo')->newText)->toBe('NsA\\Sub\\Bar')
            ->and($consumerEdits->where('oldText', 'Foo')->pluck('newText')->unique()->all())->toBe(['Bar'])
            // The control `Foobar` (use, typehint, new) is never touched.
            ->and($consumerEdits->contains(fn ($e) => str_contains($e->oldText, 'Foobar')))->toBeFalse();

        // A basename-only physical move in the same directory.
        expect($plan->move)->not->toBeNull()
            ->and($plan->move->fromRelative)->toBe('src/Sub/Foo.php')
            ->and($plan->move->toRelative)->toBe('src/Sub/Bar.php');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('renames WITH a namespace change too: declaration token + namespace line both rewrite, physical move to the new home', function () {
    // Foo\Widget → Bar\Gadget — the namespace AND the basename change. An imported consumer's `use`
    // line + short usages repoint; the declaration token and its own namespace line both rewrite.
    $root = surgeon_tmp('rename-ns-change');
    try {
        surgeon_write($root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'src/']]]));
        surgeon_write($root.'/src/Foo/Widget.php', "<?php\n\nnamespace Acme\\Foo;\n\nclass Widget {}\n");
        surgeon_write($root.'/src/Consumer.php', "<?php\n\nnamespace Acme;\n\nuse Acme\\Foo\\Widget;\n\nclass Consumer\n{\n    public function m(Widget \$w) { return Widget::class; }\n}\n");

        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('Acme\\Foo\\Widget', 'Acme\\Bar\\Gadget'));
        $plan = (new RewritePlanner(new Psr4Resolver([$root])))->plan($report);

        expect($plan->isUnsupported())->toBeFalse();

        // The declaration token renamed even though the namespace also moved.
        $declEdit = collect($plan->edits)->first(fn ($e) => $e->relativePath === 'src/Foo/Widget.php' && $e->oldText === 'Widget');
        expect($declEdit)->not->toBeNull()->and($declEdit->newText)->toBe('Gadget');
        // Its own namespace line moved to the new namespace.
        expect(collect($plan->edits)->contains(fn ($e) => $e->oldText === 'Acme\\Foo' && $e->newText === 'Acme\\Bar'))->toBeTrue();

        // The imported consumer repoints (use → full new FQN; short names → new basename).
        $consumerEdits = collect($plan->edits)->where('relativePath', 'src/Consumer.php');
        expect($consumerEdits->firstWhere('oldText', 'Acme\\Foo\\Widget')->newText)->toBe('Acme\\Bar\\Gadget')
            ->and($consumerEdits->where('oldText', 'Widget')->pluck('newText')->unique()->all())->toBe(['Gadget']);

        // The physical move lands the file at its NEW namespace's PSR-4 path with the new basename.
        expect($plan->move)->not->toBeNull()
            ->and($plan->move->fromRelative)->toBe('src/Foo/Widget.php')
            ->and($plan->move->toRelative)->toBe('src/Bar/Gadget.php');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('throws when handed an insight-only (non-relocation) finding-set', function () {
    $report = (new AuditEngine)->audit(
        [dirname(__DIR__).'/Fixtures/estate'],
        Target::symbol('Vendor\\Old\\Widget'),
    );

    expect(fn () => (new RewritePlanner)->plan($report))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a same-namespace short reference with no import rather than silently breaking it', function () {
    // Two files in one namespace; the sibling references the moved class by bare name, resolved by
    // namespace proximity — NOT by an import. Relocating the class would empty the old namespace out
    // from under that reference, so a byte-splice can't keep it valid: it must be handed off.
    $root = surgeon_tmp('samens');
    try {
        surgeon_write($root.'/Book.php', "<?php\n\nnamespace Acme\\Strat;\n\nclass Book {}\n");
        surgeon_write($root.'/Decoz.php', "<?php\n\nnamespace Acme\\Strat;\n\nclass Decoz\n{\n    public function make() { return Book::class; }\n}\n");

        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('Acme\\Strat\\Book', 'Acme\\Moved\\Book'));
        $plan = (new RewritePlanner)->plan($report);

        // The declaration's namespace line is an edit; the sibling's bare `Book` is a skip, not a no-op.
        expect(collect($plan->edits)->pluck('relativePath')->all())->toBe(['Book.php'])
            ->and($plan->skips)->toHaveCount(1)
            ->and($plan->skips[0]->relativePath)->toBe('Decoz.php')
            ->and($plan->skips[0]->reason)->toContain('namespace context');
    } finally {
        surgeon_rrmdir($root);
    }
});

it('keeps an imported short name a no-op (the in-file use line repoints it)', function () {
    $root = surgeon_tmp('imported');
    try {
        surgeon_write($root.'/Book.php', "<?php\n\nnamespace Acme\\Strat;\n\nclass Book {}\n");
        surgeon_write($root.'/Consumer.php', "<?php\n\nnamespace Acme\\Other;\n\nuse Acme\\Strat\\Book;\n\nclass Consumer\n{\n    public function make() { return Book::class; }\n}\n");

        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('Acme\\Strat\\Book', 'Acme\\Moved\\Book'));
        $plan = (new RewritePlanner)->plan($report);

        // Edits: the moved file's namespace line + the consumer's `use` line. The `Book::class` short
        // name is a no-op because the repointed import covers it. No skips.
        $edits = collect($plan->edits)->pluck('relativePath')->sort()->values()->all();
        expect($edits)->toBe(['Book.php', 'Consumer.php'])
            ->and($plan->skips)->toBe([]);
    } finally {
        surgeon_rrmdir($root);
    }
});

it('resolves a new FQN to whichever root owns it (cross-root Psr4Resolver::resolve)', function () {
    $app = surgeon_tmp('resolve-app');
    $pkg = surgeon_tmp('resolve-pkg');
    try {
        surgeon_write($app.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
        surgeon_write($pkg.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Vendor\\Pkg\\' => 'src/']]]));

        $resolver = new Psr4Resolver([$app, $pkg]);

        // The package FQN resolves into the PACKAGE root, not the app root — the cross-repo fact.
        $pkgHit = $resolver->resolve('Vendor\\Pkg\\Widget');
        expect($pkgHit['root'])->toBe($pkg)->and($pkgHit['path'])->toBe($pkg.'/src/Widget.php');

        // An app FQN resolves back into the app root.
        $appHit = $resolver->resolve('App\\Scratch\\Widget');
        expect($appHit['root'])->toBe($app)->and($appHit['path'])->toBe($app.'/app/Scratch/Widget.php');

        // A namespace no root autoloads resolves nowhere.
        expect($resolver->resolve('Nobody\\Owns\\This'))->toBeNull();
    } finally {
        surgeon_rrmdir($app);
        surgeon_rrmdir($pkg);
    }
});

it('derives the physical move from PSR-4 when the moved file is autoloadable', function () {
    $root = surgeon_psr4_root('planmove');

    try {
        $report = (new AuditEngine)->audit([$root], Target::relocatingTo('App\\Old\\Widget', 'App\\New\\Widget'));
        $plan = (new RewritePlanner(new Psr4Resolver([$root])))->plan($report);

        expect($plan->move)->not->toBeNull()
            ->and($plan->move->fromRelative)->toBe('src/Old/Widget.php')
            ->and($plan->move->toRelative)->toBe('src/New/Widget.php');
    } finally {
        surgeon_rrmdir($root);
    }
});
