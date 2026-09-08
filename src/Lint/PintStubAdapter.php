<?php

namespace Rushing\Surgeon\Lint;

use Rushing\Surgeon\Audit\AuditEngine;

/**
 * The `pint-stubs` {@see LintStack}: Pint, aimed at the `*.php.stub` publish-only migration templates that
 * Pint's own traversal cannot see (prove-the-beam-starter ticket 13).
 *
 * ## The blind spot
 *
 * **Pint's file walk keys on the `*.php` name; a `.php.stub` never matches it.** So every package's own
 * lint gate has been blind to its migration stubs since they were written, and a stub could drift
 * arbitrarily far from the style its own package enforces with no gate going red anywhere.
 *
 * In this estate a stub is not dead template text. It is `vendor:publish`ed **verbatim** into a host's
 * `database/migrations/`, where it becomes subject to that host's `ci:check` — so **the stub's style is the
 * host's compliance**, and the failure surfaces maximally displaced from its cause: a different repo, days
 * later, on a file that is untracked there, after a command the host ran for unrelated reasons. That is
 * exactly how ticket 11 was found: a clean-room `laravel-beam-starter` install went red on five published
 * files nobody wrote and no commit contains.
 *
 * ## Why explicit operands and not a temp `.php` tree
 *
 * The ticket named three candidate mechanisms; the cheapest one turns out to work. **Pint's blindness is in
 * TRAVERSAL, not in parsing** — a `.php.stub` named as an explicit path operand is linted normally.
 * Measured 2026-09-08 against `laravel/pint` at this root, on one file of drifting bytes:
 *
 * ```
 * pint --test drift.php.stub    → fail: class_definition, braces_position, no_unused_imports, …
 * pint --test drift.php         → fail: the identical six fixers          (the `.php` control)
 * pint --test sub/              → passed                                  (the directory holding it)
 * ```
 *
 * So this adapter enumerates the stubs and hands them to Pint as operands. Three things that buys over the
 * copy-to-a-temp-`.php`-tree option: the file that is checked **is** the file that ships (no copy step to
 * drift, and no chance of linting a copy of a stale stub); the report names the **real path**, in the repo
 * that owns it, which is the whole point of moving the failure back from the host; and `--fix` rewrites the
 * stub in place, so the deterministic-fix half of surgeon's check/fix split works unchanged.
 *
 * ## Style only, deliberately — and what that leaves open
 *
 * Ticket 11's six failures were **five** Pint files and **one** phpstan `property.notFound`, so this closes
 * five of six and a style-only gate is honestly a partial answer. It is the answer available: measured
 * 2026-09-08 over the 30 packages shipping a `.php.stub`, **all 30 vendor `vendor/bin/pint`, and 0 have
 * phpstan installed or a `phpstan.neon` of any name**. A static-analysis stub gate would therefore have to
 * install and configure phpstan across 30 packages *before* it could analyse anything, and analysing a
 * bare `return new class extends Migration` in isolation needs a level, an autoloader and a bootstrap that
 * none of those roots has today. The estate's static-analysis substrate is at **host** tier, which is
 * precisely where the displaced failure already lands. Closing that half is a separate piece of work with a
 * real prerequisite; pretending a style gate covers it would be worse than saying it does not.
 *
 * ## Scope of the scan
 *
 * The same walk policy {@see AuditEngine} already uses for this population (`vendor`, `node_modules`,
 * `.git`, `storage` and the scratch trees are not walked) — that engine reads `.php.stub` as real source
 * for the same reason, and two instruments disagreeing about what the population *is* would be its own
 * defect. Test fixtures are included: all five non-migration `.php.stub` files in the estate are Pint-clean
 * today, and a root that ever wants a deliberately mis-styled fixture already has the registry's declared
 * escape hatch (`extra.surgeon.lint: {"except": ["pint-stubs"]}`) rather than needing a blanket carve-out
 * here.
 *
 * **Baseline at introduction, 2026-09-08:** 169 stubs across 30 packages; **43 in drift across 12
 * packages**. Ticket 11 was one live instance of twelve, not the population.
 */
class PintStubAdapter extends PintAdapter
{
    /** Directory segments never walked — the same set {@see AuditEngine::IGNORED_SEGMENTS} uses. */
    private const IGNORED_SEGMENTS = ['vendor', 'node_modules', '.git', 'storage', '.tests', '.scratch'];

    /** The one stub dialect that is valid PHP verbatim. `nav.ts.stub` and bare `*.stub` templates are not. */
    private const SUFFIX = '.php.stub';

    /** How many drifting paths the detail line names before it summarises the rest. */
    private const NAMED_IN_DETAIL = 5;

    public function name(): string
    {
        return 'pint-stubs';
    }

    /**
     * Applies where there is something to lint and something to lint it with. A root with no `.php.stub`
     * is not skipped-with-a-reason, it genuinely does not have this stack — 30 of the estate's ~117
     * composer roots do.
     */
    public function detect(string $root): bool
    {
        return $this->binary($root) !== null && $this->stubs($root) !== [];
    }

    /**
     * The stubs, root-relative and sorted, as Pint operands.
     *
     * @return list<string>
     */
    protected function operands(string $root): array
    {
        return $this->stubs($root);
    }

    /** @param  array{clean: bool, count: int, paths: list<string>}  $verdict */
    protected function violationDetail(array $verdict): string
    {
        $paths = $verdict['paths'];

        if ($paths === []) {
            return $verdict['count'].' stub(s) need styling';
        }

        $named = array_slice($paths, 0, self::NAMED_IN_DETAIL);
        $rest = count($paths) - count($named);

        return count($paths).' stub(s) need styling: '.implode(', ', $named).($rest > 0 ? " (+{$rest} more)" : '');
    }

    /**
     * Every `.php.stub` under the root, root-relative and sorted.
     *
     * Sorted so the operand list — and therefore the reported order — is stable across filesystems; a
     * report whose file order moves between runs reads as churn.
     *
     * @return list<string>
     */
    private function stubs(string $root): array
    {
        $root = rtrim($root, '/');
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), self::IGNORED_SEGMENTS, true);
                    }

                    return str_ends_with($current->getFilename(), self::SUFFIX);
                },
            ),
        );

        $found = [];
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                $found[] = ltrim(substr($entry->getPathname(), strlen($root)), '/');
            }
        }
        sort($found);

        return $found;
    }
}
