<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * **b3 — the published-migration-drift audit** (beam-facade ticket 116). A host's
 * `database/migrations/**` holds **published copies** — snapshots stamped at install time of migrations
 * a package ships as source. Publishing is a COPY, so the moment the package edits its source the host's
 * copy is a fossil, and *nothing in the estate reports it*. This audit enumerates, host-side, every
 * published copy whose content diverges from the package file it was published from.
 *
 * **The third member of the b1/b2 family, not a fourth branch of either.** {@see UpstreamDtoAudit} (b1)
 * asks "should this move down?"; {@see StaleDownstreamDuplicateAudit} (b2) asks "did the move already
 * happen downstream, leaving a stale app copy behind?". b3 asks the same *stale-copy* question about a
 * different artifact class — a published migration rather than a DTO — and inherits b2's **posture
 * verbatim while sharing none of its machinery**:
 *  - b2 matches by short name + namespace tail ({@see InstalledPackages::findTwin()}). A migration is an
 *    anonymous class in a timestamped file with no namespace, so the match here is the **basename with
 *    the timestamp prefix stripped** — the same stem spatie/laravel-package-tools re-finds a published
 *    copy by.
 *  - b2 diffs {@see PublicShape} — public props, promoted ctor params, method signatures, **never
 *    bodies**. A migration's entire meaning *is* its body, so the comparison here is content, and
 *    teaching `PublicShape` to read bodies would break the one thing its docblock promises.
 *
 * ## Advisory, never gating. Reports, never repairs.
 *
 * Identical content is simply **not flagged**. Everything it does report is advisory
 * ({@see OperationSuggestion::advisory()}) and it nominates no `surgeon:*` operation, because the repair
 * is genuinely agentic: reset-and-republish, republish-over-the-stale-copy, or inject a correctly-ordered
 * copy depends on what diverged and what the host holds. The remedy also lands in the host's **own** tree,
 * and a package that silently rewrote a host's migrations would be a worse failure mode than the one it
 * reports. See `splicewire/laravel-beam`'s `docs/agents/migration-publish-ordering.convention.md`,
 * "A published copy is a snapshot, not the source of truth", which is the prose this audit enforces.
 *
 * ## Three deliberate limits
 *
 * - **No database, ever.** This is a *publishing* problem, not a schema problem: it never parses DDL and
 *   never opens a connection, which is why it is not homed in `rushing/laravel-schema-convergence`.
 * - **No git.** Content divergence is the decidable fact; *why* they diverged is narrative the repair does
 *   not depend on. It works at a root with a shallow clone or no `.git` at all.
 * - **One direction only.** A package file with no published copy is "not published yet", which is the
 *   installer's question, not this one.
 *
 * ## What it deliberately cannot tell apart
 *
 * **A stale copy and a copy the host edited on purpose look identical from here**, and that is the seam,
 * not a gap in it. Measured at the flagship host: `laravel/passport`'s published
 * `create_oauth_auth_codes_table` differs from its source by exactly one line — `foreignId('user_id')`
 * became `foreignUuid('user_id')` — which is a host that chose uuid user keys, precisely what publishing
 * a migration is *for*. Nothing in a content comparison distinguishes that from a package that moved on
 * and left a fossil behind, and no heuristic could without knowing the intent. So this reports both and
 * rules on neither: it is the reason the finding is advisory, the reason it nominates no operation, and
 * the reason its detail says "presumed inferior" rather than "wrong".
 *
 * ## Two measured facts that shaped the mechanism
 *
 * **Provenance comes from `installed.json`, never from walking `vendor/`.** In this estate `vendor/<vendor>/<pkg>`
 * is routinely a symlink into a co-dev checkout that carries its own `vendor/`, so a symlink-following
 * recursive walk of `vendor/` does not terminate in useful time (measured: >120s at the flagship host, no
 * result). {@see InstalledPackages::fromHostRoot()} already resolves every installed package's real install
 * path from composer's own record, so the scan is bounded to one `database/migrations` glob per package.
 *
 * **Matching is basename-only, which is deliberately WIDER than package-tools' own re-find.**
 * `generateMigrationName` globs `database_path('migrations/<declared dir>/*.php')` — its stem match is
 * scoped to the directory the package declares. Scoping this audit the same way would silence the estate's
 * most common real drift, where a package *moved* a migration between publish destinations (flat and
 * `tenant/` twins collapsed into one `shared/` file) and the host still runs the copy at the old location.
 * A directory difference is therefore reported as part of the drift rather than used to suppress it.
 */
class PublishedMigrationDriftAudit implements SuggestsOperations
{
    public const CHECK = 'published-migration-drift';

