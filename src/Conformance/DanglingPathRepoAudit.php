<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Surgeon\Overlay\OverlayOperation;
use Rushing\Surgeon\Overlay\OverlayVerb;

/**
 * A standing, surgeon-native conformance audit: **a composer `type: path` repository whose target
 * directory is gone.** Composer validates path urls while *parsing* the manifest, before it does
 * anything else, so one dangling entry aborts EVERY composer command in the repo with
 * `PathRepository.php line 163: The 'url' supplied for the path (...) repository does not exist` —
 * not just the command that would have installed the missing package. A repo is effectively bricked
 * until someone reads the trace closely enough to notice it names a *repository*, not a dependency.
 * Deleting a sibling package anywhere in an estate leaves this behind in every co-dev overlay that
 * pointed at it, silently, until the next composer invocation.
 *
 * **Why this is a built-in and not a host audit.** "A path repo url that does not exist" names no
 * host vocabulary — it is composer's own concept — so it clears the bar for the foundation package
 * ({@see BuiltInAudits}) and needs no product dependency to state.
 *
 * **Why it is broader than {@see \Rushing\Surgeon\Overlay\OverlayAudit}.** That audit already
 * diagnoses a stale path repo, but only within the overlay it manages, and only as part of a
 * whole-overlay diagnosis reached through `surgeon:overlay`. The composer-abort blast radius is
 * indifferent to which file the entry sits in: a dangling `type: path` in the committed
 * `composer.json` bricks the repo identically. So this audit scans **every** composer config a repo
 * carries and rides the always-on `surgeon:audit` channel.
 *
 * **Severity splits by whether the file is live.** `composer.json` and `composer.local.json` are read
 * by composer on every command → **Fail**. A template (`composer.local.json.dist` / `.off`) is inert
 * until materialized; it is a latent brick, reported **separately as a Warn** so materializing the
 * overlay is not a surprise.
 *
 * **The trap: resolve relative urls from `realpath()` of the config's directory, never its literal
 * path.** A path url is relative to the manifest, and a repo is very often reached through a symlink
 * (a Herd site symlinked into a workspace tree). Joining `..` segments onto the *literal* directory
 * then collapsing them lexically walks up the symlink's parent rather than the real one, and reports
 * every entry in the file as dead — an implementation that gets this wrong doesn't fail loudly, it
 * hands you a plausible-looking list that would empty a healthy config. Resolution here starts from
 * `realpath()` of the directory holding the config file, which is why the regression test stands a
 * fixture up behind a real symlink.
 *
 * **`repositories` is read in both shapes.** Composer accepts a list *or* a name-keyed map, and both
 * exist across this estate; the finding cites the map key when there is one and the list index
 * otherwise, so the entry can be found without re-deriving it.
 *
 * **A wildcard url that expands to nothing is not a defect.** Path urls may be globs (`../*`,
 * `../../rushing/*` — heavily used across this estate) and composer explicitly tolerates one that
 * currently matches no package as long as its de-magicked ancestor directory exists. The existence
 * predicate here is a port of composer's own, not a re-decision ({@see self::resolve()}): an audit
 * that flags what the tool accepts buries the findings that actually brick a repo.
 *
 * The fix half follows the deterministic/agentic seam. An entry in the live overlay nominates a real
 * `overlay-remove` — dropping a stale overlay entry is exactly what that operation does. An entry in
 * `composer.json` (the ship contract) or in a template is **advisory**: whether the repo should be
 * removed or the checkout restored is a judgment call, not a splice.
 */
class DanglingPathRepoAudit implements SuggestsOperations
{
    public const CHECK = 'dangling-path-repo';

    /**
     * The composer configs scanned, in report order, mapped to whether composer reads the file on
     * every command (live → Fail) or only once the overlay is materialized (template → Warn).
     */
    public const FILES = [
        'composer.json' => true,
        'composer.local.json' => true,
        'composer.local.json.dist' => false,
        'composer.local.json.off' => false,
    ];

    /**
     * @param  string  $appRoot  the repo root whose composer configs are scanned (the running host)
     * @param  array<string, bool>  $files  config file name => is-live, overriding {@see self::FILES}
     */
    public function __construct(
        public string $appRoot,
        public array $files = self::FILES,
    ) {}

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $findings = [];
        $scannedFiles = 0;
        $scannedEntries = 0;

        foreach ($this->files as $file => $live) {
            $path = rtrim($this->appRoot, '/').'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $scannedFiles++;

            foreach ($this->pathRepositories($path) as $entry) {
                $scannedEntries++;
                if ($entry['exists']) {
                    continue;
                }

                $findings[] = $this->dangling($file, $live, $entry);
            }
        }

