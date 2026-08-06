<?php

namespace Rushing\Surgeon\Overlay;

/**
 * Whether a project's co-dev overlay is materialized, only templated, or absent (ticket 10).
 *
 * The overlay is the `composer.local.json` file that layers `type: path` repos over the git
 * ship-contract in `composer.json` (ADR: co-dev overlay is the intended dev path). Three states,
 * because the overlay's *presence* is itself the first thing `surgeon:overlay` reports:
 *
 *  - {@see Active}   — a live `composer.local.json` is present; the path repos are (or can be) symlinked.
 *  - {@see Template} — only the committed template (`composer.local.json.dist` / `.off`) exists; the
 *                      overlay is off. `materialize` turns it on.
 *  - {@see Absent}   — no overlay file at all; this project has no co-dev overlay to manage.
 */
enum OverlayState: string
{
    case Active = 'active';
    case Template = 'template';
    case Absent = 'absent';
}
