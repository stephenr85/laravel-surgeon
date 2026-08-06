<?php

namespace Rushing\Surgeon\Rewrite;

use Rushing\Surgeon\Audit\AuditReport;
use Rushing\Surgeon\Audit\Reference;
use Rushing\Surgeon\Audit\ReferenceCategory;
use Rushing\Surgeon\Audit\Target;
use Rushing\Surgeon\Audit\TargetKind;

/**
 * Rehydrates a `surgeon:audit --out` finding-set (JSON) back into an {@see AuditReport} — the
 * concrete mechanism behind ticket 03's separation-of-insight-from-execution: `surgeon:move` applies
 * an *explicit, resolved operation set written to disk*, and NEVER re-audits or infers. The audit is
 * the only thing that produces this file; the mover only ever consumes it.
 *
 * The audit's per-reference JSON records paths RELATIVE to their root (stable, legible); this loader
 * resolves each back to an absolute path by probing the recorded roots, so a plan built here points
 * at real files. A reference that resolves under none of the roots is dropped (it cannot be a write
 * target) — surfaced by the caller as a count, never silently applied elsewhere.
 */
class FindingSetLoader
{
    /**
     * @return array{report: AuditReport, unresolved: int}
     *
     * @throws \InvalidArgumentException on a malformed or non-relocation finding-set
     */
    public static function load(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException("cannot read finding-set: {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['target'], $data['roots'], $data['references'])) {
            throw new \InvalidArgumentException("not a surgeon finding-set (missing target/roots/references): {$path}");
        }

        $target = self::rebuildTarget($data['target']);
        if (! $target->relocates()) {
            throw new \InvalidArgumentException('finding-set has no relocation destination (`--to`); nothing for surgeon:move to apply.');
        }

        $roots = array_values(array_map(fn ($r) => rtrim((string) $r, '/'), $data['roots']));

        $references = [];
        $unresolved = 0;
        foreach ($data['references'] as $ref) {
            $resolved = self::rebuildReference($ref, $roots);
            if ($resolved === null) {
                $unresolved++;

                continue;
            }
            $references[] = $resolved;
        }

        return ['report' => new AuditReport($target, $roots, $references), 'unresolved' => $unresolved];
    }

    /** @param array<string, mixed> $target */
    private static function rebuildTarget(array $target): Target
    {
        $kind = TargetKind::from((string) ($target['kind'] ?? 'symbol'));
        $value = (string) ($target['value'] ?? '');
        $new = $target['new'] ?? null;

        $rebuilt = match ($kind) {
            TargetKind::Symbol => $new !== null ? Target::relocatingTo($value, (string) $new) : Target::symbol($value),
            TargetKind::Namespace => Target::namespace($value),
            TargetKind::Pattern => Target::pattern($value),
            TargetKind::Directory => Target::directory($value),
        };

        // A namespace relocation carries its destination on the target directly (mirrors AuditCommand).
        if ($kind === TargetKind::Namespace && $new !== null) {
            $rebuilt->newFqn = Target::normalize((string) $new);
        }

        return $rebuilt;
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  list<string>  $roots
     */
    private static function rebuildReference(array $ref, array $roots): ?Reference
    {
        $relative = (string) ($ref['file'] ?? '');
        [$start, $end] = $ref['span'] ?? [null, null];
        if ($relative === '' || ! is_int($start) || ! is_int($end)) {
            return null;
        }

        [$root, $absolute] = self::resolvePath($relative, $roots);
        if ($absolute === null) {
            return null;
        }

        return new Reference(
            category: ReferenceCategory::from((string) $ref['category']),
            tier: ReferenceCategory::from((string) $ref['category'])->tier(),
            root: $root,
            file: $absolute,
            relativePath: $relative,
            line: (int) ($ref['line'] ?? 0),
            matchedFqn: (string) ($ref['matched'] ?? ''),
            startPos: $start,
            endPos: $end,
            snippet: (string) ($ref['snippet'] ?? ''),
            newFqn: isset($ref['new']) ? (string) $ref['new'] : null,
            package: isset($ref['package']) ? (string) $ref['package'] : null,
            note: isset($ref['note']) ? (string) $ref['note'] : null,
            cycleRisk: isset($ref['cycle_risk']) ? (string) $ref['cycle_risk'] : null,
        );
    }

    /**
     * @param  list<string>  $roots
     * @return array{0: string, 1: string|null} [root, absolutePath] or [firstRoot, null] if unresolved
     */
    private static function resolvePath(string $relative, array $roots): array
    {
        foreach ($roots as $root) {
            $candidate = $root.'/'.$relative;
            if (is_file($candidate)) {
                return [$root, $candidate];
            }
        }

        return [$roots[0] ?? '', null];
    }
}
