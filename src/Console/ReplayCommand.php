<?php

namespace Rushing\Surgeon\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Replay\CampaignFixture;
use Rushing\Surgeon\Replay\ReplayHarness;
use Rushing\Surgeon\Replay\ReplayReport;

/**
 * `surgeon:replay` — the golden-replay acceptance command (ticket 06). It loads a campaign fixture
 * (a real historical commit range + its move-spec), reconstructs the pre-move tree, runs the audit,
 * and reports how faithfully the tool reproduces what the hand-done campaign actually touched.
 *
 * Read-only (no git-clean precondition — it reconstructs history in a temp dir and never mutates
 * the repo). Exit is SUCCESS when the audit missed nothing (the anti-false-premise guarantee);
 * over-reach is reported but does not fail the run.
 */
class ReplayCommand extends Command
{
    protected $signature = 'surgeon:replay
        {--fixture= : Path to a campaign fixture JSON (name, beforeRef, afterRef, pairs)}
        {--repo= : Repository whose history holds the campaign (defaults to the app base path)}
        {--json : Emit the machine-readable delta as JSON instead of a table}
        {--out= : Write the JSON delta to this file (implies --json)}';

    protected $description = 'Replay a historical refactor campaign as an acceptance case: audit the pre-move tree and diff against the real result.';

    public function handle(): int
    {
        $fixturePath = (string) $this->option('fixture');
        if ($fixturePath === '') {
            $this->error('Pass --fixture=<path to a campaign fixture JSON>.');

            return self::INVALID;
        }
        $fixturePath = $this->absolutePath($fixturePath);

        try {
            $fixture = CampaignFixture::fromJsonFile($fixturePath);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $repo = $this->option('repo') ? $this->absolutePath((string) $this->option('repo')) : $this->laravel->basePath();

        try {
            $report = (new ReplayHarness)->replay($fixture, $repo);
        } catch (\Throwable $e) {
            $this->error('replay failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') || $this->option('out')) {
            return $this->emitJson($report);
        }

        return $this->emitTable($report);
    }

    private function emitJson(ReplayReport $report): int
    {
        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $out = $this->option('out');
        if ($out !== null && $out !== '') {
            file_put_contents($this->absolutePath((string) $out), $json.PHP_EOL);
            $this->info("wrote replay delta → {$out}");
        } else {
            $this->line($json);
        }

        return $report->isClean() ? self::SUCCESS : self::FAILURE;
    }

    private function emitTable(ReplayReport $report): int
    {
        $this->line('');
        $this->line('<options=bold>surgeon:replay — '.$report->fixtureName.'</>');
        $this->line('  '.substr($report->beforeRef, 0, 12).' … '.substr($report->afterRef, 0, 12));
        $this->line(str_repeat('─', 60));
        $this->line(sprintf(
            '  matched %d   missed %d   over-reach %d   (recall %s%%, %d references)',
            count($report->matched),
            count($report->missed),
            count($report->overreached),
            number_format($report->recall() * 100, 1),
            $report->referenceCount,
        ));

        $this->section('MISSED (campaign touched, audit did not enumerate)', $report->missed, 'red');
        $this->section('OVER-REACH (audit enumerated, campaign did not touch)', $report->overreached, 'yellow');

        $this->line('');
        $this->line(str_repeat('─', 60));
        foreach ($report->toDoctorFindings() as $finding) {
            $tag = match ($finding->status) {
                DoctorStatus::Pass => '<fg=green>PASS</>',
                DoctorStatus::Warn => '<fg=yellow>WARN</>',
                DoctorStatus::Fail => '<fg=red>FAIL</>',
            };
            $this->line("  {$tag} {$finding->detail}");
        }
        $this->line('');

        return $report->isClean() ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $files */
    private function section(string $heading, array $files, string $color): void
    {
        if ($files === []) {
            return;
        }

        $this->line('');
        $this->line('  <fg='.$color.';options=bold>'.$heading.'</> ('.count($files).')');
        foreach ($files as $file) {
            $this->line('    <fg='.$color.'>'.$file.'</>');
        }
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $real = realpath($path);

        return $real !== false ? $real : rtrim((string) getcwd(), '/').'/'.$path;
    }
}
