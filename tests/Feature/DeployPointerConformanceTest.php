<?php

use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Conformance\BuiltInAudits;
use Rushing\Surgeon\Conformance\DeployPointerAudit;

/**
 * beam-facade ticket 118 — the deploy pointer. Every case runs against a real disposable tree with a
 * real `.git/config`, a real corpus `deployments/` roster and, where the lag number is under test, a
 * real git repository — because the whole audit is a reading of files git and the corpus actually
 * write, and a mocked one would prove nothing about the traps it exists for.
 */

/** A corpus root holding `deployments/<name>/record.md` for each given body. */
function pointerCorpus(string $label, array $records): string
{
    $root = surgeon_tmp('deploy-corpus-'.$label);

    foreach ($records as $name => $body) {
        surgeon_write($root.'/deployments/'.$name.'/record.md', $body);
    }

    return $root;
}

/** The record shape the estate actually ships: frontmatter plus a prose `Deploy remote:` bullet. */
function pointerRecord(string $site, string $branch = 'main', ?string $remote = 'plesk', string $status = 'live', string $target = 'plesk2.frameworks.fit'): string
{
    $remoteLine = $remote === null
        ? ''
        : "- **Deploy remote:** `{$remote}` → `{$site}@74.208.164.52:git/{$site}` (wired in the local clone).\n";

    return <<<MD
    ---
    site: {$site}
    domain: {$site}.example
    deploy-target: {$target}
    system-user: {$site}
    branch: {$branch}
    status: {$status}
    ---

    Prose body.

    ## Site specifics

    {$remoteLine}- **Verify:** `curl --resolve {$site}.example:443:74.208.164.52 https://{$site}.example`

    MD;
}

/** A checkout with a `.git/config` naming the given remotes, at a directory of the given name. */
function pointerRoot(string $label, string $name, array $remotes): string
{
    $root = surgeon_tmp('deploy-root-'.$label).'/'.$name;
    mkdir($root, 0755, true);

    $config = "[core]\n\trepositoryformatversion = 0\n";
    foreach ($remotes as $remote => $url) {
        $config .= "[remote \"{$remote}\"]\n\turl = {$url}\n\tfetch = +refs/heads/*:refs/remotes/{$remote}/*\n";
    }
    surgeon_write($root.'/.git/config', $config);

    return $root;
}

/** @return array<string, \Rushing\Doctor\Finding> check => finding */
function pointerFindings(string $appRoot, ?string $corpus, int $staleDays = 7): array
{
    $keyed = [];
    foreach ((new DeployPointerAudit($appRoot, $corpus, $staleDays))->suggestOperations() as $fixable) {
        $keyed[$fixable->finding->check] = $fixable->finding;
    }

    return $keyed;
}

it('reports detection UNAVAILABLE as a warn when the root carries a deploy remote and the roster is unreadable', function () {
    $root = pointerRoot('unavailable-warn', 'fable', ['plesk' => 'fable@74.208.164.52:git/fable']);

    try {
        $findings = pointerFindings($root, '/nonexistent-corpus');

        expect($findings)->toHaveCount(1)
            ->and($findings[DeployPointerAudit::CHECK.'.detection-unavailable']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[DeployPointerAudit::CHECK.'.detection-unavailable']->detail)->toContain('UNCHECKED');
    } finally {
        surgeon_rrmdir(dirname($root));
    }
});

