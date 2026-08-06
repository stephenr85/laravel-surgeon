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

    expect($plan->edits)->toHaveCount(3)
        ->and($plan->skips)->toBe([])
        ->and($plan->unsupported)->toBeNull();

    // The two full-FQN reference sites (a use import + a test-fixture use) and the moved file's own
    // namespace line — the imported short names (typehint, ::class, `new Widget`) are no-ops.
    $categories = collect($plan->edits)->map(fn ($e) => $e->category->value)->sort()->values()->all();
    expect($categories)->toBe(['namespace-decl', 'test-fixture-reference', 'use-import']);

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

it('marks a basename rename unsupported rather than emit half-renamed code', function () {
    $plan = estatePlan('Vendor\\Old\\Widget', 'Vendor\\Other\\Gadget');

    expect($plan->isUnsupported())->toBeTrue()
        ->and($plan->unsupported)->toContain('basename rename');
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
