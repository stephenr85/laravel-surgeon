<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **this checkout's deploy pointer, and whether the
 * deployment roster and the checkout agree about it** (beam-facade ticket 118).
 *
 * ## The defect it exists for
 *
 * A root whose *deployed copy* is weeks behind runs, returns 200, and passes every check in the
 * regime — because every check runs against the checkout, never against the thing serving the public.
 * Measured 2026-08-26 across `~/Herd/*`: seven roots carry a deploy remote, all seven were between
 * 14 and 483 commits behind, and nothing anywhere in the estate reported it.
 *
 * ## Why this audit does NOT ship a lag gate — the shape ruling
 *
 * The obvious predicate, `git rev-list --count <remote>/<branch>..<branch>`, is **structurally
 * dishonest**. `refs/remotes/<remote>/<branch>` is a *local* ref, only as fresh as the last time this
 * machine talked to the box. Measured on the same seven roots, every one of those refs had an mtime
 * within seconds of the deployed commit's own date — the signature of a ref written by a **push**,
 * six weeks earlier, never since refreshed. So the count does not measure "how far behind production
 * is"; it measures "how far this checkout has moved since we last pushed", and it assumes nothing
 * changed the box by any other path (a Plesk-UI deploy, an on-box pull). When that assumption breaks
 * the number is wrong **in the reassuring direction**, and a green `0 behind` would then mean
 * *"nobody has fetched"* — which is the condition of every root on that list. An honest lag number
 * needs `git fetch`, which needs the box, a credential, and therefore an operator.
 *
 * **What is honest and locally decidable is the FRESHNESS OF THE READING and the COMPLETENESS OF THE
 * ROSTER**, so those are the findings, in that order of value. Lag is reported last and **never
 * bare** — always as `N commits behind, as of a reading taken D days ago`. A lag figure whose
 * staleness is not printed beside it is the exact thing this audit exists to prevent.
 *
 * **Advisory, never a gate**, under `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md`:
 * every answer here depends on what the invoking machine last fetched, which is that convention's
 * axis-3 forbidden case — enforcement may never be a function of the environment the check ran in.
 * It rides surgeon's built-in channel ({@see BuiltInAudits}), advisory by contract.
 *
 * ## The roster is intrinsic — nothing here builds an enumeration
 *
 * The deployment records under `$SCRIPTORIUM_CORPUS/deployments/<domain>/record.md` already carry
 * `deploy-target`, `system-user`, `branch`, `status` and the literal deploy remote url. This audit
 * reads them and builds no list of its own (ticket 37's rule: ask whether the enumeration is already
 * intrinsic before building one). Two consequences worth stating:
 *
 * - **`status:` is read, not scored away.** Lag on a dark marquee site and lag on a site taking public
 *   writes are different findings; the record's own `status` rides on every finding so they never read
 *   the same.
 * - **Detection can be UNAVAILABLE**, when `$SCRIPTORIUM_CORPUS` is unset or carries no `deployments/`
 *   tree — the `UndescribedRegistryAudit::detectionAvailable()` pattern. That is a **Warn** at a root
 *   that carries a deploy remote (a check reporting all-clear because it could not look is the failure
 *   mode this whole effort is paying to remove) and a **Pass** at a root that declares no deploy remote
 *   at all, which is most of the estate and is not a deployment question.
 *
 * ## Scope: this checkout, both directions
 *
 * The roster is estate-wide but a checkout is not, so the two-way match is scoped to the running root:
 * a record whose remote this root does not carry, and a deploy remote no record describes. Records that
 * match no checkout **cannot be seen from here at all** — that is stated in the audit's own scope
 * finding rather than silently omitted, because the flagship (`app.splicewire.com`, deployed, with no
 * record and no deploy remote in its checkout) is exactly that shape and is invisible to any per-root
 * predicate.
 *
 * ## Three measured traps this implementation is built around
 *
 * - **`packed-refs` mtime is not a reading.** `audiostud` and `stephenrushing` both had one touched by
 *   `git gc`, which would fool a naive freshness check into reporting a two-day-old reading of a
 *   six-week-old ref. A packed ref therefore yields *reading age UNKNOWN*, never a date.
 * - **`FETCH_HEAD` is not a freshness proxy either** — in 5 of 7 roots it records a GitHub fetch, not
 *   the deploy remote's.
 * - **A remote is a deploy remote by NOT being a code host**, not by being called `plesk`. Naming the
 *   remote would teach this foundation package one estate's vocabulary; {@see self::CODE_HOSTS} is the
 *   generic predicate, and it is why a root wired to a differently-named box is still seen.
 */