it('reports detection unavailable as a PASS at a root with no deploy remote, so package repos are not noise', function () {
    $root = pointerRoot('unavailable-pass', 'laravel-surgeon', ['origin' => 'git@github.com:stephenr85/laravel-surgeon.git']);

    try {
        $findings = pointerFindings($root, '/nonexistent-corpus');

        expect($findings[DeployPointerAudit::CHECK.'.detection-unavailable']->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir(dirname($root));
    }
});

it('warns on a record that states no deploy remote, with no checkout involved at all', function () {
    $corpus = pointerCorpus('incomplete', [
        'thingson.tv' => pointerRecord('thingsontv', remote: null),
        'fable.pub' => pointerRecord('fable'),
    ]);
    $root = pointerRoot('incomplete', 'unrelated', ['origin' => 'git@github.com:acme/unrelated.git']);

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings[DeployPointerAudit::CHECK.'.roster-incomplete']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[DeployPointerAudit::CHECK.'.roster-incomplete']->detail)
            ->toContain('thingson.tv')
            ->toContain('a deploy remote url')
            ->and($findings)->not->toHaveKey(DeployPointerAudit::CHECK.'.roster-complete');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('does not demand a git deploy remote from a PaaS-hosted record', function () {
    $corpus = pointerCorpus('paas', [
        'entreport-pilot' => pointerRecord('entreport-pilot', remote: null, target: 'laravel-cloud'),
    ]);
    $root = pointerRoot('paas', 'unrelated', ['origin' => 'git@github.com:acme/unrelated.git']);

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings)->not->toHaveKey(DeployPointerAudit::CHECK.'.roster-incomplete')
            ->and($findings[DeployPointerAudit::CHECK.'.roster-complete']->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('warns when a record names a deploy remote this checkout does not carry', function () {
    $corpus = pointerCorpus('remote-missing', ['numero.zone' => pointerRecord('numero')]);
    $root = pointerRoot('remote-missing', 'numero', ['origin' => 'git@github.com:acme/numero.git']);

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings[DeployPointerAudit::CHECK.'.remote-missing']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[DeployPointerAudit::CHECK.'.remote-missing']->detail)
            ->toContain('numero@74.208.164.52:git/numero')
            ->toContain('invisible to any lag reading');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('warns when this checkout carries a deploy remote no record describes', function () {
    $corpus = pointerCorpus('undescribed', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('undescribed', 'splicewire-app', ['plesk' => 'someone@203.0.113.9:git/app']);

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings[DeployPointerAudit::CHECK.'.undescribed']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[DeployPointerAudit::CHECK.'.undescribed']->detail)->toContain('203.0.113.9');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('passes quietly at a root that is not a deployed site', function () {
    $corpus = pointerCorpus('not-deployed', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('not-deployed', 'laravel-doctor', ['origin' => 'git@github.com:stephenr85/laravel-doctor.git']);

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings[DeployPointerAudit::CHECK.'.not-deployed']->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('reads the age of the reading off the loose ref, and warns once it is past the horizon', function () {
    $corpus = pointerCorpus('reading-stale', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('reading-stale', 'fable', ['plesk' => 'fable@74.208.164.52:git/fable']);

    surgeon_write($root.'/.git/refs/remotes/plesk/main', str_repeat('a', 40)."\n");
    touch($root.'/.git/refs/remotes/plesk/main', time() - 43 * 86400);

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings[DeployPointerAudit::CHECK.'.reading-stale']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[DeployPointerAudit::CHECK.'.reading-stale']->detail)
            ->toContain('43 day(s) ago')
            ->toContain('age of the READING')
            ->and($findings)->not->toHaveKey(DeployPointerAudit::CHECK.'.reading-fresh');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('passes the freshness finding when the reading is inside the horizon', function () {
    $corpus = pointerCorpus('reading-fresh', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('reading-fresh', 'fable', ['plesk' => 'fable@74.208.164.52:git/fable']);

    surgeon_write($root.'/.git/refs/remotes/plesk/main', str_repeat('a', 40)."\n");
    touch($root.'/.git/refs/remotes/plesk/main', time() - 3600);

    try {
        expect(pointerFindings($root, $corpus)[DeployPointerAudit::CHECK.'.reading-fresh']->status)->toBe(DoctorStatus::Pass);
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('refuses to date a PACKED ref, because packed-refs mtime is a gc artifact rather than a reading', function () {
    $corpus = pointerCorpus('reading-packed', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('reading-packed', 'fable', ['plesk' => 'fable@74.208.164.52:git/fable']);

    // The loose ref is gone and the same ref sits in packed-refs, whose mtime is TODAY — the exact
    // shape `git gc` leaves behind at a root whose real reading is six weeks old.
    surgeon_write($root.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted \n".str_repeat('a', 40).' refs/remotes/plesk/main'."\n");

    try {
        $findings = pointerFindings($root, $corpus);

        expect($findings[DeployPointerAudit::CHECK.'.reading-unknown']->status)->toBe(DoctorStatus::Warn)
            ->and($findings[DeployPointerAudit::CHECK.'.reading-unknown']->detail)
            ->toContain('PACKED')
            ->toContain('gc')
            ->and($findings[DeployPointerAudit::CHECK.'.reading-unknown']->detail)->not->toContain(date('Y-m-d'));
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('reports lag as UNAVAILABLE rather than as zero when the count cannot be taken', function () {
    $corpus = pointerCorpus('lag-unavailable', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('lag-unavailable', 'fable', ['plesk' => 'fable@74.208.164.52:git/fable']);

    surgeon_write($root.'/.git/refs/remotes/plesk/main', str_repeat('a', 40)."\n");

    try {
        expect(pointerFindings($root, $corpus)[DeployPointerAudit::CHECK.'.lag']->detail)
            ->toContain('UNAVAILABLE')
            ->not->toContain('0 commit(s) behind');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('counts real lag against a real repository, and never prints the number without its horizon', function () {
    $git = trim((string) shell_exec('command -v git 2>/dev/null'));

    if ($git === '') {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $corpus = pointerCorpus('lag-real', ['fable.pub' => pointerRecord('fable')]);
    $parent = surgeon_tmp('deploy-root-lag-real');
    $root = $parent.'/fable';
    mkdir($root, 0755, true);

    $run = fn (string $cmd) => shell_exec('cd '.escapeshellarg($root).' && '.$cmd.' 2>/dev/null');

    $run('git init -q -b main');
    $run('git config user.email t@example.test && git config user.name t');
    file_put_contents($root.'/a.txt', "one\n");
    $run('git add -A && git commit -q -m one');
    $deployed = trim((string) $run('git rev-parse HEAD'));

    foreach (['two', 'three'] as $n) {
        file_put_contents($root.'/a.txt', $n."\n");
        $run('git add -A && git commit -q -m '.$n);
    }

    // The deploy pointer as a PUSH leaves it: a loose remote-tracking ref at the deployed commit,
    // written once and never refreshed.
    surgeon_write($root.'/.git/refs/remotes/plesk/main', $deployed."\n");
    touch($root.'/.git/refs/remotes/plesk/main', time() - 43 * 86400);

    $config = (string) file_get_contents($root.'/.git/config');
    file_put_contents($root.'/.git/config', $config."[remote \"plesk\"]\n\turl = fable@74.208.164.52:git/fable\n");

    try {
        $lag = pointerFindings($root, $corpus)[DeployPointerAudit::CHECK.'.lag'];

        expect($lag->status)->toBe(DoctorStatus::Warn)
            ->and($lag->detail)
            ->toContain('2 commit(s) behind plesk/main')
            ->toContain('as of a reading taken 43 day(s) ago')
            ->toContain('Never read that number bare');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir($parent);
    }
});

it('reads a stated remote only off a line that says it is a remote, so a --resolve recipe is not mistaken for one', function () {
    $corpus = pointerCorpus('resolve-trap', ['fable.pub' => pointerRecord('fable', remote: null)]);
    $root = pointerRoot('resolve-trap', 'unrelated', []);

    try {
        $records = (new DeployPointerAudit($root, $corpus))->records();

        expect($records)->toHaveCount(1)
            ->and($records[0]['remotes'])->toBe([]);
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('always carries a scope note saying nothing was fetched from the box', function () {
    $corpus = pointerCorpus('scope', ['fable.pub' => pointerRecord('fable')]);
    $root = pointerRoot('scope', 'unrelated', []);

    try {
        expect(pointerFindings($root, $corpus)[DeployPointerAudit::CHECK.'.scope']->detail)
            ->toContain('nothing was fetched, SSHed or read from the box');
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('never emits a Fail, because enforcement may not be a function of what this machine last fetched', function () {
    $corpus = pointerCorpus('advisory', [
        'fable.pub' => pointerRecord('fable'),
        'thingson.tv' => pointerRecord('thingsontv', remote: null),
    ]);
    $root = pointerRoot('advisory', 'fable', ['plesk' => 'fable@74.208.164.52:git/fable']);

    try {
        $statuses = array_map(fn ($f) => $f->status, pointerFindings($root, $corpus));

        expect($statuses)->not->toContain(DoctorStatus::Fail);
    } finally {
        surgeon_rrmdir($corpus);
        surgeon_rrmdir(dirname($root));
    }
});

it('is registered in the built-in set so surgeon:audit runs it', function () {
    $audits = (new BuiltInAudits('/nonexistent-root'))->audits();

    expect(array_filter($audits, fn ($a) => $a instanceof DeployPointerAudit))->toHaveCount(1);
});
