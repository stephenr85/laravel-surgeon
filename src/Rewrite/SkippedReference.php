<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Audit\Reference;

/**
 * A Tier-1 reference the rewriter deliberately DID NOT splice, with the reason why.
 *
 * The deterministic/agentic seam is preserved by *refusing* rather than *guessing*: a group-use
 * member relocated across namespaces, or a partially-qualified inline name that leans on an import
 * this Tier-1 pass doesn't manage, is handed off — never spliced on a hunch. A skip is not a
 * failure; it is the honest edge of what a byte-splice can safely do, surfaced for the guided path.
 */
class SkippedReference
{
    public function __construct(
        public string $relativePath,
        public int $line,
        public string $snippet,
        public string $reason,
    ) {}

    public static function of(Reference $ref, string $reason): self
    {
        return new self($ref->relativePath, $ref->line, trim($ref->snippet), $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'file' => $this->relativePath,
            'line' => $this->line,
            'snippet' => $this->snippet,
            'reason' => $this->reason,
        ];
    }
}
