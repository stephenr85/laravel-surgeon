<?php

namespace Rushing\Surgeon\Overlay;

/**
 * The overlay operation vocabulary (ticket 10). Each verb is a distinct {@see OverlayOperation} the
 * audit can nominate; the read verbs (`list`, `diff`) carry no {@see OverlayVerb} because they never
 * mutate. This enum names only the *writing* verbs — the ones that run under ticket 03's writer posture
 * (preview by default, no auto-commit, touch-manifest sidecar).
 *
 *  - {@see Add}         — add a `type: path` repo (+ optional `require`) to the overlay.
 *  - {@see Remove}      — drop a path repo (+ its `require`) from the overlay.
 *  - {@see Materialize} — turn the overlay on: copy the committed template to `composer.local.json`.
 *  - {@see TearDown}    — turn the overlay off: remove `composer.local.json`, back to template-only.
 *  - {@see Sync}        — reconcile this project's overlay toward a sibling's canonical path-repo set.
 */
enum OverlayVerb: string
{
    case Add = 'add';
    case Remove = 'remove';
    case Materialize = 'materialize';
    case TearDown = 'teardown';
    case Sync = 'sync';
}
