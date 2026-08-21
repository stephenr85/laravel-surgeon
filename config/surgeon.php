<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | `granted_abilities` is the default-DENY write gate for surgeon over MCP. A
    | writer tool (annotated destructive, gated on `surgeon.write`) is invisible
    | in tools/list and refused before tools/call unless the ability it needs is
    | listed here. Read tools are ungated and unaffected.
    |
    | Empty is the safe floor: read-only surgeon over MCP until write is
    | deliberately enabled. A host wanting a real gate (e.g. off an
    | authenticated capability rather than a static list) overrides
    | `SurgeonMcpServer::toolAuthorizer()` instead.
    |
    */
    'mcp' => [
        'granted_abilities' => [],
    ],

];
