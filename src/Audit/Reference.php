<?php

namespace Rushing\Surgeon\Audit;

/**
 * One touch-point — a single place in one file where a {@see Target} is referenced.
 *
 * This is the atom the whole tool is built to enumerate: the step whose absence caused the FR
 * campaign's false "11-file" premise. It is deliberately *span-annotated* — `startPos`/`endPos` are
 * the exact byte offsets of the name in the source (nikic keeps them on by default in v5), so a
 * future Tier-1 rewriter can splice the new FQN into that span alone and preserve formatting by
 * construction (finding-01). The audit records the span; it never uses it to write.
 *
 * `newFqn` is populated only when the owning target relocates — it is the canonical name a Tier-1
 * splice would write. For an insight-only audit it stays null.
 */
class Reference
{
    /**
     * @param  string  $root  the configured scan root this file was found under (absolute)
     * @param  string  $file  absolute path to the file
     * @param  string  $relativePath  path relative to $root (for stable, human-legible reporting)
     * @param  string  $matchedFqn  the fully-qualified name that matched the target
     * @param  int  $startPos  byte offset of the name's first character in the source
     * @param  int  $endPos  byte offset of the name's last character in the source
     * @param  string|null  $newFqn  canonical destination FQN (relocation only; else null)
     * @param  string|null  $package  composer package that owns $file, if resolvable (cycle-risk)
     */
    public function __construct(
        public ReferenceCategory $category,
        public Tier $tier,
        public string $root,
        public string $file,
        public string $relativePath,
        public int $line,
        public string $matchedFqn,
        public int $startPos,
        public int $endPos,
        public string $snippet,
        public ?string $newFqn = null,
        public ?string $package = null,
        public ?string $note = null,
        public ?string $cycleRisk = null,
    ) {}

    /** Would repointing this reference at the new home close an upward composer edge? */
    public function hasCycleRisk(): bool
    {
        return $this->cycleRisk !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'tier' => $this->tier->value,
            'root' => $this->root,
            'file' => $this->relativePath,
            'line' => $this->line,
            'matched' => $this->matchedFqn,
            'span' => [$this->startPos, $this->endPos],
            'snippet' => $this->snippet,
            'new' => $this->newFqn,
            'package' => $this->package,
            'note' => $this->note,
            'cycle_risk' => $this->cycleRisk,
        ];
    }
}
