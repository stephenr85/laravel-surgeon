<?php

namespace Rushing\Surgeon\Replay;

use Rushing\Surgeon\Audit\Target;

/**
 * A golden-replay campaign fixture (ticket 06) — a historical refactor turned into an acceptance
 * case. It names a real commit range and the move-spec that *should* reproduce it, so the audit
 * earns its trust by enumerating exactly the files the hand-done campaign actually touched.
 *
 * The fixture is the ticket-02 move-spec's *declared* stratum plus the ground-truth refs to diff
 * against: `beforeRef` is the pre-move tree the audit runs on (the commit before the campaign's
 * first step), `afterRef` is the campaign's landed tip. `pairs` are the per-symbol `old → new` FQN
 * atoms; the audit hunts references to each `old` in the reconstructed pre-move tree.
 *
 * `includeGlobs` / `excludeGlobs` scope the *ground-truth* file set the tool is compared against —
 * a campaign commit also churns `composer.lock`, `.scratch/` docs, and package trees that the
 * app-only audit is not responsible for, so they are excluded rather than counted as misses.
 */
class CampaignFixture
{
    /**
     * @param  list<array{old: string, new: string}>  $pairs  the move-spec's declared old→new atoms
     * @param  list<string>  $includeGlobs  fnmatch patterns a touched file must match to count
     * @param  list<string>  $excludeGlobs  fnmatch patterns that drop a touched file from the set
     */
    public function __construct(
        public string $name,
        public string $beforeRef,
        public string $afterRef,
        public array $pairs,
        public array $includeGlobs = ['*.php'],
        public array $excludeGlobs = ['.scratch/*', 'vendor/*', 'node_modules/*', 'composer.lock'],
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'beforeRef', 'afterRef', 'pairs'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \InvalidArgumentException("campaign fixture is missing required key: {$required}");
            }
        }

        $pairs = [];
        foreach ($data['pairs'] as $i => $pair) {
            if (! isset($pair['old'], $pair['new'])) {
                throw new \InvalidArgumentException("pair #{$i} must carry both 'old' and 'new' FQNs");
            }
            $pairs[] = ['old' => (string) $pair['old'], 'new' => (string) $pair['new']];
        }

        return new self(
            name: (string) $data['name'],
            beforeRef: (string) $data['beforeRef'],
            afterRef: (string) $data['afterRef'],
            pairs: $pairs,
            includeGlobs: $data['includeGlobs'] ?? ['*.php'],
            excludeGlobs: $data['excludeGlobs'] ?? ['.scratch/*', 'vendor/*', 'node_modules/*', 'composer.lock'],
            description: isset($data['description']) ? (string) $data['description'] : null,
        );
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException("cannot read campaign fixture: {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException("campaign fixture is not valid JSON: {$path}");
        }

        return self::fromArray($data);
    }

    /**
     * The declared move-pairs as relocation {@see Target}s — one audit target per atom.
     *
     * @return list<Target>
     */
    public function targets(): array
    {
        return array_map(fn (array $p) => Target::relocatingTo($p['old'], $p['new']), $this->pairs);
    }

    /** Does a repo-relative path count toward the ground-truth set (include-matched, not excluded)? */
    public function countsPath(string $relativePath): bool
    {
        foreach ($this->excludeGlobs as $glob) {
            if (fnmatch($glob, $relativePath)) {
                return false;
            }
        }
        foreach ($this->includeGlobs as $glob) {
            if (fnmatch($glob, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
