<?php

namespace Rushing\Surgeon\Replay;

use Rushing\Surgeon\Audit\AuditEngine;

/**
 * THE GOLDEN-REPLAY ACCEPTANCE HARNESS (ticket 06) — the map's acceptance spine.
 *
 * It turns a known historical commit range into an acceptance case: reconstruct the pre-move tree,
 * run the audit with the campaign's move-spec, and diff the tool's enumeration against what the
 * hand-done campaign actually touched. Every rewriter earns its merge by reproducing a real result;
 * the audit is the first thing replayed (does it enumerate exactly the files the campaign hit — the
 * anti-false-premise guarantee, the step whose absence made FR's "11-file" move really 19+7?).
 *
 * It is read-only end to end: {@see GitRepo} only reads history, and {@see AuditEngine} writes
 * nothing. No cycle graph is supplied — enumeration fidelity (which files) is the acceptance
 * question here; cycle-risk is the writer's separate gate. The reconstructed tree lands in a
 * throwaway temp dir that is always cleaned up.
 */
class ReplayHarness
{
    public function __construct(
        private AuditEngine $engine = new AuditEngine,
    ) {}

    /**
     * Replay one campaign against a git repo, returning the enumeration delta.
     *
     * @param  string  $repoPath  the repository whose history holds the campaign (defaults to the app)
     */
    public function replay(CampaignFixture $fixture, string $repoPath): ReplayReport
    {
        $git = new GitRepo($repoPath);

        $before = $git->resolve($fixture->beforeRef);
        $after = $git->resolve($fixture->afterRef);

        $work = sys_get_temp_dir().'/surgeon-replay-'.preg_replace('/[^a-z0-9]+/i', '-', $fixture->name).'-'.bin2hex(random_bytes(4));

        try {
            $git->archiveTo($fixture->beforeRef, $work);

            [$toolFiles, $referenceCount] = $this->enumerate($fixture, $work);
            $actualFiles = $this->groundTruth($fixture, $git);

            return ReplayReport::diff($fixture, $before, $after, $toolFiles, $actualFiles, $referenceCount);
        } finally {
            $this->rrmdir($work);
        }
    }

    /**
     * Run the audit over the reconstructed tree — one relocation target per move-pair — and reduce
     * the accumulated references to the ground-truth-scoped set of unique repo-relative files.
     *
     * @return array{0: list<string>, 1: int} [tool files, total reference count]
     */
    private function enumerate(CampaignFixture $fixture, string $work): array
    {
        $files = [];
        $references = 0;

        foreach ($fixture->targets() as $target) {
            $report = $this->engine->audit([$work], $target);
            foreach ($report->references() as $ref) {
                $references++;
                if ($fixture->countsPath($ref->relativePath)) {
                    $files[$ref->relativePath] = true;
                }
            }
        }

        $list = array_keys($files);
        sort($list);

        return [$list, $references];
    }

    /**
     * The campaign's actual touched-file set, scoped by the fixture's include/exclude globs so the
     * app-only audit is not charged for `composer.lock`, `.scratch/` docs, or package trees.
     *
     * @return list<string>
     */
    private function groundTruth(CampaignFixture $fixture, GitRepo $git): array
    {
        $changed = $git->changedFiles($fixture->beforeRef, $fixture->afterRef);

        return array_values(array_filter($changed, fn (string $p) => $fixture->countsPath($p)));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