class DeployPointerAudit implements SuggestsOperations
{
    public const CHECK = 'deploy-pointer';

    /**
     * Hosts that serve *source*, not deployments. A remote pointing anywhere else is treated as a
     * candidate deploy remote — the predicate is deliberately by-exclusion, so a box this list has
     * never heard of is still seen (see the class docblock).
     */
    public const CODE_HOSTS = [
        'github.com',
        'www.github.com',
        'gitlab.com',
        'bitbucket.org',
        'codeberg.org',
        'git.sr.ht',
        'ssh.dev.azure.com',
        'vs-ssh.visualstudio.com',
        'git.launchpad.net',
    ];

    /** Deploy targets that are a PaaS rather than a git box, so a missing deploy remote is correct. */
    public const PAAS_TARGETS = ['laravel-cloud', 'vapor', 'forge-paas', 'fly.io', 'heroku'];

    /** The one line the report always carries about what it did NOT do. */
    public const SCOPE_NOTE = 'Scope: this checkout and the corpus roster only — nothing was fetched, SSHed or read from the box, so every reading here is as fresh as the last time THIS machine talked to the deploy remote, and never fresher.';

    /**
     * @param  string  $appRoot  the repo root whose deploy pointer is read (the running host)
     * @param  string|null  $corpusRoot  the scriptorium corpus root; null reads `$SCRIPTORIUM_CORPUS`
     * @param  int  $staleReadingDays  a reading older than this many days is reported as a Warn
     */
    public function __construct(
        public string $appRoot,
        public ?string $corpusRoot = null,
        public int $staleReadingDays = 7,
    ) {}

    /**
     * Whether the roster is readable at all. Mirrors `UndescribedRegistryAudit::detectionAvailable()`:
     * the corpus is an out-of-tree substrate no package can require, so "not present" is a live state
     * rather than a hypothetical, and it must be said out loud rather than answered as clean.
     */
    public function detectionAvailable(): bool
    {
        return $this->deploymentsDir() !== null;
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $remotes = $this->remotes();
        $deployRemotes = array_filter($remotes, fn (string $url) => $this->isDeployUrl($url));

        if (! $this->detectionAvailable()) {
            return [new FixableFinding($this->unavailable($deployRemotes))];
        }

        $records = $this->records();

        $findings = $this->rosterFindings($records);
        $findings = array_merge($findings, $this->checkoutFindings($records, $remotes, $deployRemotes));

        $findings[] = new FixableFinding(Finding::pass(
            self::CHECK.'.scope',
            sprintf(
                'Read %d deployment record(s) from %s; this root could speak to %s. A record matching no '.
                'checkout is invisible from here by construction — run this audit at each deployed root, or '.
                'the roster half of it is the only half you have. %s',
                count($records),
                (string) $this->deploymentsDir(),
                $deployRemotes === [] ? 'none of them (it declares no deploy remote)' : 'the record(s) naming its own remote(s)',
                self::SCOPE_NOTE,
            ),
        ));

        return $findings;
    }

    /**
     * Detection unavailable. Warn only where it MATTERS — a root carrying a deploy remote is a root
     * whose deploy pointer is now unchecked, which is a real gap; a package repo with no deploy remote
     * is not a deployment question and a Warn there is noise that would bury the real ones.
     *
     * @param  array<string, string>  $deployRemotes  remote name => url
     */
    private function unavailable(array $deployRemotes): Finding
    {
        $configured = $this->corpusRoot ?? $this->env('SCRIPTORIUM_CORPUS');

        $detail = sprintf(
            'Deploy pointer is UNCHECKED: no deployment roster is readable (%s). This is not a clean '.
            'result, it is an unread one.',
            $configured === null ? '$SCRIPTORIUM_CORPUS is unset' : 'no deployments/ tree under '.$configured,
        );

        return $deployRemotes === []
            ? Finding::pass(self::CHECK.'.detection-unavailable', $detail.' This root declares no deploy remote, so there is nothing here the roster would have described.')
            : Finding::warn(self::CHECK.'.detection-unavailable', $detail.' This root DOES carry a deploy remote ('.implode(', ', array_keys($deployRemotes)).'), so a real deploy pointer is going unread.');
    }

