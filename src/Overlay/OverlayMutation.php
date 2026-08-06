<?php

namespace Rushing\Surgeon\Overlay;

use Rushing\Surgeon\Rewrite\TouchManifest;

/**
 * The previewable, reversible record of what an overlay write verb would do to disk (ticket 10) — the
 * overlay analogue of the rewriter's {@see TouchManifest}, honouring the same
 * ticket-03 writer posture at the lighter blast radius of "one or two config files":
 *
 *  - **Preview by default.** A mutation is *built* fully (every {@see FileChange} computed, before/after
 *    bytes in hand) before anything is written; the command renders it and only calls {@see apply()}
 *    under `--apply`.
 *  - **No inference at apply time.** {@see apply()} writes exactly the changes the verb computed; it
 *    re-reads nothing.
 *  - **Manifest-scoped rollback.** {@see rollback()} restores each touched file to its pre-write bytes
 *    (or deletes a file the verb created), leaving all other working-tree state exactly as found.
 *
 * The pre-write bytes stay out of {@see toArray()}: the persisted sidecar is an operation manifest, not
 * a backup (same split as {@see TouchManifest}).
 */
class OverlayMutation
{
    public const CREATE = 'create';

    public const MODIFY = 'modify';

    public const DELETE = 'delete';

    /** @var list<array{path: string, relativePath: string, action: string, before: ?string, after: ?string}> */
    public array $changes = [];

    public function __construct(
        public OverlayVerb $verb,
        public string $baseDir,
        public string $summary = '',
    ) {}

    /**
     * Record an intended write. `$before` is the current bytes (null if the file does not exist yet);
     * `$after` is the bytes to write (null to delete the file). The action is derived from the pair.
     */
    public function change(string $path, ?string $before, ?string $after): void
    {
        $action = $after === null ? self::DELETE : ($before === null ? self::CREATE : self::MODIFY);

        $this->changes[] = [
            'path' => $path,
            'relativePath' => $this->relative($path),
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /** Perform every recorded change on disk. Returns the touched absolute paths. */
    public function apply(): array
    {
        foreach ($this->changes as $change) {
            if ($change['after'] === null) {
                @unlink($change['path']);

                continue;
            }
            $dir = dirname($change['path']);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($change['path'], $change['after']);
        }

        return $this->touchedFiles();
    }

    /** Restore every touched file to its pre-write state (delete what was created). */
    public function rollback(): void
    {
        foreach ($this->changes as $change) {
            if ($change['before'] === null) {
                @unlink($change['path']);

                continue;
            }
            file_put_contents($change['path'], $change['before']);
        }
    }

    /** @return list<string> */
    public function touchedFiles(): array
    {
        return array_values(array_unique(array_map(fn (array $c) => $c['path'], $this->changes)));
    }

    /** @return array<string, mixed> the persisted sidecar shape (no byte blobs) */
    public function toArray(): array
    {
        return [
            'verb' => $this->verb->value,
            'summary' => $this->summary,
            'base_dir' => $this->baseDir,
            'changes' => array_map(fn (array $c) => [
                'file' => $c['relativePath'],
                'action' => $c['action'],
            ], $this->changes),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->baseDir.'/')
            ? substr($path, strlen($this->baseDir) + 1)
            : $path;
    }
}