        if ($scannedEntries === 0) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                $scannedFiles === 0
                    ? 'No composer config in this repo — no path repositories to check.'
                    : "No type:path repositories declared across {$scannedFiles} composer config(s).",
            ))];
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.clean',
                "All {$scannedEntries} type:path repositor(y/ies) across {$scannedFiles} composer config(s) resolve to a directory that exists.",
            ));
        }

        return $findings;
    }

    /**
     * Read one composer config's `type: path` repositories, resolved against the realpath of the
     * directory holding it. Accepts both `repositories` shapes; a config that does not decode to an
     * object contributes nothing rather than aborting the run.
     *
     * @return list<array{key: string, url: string, resolved: string, exists: bool}>
     */
    public function pathRepositories(string $configFile): array
    {
        $decoded = json_decode((string) @file_get_contents($configFile), true);
        $repositories = is_array($decoded) ? ($decoded['repositories'] ?? []) : [];

        if (! is_array($repositories)) {
            return [];
        }

        // Resolve from the REAL directory, never the literal one — a config reached through a
        // symlink otherwise resolves its `..` segments against the wrong parent (see class docblock).
        $baseDir = realpath(dirname($configFile)) ?: dirname($configFile);
        $isList = array_is_list($repositories);

        $entries = [];
        foreach ($repositories as $key => $repo) {
            if (! is_array($repo) || ($repo['type'] ?? null) !== 'path' || ! isset($repo['url']) || ! is_string($repo['url'])) {
                continue;
            }

            $entries[] = ['key' => $isList ? '#'.$key : (string) $key, 'url' => $repo['url']]
                + $this->resolve($baseDir, $repo['url']);
        }

        return $entries;
    }

    /**
     * Decide whether one path url is the kind composer aborts on, **porting composer's own
     * `PathRepository::initialize()` predicate rather than re-deciding it** — the audit must agree
     * with the tool whose failure it is preventing, or it reports defects composer tolerates.
     *
     * Composer globs the url with `GLOB_MARK|GLOB_ONLYDIR` (+`GLOB_BRACE`). Any match → fine. No
     * match, but the url carries glob magic (`*`, `{`, `}`) → composer strips trailing segments via
     * `dirname()` until the magic is gone and accepts the repo if that ancestor is a directory: a
     * wildcard that currently expands to nothing is legal, so `"url": "../*"` is NOT a defect and
     * flagging it would bury the real ones. Only a non-magic url that is not a directory, or a
     * wildcard whose de-magicked ancestor is itself gone, aborts the parse.
     *
     * `resolved` is the path a human has to go look at: the target itself for a plain url, the
     * missing ancestor for a wildcard.
     *
     * @return array{resolved: string, exists: bool}
     */
    private function resolve(string $baseDir, string $url): array
    {
        $joined = str_starts_with($url, '/') ? $url : rtrim($baseDir, '/').'/'.$url;
        $magic = preg_match('/[*{}]/', $joined) === 1;

        // `realpath()` first for a plain url (it resolves any further symlink in the way); a lexical
        // collapse otherwise, so a *missing* target still yields the exact path that is absent.
        $pattern = $magic ? $this->collapse($joined) : (realpath($joined) ?: $this->collapse($joined));

        $matches = glob($pattern, GLOB_MARK | GLOB_ONLYDIR | (defined('GLOB_BRACE') ? GLOB_BRACE : 0));
        if (is_array($matches) && $matches !== []) {
            return ['resolved' => $pattern, 'exists' => true];
        }

        if (! $magic) {
            return ['resolved' => $pattern, 'exists' => is_dir($pattern)];
        }

        $ancestor = $pattern;
        while (preg_match('/[*{}]/', $ancestor) === 1) {
            $ancestor = dirname($ancestor);
        }

        return ['resolved' => $ancestor, 'exists' => is_dir($ancestor)];
    }

    /** Collapse `.` / `..` segments without touching disk (the base is already real). */
    private function collapse(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        return '/'.implode('/', $out);
    }

    /**
     * @param  array{key: string, url: string, resolved: string, exists: bool}  $entry
     */
    private function dangling(string $file, bool $live, array $entry): FixableFinding
    {
        $detail = sprintf(
            '%s: type:path repository [%s] url "%s" resolves to %s, which does not exist — %s',
            $file,
            $entry['key'],
            $entry['url'],
            $entry['resolved'],
            $live
                ? 'composer aborts at parse time, so EVERY composer command in this repo fails until the entry is removed or the checkout restored.'
                : 'materializing this template would abort every composer command in this repo.',
        );

        if ($live && $file !== 'composer.json') {
            return new FixableFinding(
                Finding::fail(self::CHECK, $detail),
                new OperationSuggestion(
                    (new OverlayOperation(OverlayVerb::Remove))->kind(),
                    "Remove the stale path repo {$entry['url']} from {$file}.",
                    ['url' => $entry['url'], 'file' => $file],
                ),
            );
        }

        return new FixableFinding(
            $live ? Finding::fail(self::CHECK, $detail) : Finding::warn(self::CHECK.'.template', $detail),
            OperationSuggestion::advisory(
                "Drop or repoint the stale path repo {$entry['url']} in {$file} — whether the entry retires or the checkout is restored is a judgment call, not a splice.",
                'rushing/laravel-surgeon',
            ),
        );
    }
}
