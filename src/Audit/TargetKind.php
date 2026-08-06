<?php

namespace Rushing\Surgeon\Audit;

/**
 * How a {@see Target} names *what to hunt*. The audit is target-general (ticket 03 widened the
 * Destination from relocation-only to a general code-insight engine), so a target is never assumed
 * to be an FQN move-pair:
 *
 *  - {@see TargetKind::Symbol} — one exact fully-qualified name (`Tower\Models\Fragment`).
 *  - {@see TargetKind::Namespace} — a namespace prefix; matches the FQN and everything under it
 *    (`Tower\Models` matches `Tower\Models\Fragment` and `Tower\Models\Concept\Anchor`).
 *  - {@see TargetKind::Pattern} — a regular expression matched against the whole FQN, for the
 *    fuzzier insight hunts (`/Assistant/` to find `chat`→`threads`-style stragglers).
 *
 * ({@see TargetKind::Directory} names a filesystem root to *restrict the scan to*, rather than a
 * symbol to match — it pairs with another kind, or stands alone to enumerate a directory's own
 * class-load surface. It carries no FQN match predicate.)
 */
enum TargetKind: string
{
    case Symbol = 'symbol';
    case Namespace = 'namespace';
    case Pattern = 'pattern';
    case Directory = 'directory';
}