    /**
     * Finding 1a — roster completeness, the record side. Decidable with no checkout at all, which is
     * what makes it the highest value per byte: a record that never states a deploy remote or a branch
     * cannot be checked against ANY checkout, by anyone, ever.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<FixableFinding>
     */
    private function rosterFindings(array $records): array
    {
        $findings = [];
        $incomplete = 0;

        foreach ($records as $record) {
            $missing = [];

            foreach (['branch', 'deploy-target', 'status'] as $field) {
                if (($record['front'][$field] ?? '') === '') {
                    $missing[] = $field.':';
                }
            }

            $paas = in_array(strtolower((string) ($record['front']['deploy-target'] ?? '')), self::PAAS_TARGETS, true);

            if ($record['remotes'] === [] && ! $paas) {
                $missing[] = 'a deploy remote url';
            }

            if ($missing === []) {
                continue;
            }

            $incomplete++;
            $findings[] = new FixableFinding(
                Finding::warn(
                    self::CHECK.'.roster-incomplete',
                    sprintf(
                        'Deployment record %s does not state %s, so its deploy pointer cannot be checked '.
                        'against any checkout. %s',
                        $record['name'],
                        implode(' or ', $missing),
                        $paas
                            ? 'Its target is a PaaS, so a git deploy remote is correctly absent — the other field(s) are not.'
                            : 'The record asserts a deployed site; the pointer it would be compared against is missing from the record itself.',
                    ),
                ),
                OperationSuggestion::advisory(
                    sprintf('Add %s to %s.', implode(' and ', $missing), $record['file']),
                    $record['name'],
                ),
            );
        }

        if ($incomplete === 0 && $records !== []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.roster-complete',
                sprintf('All %d deployment record(s) state a branch, a target, a status and (unless PaaS-hosted) a deploy remote url.', count($records)),
            ));
        }

        return $findings;
    }

    /**
     * Findings 1b, 2 and 3 — the checkout side, both directions of the match, then the freshness of the
     * reading and (only behind it) the lag.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, string>  $remotes
     * @param  array<string, string>  $deployRemotes
     * @return list<FixableFinding>
     */
    private function checkoutFindings(array $records, array $remotes, array $deployRemotes): array
    {
        $matchedByUrl = [];
        $matchedByName = [];

        foreach ($records as $record) {
            foreach ($record['remotes'] as $url) {
                foreach ($remotes as $name => $mine) {
                    if ($this->sameRemote($mine, $url)) {
                        $matchedByUrl[] = ['record' => $record, 'remote' => $name];

                        continue 3;
                    }
                }
            }

            if ($this->namesThisRoot($record)) {
                $matchedByName[] = $record;
            }
        }

        if ($matchedByUrl !== []) {
            $findings = [];
            foreach ($matchedByUrl as $match) {
                $findings = array_merge($findings, $this->pointerFindings($match['record'], $match['remote']));
            }

            return $findings;
        }

        if ($matchedByName !== []) {
            return array_map(fn (array $record) => new FixableFinding(
                Finding::warn(
                    self::CHECK.'.remote-missing',
                    sprintf(
                        'Deployment record %s (%s, status: %s) names the deploy remote %s, and this checkout '.
                        'does not carry it — %s. The site is recorded live on the box, so nothing local can '.
                        'read its deploy pointer at all: this root is invisible to any lag reading, not merely '.
                        'stale in one.',
                        $record['name'],
                        (string) ($record['front']['domain'] ?? $record['name']),
                        (string) ($record['front']['status'] ?? 'unstated'),
                        implode(' / ', $record['remotes']),
                        $remotes === [] ? 'it has no git remotes' : 'its remotes are '.implode(', ', array_keys($remotes)),
                    ),
                ),
                OperationSuggestion::advisory(
                    sprintf('Wire the deploy remote %s into this checkout, or correct %s.', implode(' / ', $record['remotes']), $record['file']),
                    $record['name'],
                ),
            ), $matchedByName);
        }

        if ($deployRemotes !== []) {
            return [new FixableFinding(
                Finding::warn(
                    self::CHECK.'.undescribed',
                    sprintf(
                        'This checkout carries %d deploy remote(s) (%s) that no deployment record describes. '.
                        'A deployed root with no record has no recorded branch, no recorded status and no '.
                        'stated deploy pointer, so it is outside every reading this audit can take.',
                        count($deployRemotes),
                        implode(', ', array_map(fn ($n, $u) => $n.' → '.$u, array_keys($deployRemotes), $deployRemotes)),
                    ),
                ),
                OperationSuggestion::advisory(
                    sprintf('Write a deployment record for this root under %s.', (string) $this->deploymentsDir()),
                    basename($this->realRoot()),
                ),
            )];
        }

        return [new FixableFinding(Finding::pass(
            self::CHECK.'.not-deployed',
            'This root declares no deploy remote and no deployment record names it, so there is no deploy pointer '.
            'to read here. Read that as "nothing local to check", NOT as "not deployed": a root deployed by a path '.
            'this checkout does not record — a shared subscription, a PaaS pipeline, a CI runner — is '.
            'indistinguishable from an undeployed one by any per-root predicate. The flagship engine is exactly '.
            'that shape, and it is why this finding states its own limit instead of reading as clean.',
        ))];
    }

    /**
     * Findings 2 and 3 for one matched record: the age of the READING first, and the lag only behind it,
     * never as a bare number.
     *
     * @param  array<string, mixed>  $record
     * @return list<FixableFinding>
     */
    private function pointerFindings(array $record, string $remote): array
    {
        $branch = (string) ($record['front']['branch'] ?? '') ?: 'main';
        $status = (string) ($record['front']['status'] ?? 'unstated');
        $site = (string) ($record['front']['domain'] ?? $record['name']);

        $ref = $this->refReading($remote, $branch);
        $findings = [];

        if ($ref['state'] === 'loose') {
            $days = $ref['days'];
            $horizon = sprintf('a reading taken %d day(s) ago', $days);
            $detail = sprintf(
                'The last time this checkout wrote refs/remotes/%s/%s was %d day(s) ago (%s). That is the age '.
                'of the READING, not of the deploy: the ref is written by a push or a fetch here, so anything '.
                'that changed %s by another path is invisible to it.',
                $remote,
                $branch,
                $days,
                date('Y-m-d', $ref['mtime']),
                $site,
            );

            $findings[] = new FixableFinding($days > $this->staleReadingDays
                ? Finding::warn(self::CHECK.'.reading-stale', $detail.sprintf(' Older than the %d-day horizon: run `git fetch %s` before believing any lag number derived from it.', $this->staleReadingDays, $remote))
                : Finding::pass(self::CHECK.'.reading-fresh', $detail));
        } else {
            $horizon = 'a reading of unknown age';
            $findings[] = new FixableFinding(Finding::warn(
                self::CHECK.'.reading-unknown',
                $ref['state'] === 'packed'
                    ? sprintf('refs/remotes/%s/%s is PACKED, so the age of the reading is unknown — packed-refs mtime is a `git gc` artifact and reading it would report a fresh date for a months-old ref. Run `git fetch %s` to get a datable reading.', $remote, $branch, $remote)
                    : sprintf('This root carries the deploy remote %s but no refs/remotes/%s/%s at all — nothing has ever pushed or fetched %s from here, so there is no deploy pointer to read.', $remote, $remote, $branch, $branch),
            ));
        }

        $lag = $this->lag($remote, $branch);

        $findings[] = new FixableFinding(Finding::warn(
            self::CHECK.'.lag',
            $lag === null
                ? sprintf('Lag against %s/%s is UNAVAILABLE (the ref, the local branch, or git itself could not be read). Reported as unavailable rather than as zero, deliberately: this predicate fails toward reassurance and a bare 0 would read as "up to date" when it means "could not look".', $remote, $branch)
                : sprintf(
                    '%s (%s, status: %s) is %d commit(s) behind %s/%s, as of %s. Never read that number bare: '.
                    'it measures how far this checkout has moved since it last talked to the remote, not how '.
                    'far the box is behind, and if anything deployed by another path it is wrong in the '.
                    'reassuring direction. An honest number needs `git fetch %s`, which needs the box.',
                    $site,
                    (string) ($record['front']['deploy-target'] ?? 'unstated target'),
                    $status,
                    $lag,
                    $remote,
                    $branch,
                    $horizon,
                    $remote,
                ),
        ));

        return $findings;
    }

    /**
     * The age of the reading, from the LOOSE ref's own mtime. A packed ref deliberately yields `packed`
     * rather than a date — see the class docblock's `git gc` trap.
     *
     * @return array{state: string, mtime: int, days: int}
     */
    private function refReading(string $remote, string $branch): array
    {
        $gitDir = $this->gitDir();

        if ($gitDir === null) {
            return ['state' => 'absent', 'mtime' => 0, 'days' => 0];
        }

        $loose = $gitDir.'/refs/remotes/'.$remote.'/'.$branch;

        if (is_file($loose)) {
            $mtime = (int) @filemtime($loose);

            return ['state' => 'loose', 'mtime' => $mtime, 'days' => (int) floor((time() - $mtime) / 86400)];
        }

        $packed = $gitDir.'/packed-refs';

        if (is_file($packed) && str_contains((string) @file_get_contents($packed), ' refs/remotes/'.$remote.'/'.$branch)) {
            return ['state' => 'packed', 'mtime' => 0, 'days' => 0];
        }

        return ['state' => 'absent', 'mtime' => 0, 'days' => 0];
    }

    /**
     * The commit count between the remote-tracking ref and the local branch. Null on ANY failure —
     * a missing ref, a missing branch, `exec` disabled, a non-numeric answer. Never 0 as a fallback:
     * this is the one number in the audit that fails toward reassurance, so its unknown state has to
     * be distinguishable from its zero state.
     */
    private function lag(string $remote, string $branch): ?int
    {
        if (! function_exists('exec')) {
            return null;
        }

        $root = $this->realRoot();

        if (! is_dir($root.'/.git') && ! is_file($root.'/.git')) {
            return null;
        }

        $command = sprintf(
            'git -C %s rev-list --count %s..%s 2>/dev/null',
            escapeshellarg($root),
            escapeshellarg('refs/remotes/'.$remote.'/'.$branch),
            escapeshellarg('refs/heads/'.$branch),
        );

        $output = [];
        $status = 1;
        @exec($command, $output, $status);

        $answer = trim(implode('', $output));

        return $status === 0 && $answer !== '' && ctype_digit($answer) ? (int) $answer : null;
    }

    /**
     * Every deployment record under the corpus, with its frontmatter and the deploy remote url(s) it
     * states. Remote urls are read from lines that SAY they are about a remote — the records are prose
     * and carry other scp-shaped strings (a `--resolve host:port:ip` verification recipe, for one), so
     * an unanchored scan of the body would manufacture remotes that do not exist.
     *
     * @return list<array{name: string, file: string, front: array<string, string>, remotes: list<string>}>
     */
    public function records(): array
    {
        $dir = $this->deploymentsDir();

        if ($dir === null) {
            return [];
        }

        $records = [];

        foreach ((array) glob($dir.'/*/record.md') as $file) {
            $name = basename(dirname((string) $file));

            if (str_starts_with($name, '_')) {
                continue;
            }

            $body = (string) @file_get_contents((string) $file);

            $records[] = [
                'name' => $name,
                'file' => (string) $file,
                'front' => $this->frontmatter($body),
                'remotes' => $this->statedRemotes($body),
            ];
        }

        usort($records, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $records;
    }

    /** @return array<string, string> */
    private function frontmatter(string $body): array
    {
        if (! str_starts_with($body, "---\n")) {
            return [];
        }

        $end = strpos($body, "\n---", 4);

        if ($end === false) {
            return [];
        }

        $front = [];

        foreach (explode("\n", substr($body, 4, $end - 4)) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $front[trim($key)] = trim($value);
        }

        return $front;
    }

    /** @return list<string> */
    private function statedRemotes(string $body): array
    {
        $urls = [];

        foreach (explode("\n", $body) as $line) {
            if (stripos($line, 'remote') === false) {
                continue;
            }

            if (preg_match_all('/`([A-Za-z0-9._+-]+@[A-Za-z0-9._-]+:[^\s`]+)`/', $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $url) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * This root's git remotes, read straight out of `.git/config`. Deliberately not `git remote -v`:
     * the audit must answer at a root whose git binary is unavailable, and the config file is the same
     * fact one layer down.
     *
     * @return array<string, string> remote name => url
     */
    public function remotes(): array
    {
        $gitDir = $this->gitDir();

        if ($gitDir === null || ! is_file($config = $gitDir.'/config')) {
            return [];
        }

        $remotes = [];
        $current = null;

        foreach (explode("\n", (string) @file_get_contents($config)) as $line) {
            $line = trim($line);

            if (preg_match('/^\[remote "([^"]+)"\]$/', $line, $m) === 1) {
                $current = $m[1];

                continue;
            }

            if (str_starts_with($line, '[')) {
                $current = null;

                continue;
            }

            if ($current !== null && preg_match('/^url\s*=\s*(.+)$/', $line, $m) === 1) {
                $remotes[$current] = trim($m[1]);
            }
        }

        return $remotes;
    }

    /** A remote url that points somewhere other than a known code host — see {@see self::CODE_HOSTS}. */
    private function isDeployUrl(string $url): bool
    {
        $host = $this->hostOf($url);

        return $host !== null && ! in_array(strtolower($host), self::CODE_HOSTS, true);
    }

    private function hostOf(string $url): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]+@)?([^/:]+)#i', $url, $m) === 1) {
            return $m[1];
        }

        if (preg_match('#^(?:[^@/]+@)?([^/:]+):(?!//)#', $url, $m) === 1) {
            return $m[1];
        }

        return null; // a local path url — not a deploy remote, and not a code host either
    }

    /** Two remote urls naming the same place, modulo a trailing `.git` and a `ssh://` spelling. */
    private function sameRemote(string $a, string $b): bool
    {
        return $this->normalizeRemote($a) === $this->normalizeRemote($b);
    }

    private function normalizeRemote(string $url): string
    {
        $url = preg_replace('#^ssh://#i', '', trim($url)) ?? $url;
        $url = preg_replace('#\.git$#', '', $url) ?? $url;

        return rtrim(strtolower($url), '/');
    }

    /**
     * Whether a record plausibly describes THIS checkout when no remote url matched — the case the
     * `remote-missing` finding exists for. Matching is by directory name against the record's own
     * identifiers only; it never guesses from the domain's shape.
     *
     * @param  array<string, mixed>  $record
     */
    private function namesThisRoot(array $record): bool
    {
        $basename = strtolower(basename($this->realRoot()));

        $candidates = [
            strtolower((string) ($record['front']['site'] ?? '')),
            strtolower((string) ($record['front']['system-user'] ?? '')),
        ];

        foreach ($record['remotes'] as $url) {
            $candidates[] = strtolower(basename($url));
        }

        return in_array($basename, array_values(array_filter($candidates)), true);
    }

    /** The corpus `deployments/` tree, or null when it is not readable. */
    private function deploymentsDir(): ?string
    {
        $corpus = $this->corpusRoot ?? $this->env('SCRIPTORIUM_CORPUS');

        if ($corpus === null || $corpus === '') {
            return null;
        }

        $dir = rtrim($corpus, '/').'/deployments';

        return is_dir($dir) ? $dir : null;
    }

    /** Resolve the real `.git` directory, following the `gitdir:` pointer a worktree or submodule uses. */
    private function gitDir(): ?string
    {
        $root = $this->realRoot();

        if (is_dir($root.'/.git')) {
            return $root.'/.git';
        }

        if (is_file($root.'/.git') && preg_match('/^gitdir:\s*(.+)$/m', (string) @file_get_contents($root.'/.git'), $m) === 1) {
            $path = trim($m[1]);
            $path = str_starts_with($path, '/') ? $path : $root.'/'.$path;

            return is_dir($path) ? (realpath($path) ?: $path) : null;
        }

        return null;
    }

    private function realRoot(): string
    {
        return realpath($this->appRoot) ?: rtrim($this->appRoot, '/');
    }

    private function env(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
