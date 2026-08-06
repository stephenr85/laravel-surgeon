<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Audit\Reference;
use Rushing\Surgeon\Audit\ReferenceCategory;

/**
 * One concrete byte-splice the Tier-1 rewriter will apply — the resolved form of a single
 * {@see Reference} once the planner has decided *what text* replaces the
 * span (which is NOT simply the new FQN; see {@see RewritePlanner}).
 *
 * `startPos`/`endPos` are the same inclusive byte offsets the audit recorded, so the applier splices
 * the exact span and preserves surrounding formatting by construction (finding-01). `oldText` is the
 * recorded source at that span — the applier re-reads the file and refuses to splice unless the live
 * bytes still equal it, so a file that drifted since the audit fails safe instead of corrupting.
 */
class PlannedEdit
{
    public function __construct(
        public string $file,
        public string $relativePath,
        public int $line,
        public int $startPos,
        public int $endPos,
        public string $oldText,
        public string $newText,
        public ReferenceCategory $category,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'file' => $this->relativePath,
            'line' => $this->line,
            'span' => [$this->startPos, $this->endPos],
            'category' => $this->category->value,
            'from' => $this->oldText,
            'to' => $this->newText,
        ];
    }
}