    /**
     * @param  string  $appRoot  the host app root (holds `vendor/composer/installed.json` + the published set)
     * @param  string  $migrationsDir  the published migrations root, relative to $appRoot
     */
    public function __construct(
        public string $appRoot,
        public string $migrationsDir = 'database/migrations',
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $root = rtrim($this->appRoot, '/').'/'.trim($this->migrationsDir, '/');

        if (! is_dir($root)) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                "No {$this->migrationsDir} directory in this host — no published migration copies to check.",
            ))];
        }

        $sources = $this->packageSources();

        $findings = [];
        $checked = 0;

        foreach ($this->migrationFiles($root) as $file) {
            $relative = ltrim(substr($file, strlen($root)), '/');
            $stem = $this->stem($relative);
            $candidates = $sources[$stem] ?? [];

            if ($candidates === []) {
                continue; // no package ships this stem — a host's own migration, silent
            }

            $checked++;
            $published = $this->normalize((string) @file_get_contents($file));

            foreach ($candidates as $candidate) {
                if ($this->normalize((string) @file_get_contents($candidate['file'])) === $published) {
                    continue 2; // byte-identical to a source it could have come from — not flagged
                }
            }

            $findings[] = $this->drifted($relative, $published, $candidates);
        }

        if ($findings === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.none',
                $checked === 0
                    ? "No migration under {$this->migrationsDir} was published by an installed package — nothing to compare."
                    : "All {$checked} published migration copies under {$this->migrationsDir} match the package source they were published from.",
            ))];
        }

        return $findings;
    }

    /**
     * One advisory finding per drifted copy — the repair is chosen per instance, so the enumeration is per
     * instance too. The detail names every package file the stem could have come from (a stem shipped by
     * two packages is a real estate shape, e.g. a family stub twinning a third party's), the size delta,
     * the first line that differs, and a directory move when the source publishes elsewhere now.
     *
     * @param  list<array{package: string, dir: string, file: string}>  $candidates
     */
    private function drifted(string $relative, string $published, array $candidates): FixableFinding
    {
        $names = array_map(
            fn (array $c) => $c['package'].'/database/migrations/'.$c['dir'].basename($c['file']),
            $candidates,
        );

        $reference = $candidates[0];
        $source = $this->normalize((string) @file_get_contents($reference['file']));

        $publishedDir = str_contains($relative, '/') ? dirname($relative).'/' : '';
        $moved = $publishedDir !== $reference['dir']
            ? sprintf(' The source now publishes into `%s`, this copy is at `%s`.', $reference['dir'] ?: './', $publishedDir ?: './')
            : '';

        return new FixableFinding(
            Finding::warn(
                self::CHECK.'.drifted',
                sprintf(
                    'Published copy %s has DRIFTED from its source (%s) — %s.%s The host copy is a snapshot '.
                    'stamped at install time and is presumed INFERIOR to the package source; gap-analyse the '.
                    'divergence and choose the repair per instance (reset and republish, republish over the '.
                    'stale copy, or inject a correctly-ordered copy). There is no default.',
                    $relative,
                    implode(' | ', $names),
                    $this->describeDrift($published, $source),
                    $moved,
                ),
            ),
            OperationSuggestion::advisory(
                sprintf('Reconcile the published copy %s against %s by hand.', $relative, implode(' | ', $names)),
                $reference['package'],
            ),
        );
    }

    /**
     * A cheap, decidable descriptor of the divergence — line counts plus the first differing line number.
     * Deliberately NOT a semantic diff: a migration is a body, no shape reader abstracts it, and the human
     * opens the two files anyway. Its job is to make the finding sortable and to prove the audit compared
     * something real.
     */
    private function describeDrift(string $published, string $source): string
    {
        $a = explode("\n", $published);
        $b = explode("\n", $source);

        $first = 0;
        $max = max(count($a), count($b));
        for ($i = 0; $i < $max; $i++) {
            if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
                $first = $i + 1;
                break;
            }
        }

        return sprintf(
            'first difference at line %d, %d published lines vs %d in source',
            $first,
            count($a),
            count($b),
        );
    }

    /**
     * Every migration an installed package ships, keyed by stem. Both extensions are collected: a package
     * may ship a timestamp-less `.php.stub` (package-tools re-stamps it on publish) or a real-timestamped
     * `.php` (published verbatim) — package-tools supports both and this estate uses both.
     *
     * Depth is two — `database/migrations/*` and `database/migrations/<dir>/*` — which covers the
     * publish-destination subdirectories the estate actually declares (`shared/`, `tenant/`) without a
     * recursive walk that would follow a co-dev symlink into a nested `vendor/`.
     *
     * @return array<string, list<array{package: string, dir: string, file: string}>>
     */
    private function packageSources(): array
    {
        $sources = [];

        foreach (InstalledPackages::fromHostRoot($this->appRoot)->packages as $package) {
            $base = rtrim($package['path'], '/').'/database/migrations';
            if (! is_dir($base)) {
                continue;
            }

            foreach ($this->migrationFiles($base, stubs: true) as $file) {
                $relative = ltrim(substr($file, strlen($base)), '/');
                $sources[$this->stem($relative)][] = [
                    'package' => $package['name'],
                    'dir' => str_contains($relative, '/') ? dirname($relative).'/' : '',
                    'file' => $file,
                ];
            }
        }

        return $sources;
    }

    /**
     * The match key: the basename with the leading `Y_m_d_His_` publish stamp and the `.php` / `.php.stub`
     * extension stripped. Identical on both sides of the comparison, which is what lets a copy stamped
     * `2026_07_16_100000_` find the timestamp-less stub it came from.
     */
    private function stem(string $relative): string
    {
        $name = preg_replace('/\.php(\.stub)?$/', '', basename($relative));

        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', (string) $name);
    }

    /**
     * Line endings and trailing whitespace are publishing noise, not drift — a copy that differs only by a
     * CRLF or a missing final newline came from this source. Everything else is content.
     */
    private function normalize(string $contents): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $contents));
    }

    /** @return list<string> */
    private function migrationFiles(string $root, bool $stubs = false): array
    {
        $patterns = $stubs
            ? [$root.'/*.php', $root.'/*/*.php', $root.'/*.php.stub', $root.'/*/*.php.stub']
            : [$root.'/*.php', $root.'/*/*.php'];

        $found = [];
        foreach ($patterns as $pattern) {
            $found = array_merge($found, (array) glob($pattern));
        }

        $files = array_values(array_filter($found, 'is_string'));
        sort($files);

        return $files;
    }
}
