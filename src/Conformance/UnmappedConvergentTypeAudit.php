<?php

namespace Rushing\Surgeon\Conformance;

use Rushing\Doctor\Finding;
use Rushing\SchemaConvergence\ColumnTypeEquivalence;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;

/**
 * A standing, surgeon-native conformance audit: **a column type declared in a convergent migration stub
 * that `ColumnTypeEquivalence` has no mapping for**, and would therefore report as `unverified`
 * (beam-facade ticket 183a).
 *
 * ## Why this exists, and why it is measured before anything escalates
 *
 * `ConvergentTable` compares a Blueprint's LARAVEL type against the DATABASE's own `type_name`, through
 * a per-driver map. An unmapped Laravel type returns **`null` — unverified, never a conflict**: the map
 * is deliberately allowed to be incomplete, because a false conflict stops an install and a missed one
 * leaves the status quo.
 *
 * beam-facade [115] ruled that `unverified` should **escalate to a conflict when the table holds rows**,
 * and ruled it *measured before shipped* — an escalation is a thing that stops an install, and shipping
 * one blind against ~141 convergent stubs is how this estate manufactures a red suite it then attributes
 * to the wrong seam. **This audit is that measurement's file-only half.**
 *
 * ## The half it is, and the half it deliberately is not
 *
 * 183a asks *"which declared types COULD go unverified"* — a pure question about stub text and a map.
 * 183b asks *"and which of those sit on a table that holds rows here"*, which needs a live connection and
 * `MigrationRehearsal` to learn a stub's declared shape by executing it.
 *
 * **`MigrationRehearsal` cannot be reached from this package without inverting the tier dependency** — it
 * lives in `splicewire/laravel-beam`, and surgeon is a foundation package that must not require beam. So
 * the rows half is beam-tier by construction and 183b lands there. This half stays here **because that is
 * the entire point**: [36] put convergent guards into five *beamless* `rushing/*` packages, every existing
 * convergence instrument is beam-tier under `BeamDoctorManifest`, and `surgeon:audit` is the estate's
 * tier-neutral sweep. Those five get this audit and not 183b — a smaller answer than 115 assumed it was
 * buying, stated rather than hidden.
 *
 * ## Detection can be UNAVAILABLE, and that is a Warn where it matters
 *
 * `rushing/laravel-schema-convergence` is **not** a dependency of this package — nothing here may require
 * it — so `ColumnTypeEquivalence` is guarded by `class_exists()`, the same posture `LocalMcpWiringAudit`
 * takes with `laravel/mcp`. A root that ships convergent stubs and cannot load the map is reported as
 * **unread, not clean**; a root with no convergent stubs at all passes quietly, because it is not a
 * convergence question.
 *
 * ## Three things this implementation is built around
 *
 * - **The map's coverage is asked through `isMapped()`, never through `matches()`.** `matches()` needs a
 *   live column to pass as `$actual` and folds *"no mapping"* into `null` only in its presence. Asking it
 *   with a fabricated `$actual` would conflate an unmapped type with a disagreeing column, which are
 *   different findings and only the first is this audit's.
 * - **A Blueprint method is not always a column type, and pretending otherwise manufactures findings.**
 *   `$table->index(...)` declares no column; `$table->id()` declares a `bigInteger`; `$table->morphs()`
 *   declares two columns of two different types. So methods are classified three ways
 *   ({@see self::STRUCTURAL}, {@see self::EXPANSIONS}, {@see self::TYPES}) and **anything that matches
 *   none of the three is reported as unclassified rather than assumed to be a type** — an audit that
 *   silently treats an unrecognised method as an unmapped type is louder and less true.
 * - **The scan is textual, and says so.** Reading `$table-><method>(` out of a stub is not the same as
 *   executing the declaration: a type composed at runtime, or a column added inside a conditional this
 *   scan cannot evaluate, is seen as declared unconditionally. That is the right direction to be wrong in
 *   for an advisory census — it over-reports rather than under-reports — but it is not a substitute for
 *   183b, and the scope finding says so.
 *
 * **Advisory, never a gate.** What this reports is a fact about a *map's* coverage, and its remedy is
 * "add the pairing", which is cheap. Nothing here should redden an exit code.
 */
class UnmappedConvergentTypeAudit implements SuggestsOperations
{
    public const CHECK = 'unmapped-convergent-type';

    /** The marker that makes a migration file this audit's business at all. */
    public const MARKER = 'ConvergentTable';

    /**
     * Blueprint methods that declare no column — indexes, constraints, table-level settings, drops.
     * Calling one of these is not a type declaration and must not be scored as an unmapped one.
     */
    public const STRUCTURAL = [
        'index', 'unique', 'primary', 'spatialIndex', 'fullText', 'rawIndex', 'foreign',
        'dropColumn', 'dropIndex', 'dropUnique', 'dropPrimary', 'dropForeign', 'dropFullText',
        'dropSpatialIndex', 'dropTimestamps', 'dropTimestampsTz', 'dropSoftDeletes',
        'dropSoftDeletesTz', 'dropMorphs', 'dropRememberToken', 'renameColumn', 'renameIndex',
        'comment', 'engine', 'charset', 'collation', 'temporary', 'rename', 'after',
    ];

    /**
     * Blueprint convenience methods that expand into one or more columns of OTHER declared types. The
     * value is the list of types the method actually emits — `morphs()` is two columns of two types, and
     * an audit that scored the method name itself would report `morphs` as an unmapped type forever.
     *
     * @var array<string, list<string>>
     */
    public const EXPANSIONS = [
        'id' => ['bigInteger'],
        'increments' => ['integer'],
        'bigIncrements' => ['bigInteger'],
        'mediumIncrements' => ['mediumInteger'],
        'smallIncrements' => ['smallInteger'],
        'tinyIncrements' => ['tinyInteger'],

        'foreignId' => ['bigInteger'],
        'foreignIdFor' => ['bigInteger'],
        'foreignUuid' => ['uuid'],
        'foreignUlid' => ['ulid'],

        'unsignedBigInteger' => ['bigInteger'],
        'unsignedInteger' => ['integer'],
        'unsignedMediumInteger' => ['mediumInteger'],
        'unsignedSmallInteger' => ['smallInteger'],
        'unsignedTinyInteger' => ['tinyInteger'],
        'unsignedDecimal' => ['decimal'],

        'timestamps' => ['timestamp'],
        'nullableTimestamps' => ['timestamp'],
        'timestampsTz' => ['timestampTz'],
        'softDeletes' => ['timestamp'],
        'softDeletesTz' => ['timestampTz'],
        'rememberToken' => ['string'],

        'morphs' => ['string', 'bigInteger'],
        'nullableMorphs' => ['string', 'bigInteger'],
        'numericMorphs' => ['string', 'bigInteger'],
        'nullableNumericMorphs' => ['string', 'bigInteger'],
        'uuidMorphs' => ['string', 'uuid'],
        'nullableUuidMorphs' => ['string', 'uuid'],
        'ulidMorphs' => ['string', 'ulid'],
        'nullableUlidMorphs' => ['string', 'ulid'],
    ];

    /**
     * Blueprint methods whose own name IS the declared type — `addColumn('<method>', …)`. Listed rather
     * than assumed, so that a method matching none of the three lists can be reported as unclassified.
     */
    public const TYPES = [
        'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime', 'dateTimeTz', 'decimal',
        'double', 'enum', 'float', 'geography', 'geometry', 'integer', 'ipAddress', 'json', 'jsonb',
        'longText', 'macAddress', 'mediumInteger', 'mediumText', 'set', 'smallInteger', 'string',
        'text', 'time', 'timeTz', 'timestamp', 'timestampTz', 'tinyInteger', 'tinyText', 'ulid',
        'uuid', 'vector', 'year',
    ];

    /** The one line the report always carries about what it did NOT do. */
    public const SCOPE_NOTE = 'Scope: stub TEXT and the equivalence map only — no declaration was executed and no database was read, so a type composed at runtime or a column added under a condition this scan cannot evaluate is counted as declared. That over-reports rather than under-reports, and it is not a substitute for the populated-table half (183b), which is beam-tier because it needs MigrationRehearsal and a live connection.';

    /**
     * The map is reached through two injectable readers rather than a hard reference, and that is a
     * TESTABILITY decision with a supply consequence worth stating: `rushing/laravel-schema-convergence`
     * is not installed in this package's own `vendor/`, so a test of the mapped/unmapped branches would
     * otherwise require adding it as a `require-dev` — a supply change to prove a guard. Injecting the
     * two readers lets the audit be exercised on both sides of `detectionAvailable()` with no dependency
     * at all, and the production default is still the real class behind `class_exists()`.
     *
     * @param  string  $appRoot  the host app root (holds `vendor/composer/installed.json`)
     * @param  string  $migrationsDir  the host's own migrations root, relative to $appRoot
     * @param  (callable(string): bool)|null  $isMapped  override the coverage question (tests)
     * @param  (callable(): list<string>)|null  $mappedTypes  override the map's own inventory (tests)
     */
    public function __construct(
        public string $appRoot,
        public string $migrationsDir = 'database/migrations',
        private $isMapped = null,
        private $mappedTypes = null,
    ) {}

    /**
     * Whether the equivalence map can be reached here. `rushing/laravel-schema-convergence` is not a
     * dependency of this package and must not become one, so its absence is a live state, not a
     * hypothetical — every root that installs surgeon without it lands on this branch.
     */
    public function detectionAvailable(): bool
    {
        return $this->isMapped !== null || class_exists(ColumnTypeEquivalence::class);
    }

    /** Is this declared Laravel type present in the equivalence map at all? */
    private function mapped(string $type): bool
    {
        return $this->isMapped !== null
            ? (bool) ($this->isMapped)($type)
            : ColumnTypeEquivalence::isMapped($type);
    }

    /** @return list<string> */
    private function inventory(): array
    {
        return $this->mappedTypes !== null
            ? (array) ($this->mappedTypes)()
            : ColumnTypeEquivalence::mappedTypes();
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        $files = $this->convergentFiles();

        if ($files === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK.'.no-scope',
                'No convergent migration stub is installed at this root — nothing declares a type through ConvergentTable, so the equivalence map governs nothing here.',
            ))];
        }

        if (! $this->detectionAvailable()) {
            // Warn, not pass: this root ships convergent stubs and the map that judges them cannot be
            // loaded, so the answer is UNREAD. A clean result here would be the failure mode the whole
            // convergence-measurement effort exists to remove.
            return [new FixableFinding(Finding::warn(
                self::CHECK.'.detection-unavailable',
                sprintf(
                    'Type coverage is UNCHECKED across %d convergent stub(s): %s could not be loaded '.
                    '(rushing/laravel-schema-convergence is not installed here, and surgeon must not '.
                    'require it). This is not a clean estate, it is an unread one.',
                    count($files),
                    ColumnTypeEquivalence::class,
                ),
            ))];
        }

        $unmapped = [];
        $unclassified = [];
        $mappedSeen = [];

        foreach ($files as $file) {
            foreach ($this->declaredIn($file) as $method => $types) {
                if ($types === null) {
                    $unclassified[$method][] = $file;

                    continue;
                }

                foreach ($types as $type) {
                    if ($this->mapped($type)) {
                        $mappedSeen[$type] = true;

                        continue;
                    }

                    $unmapped[$type][$method] = true;
                    $unmapped[$type]['__files'][$file] = true;
                }
            }
        }

        $findings = [];

        foreach ($this->sortedKeys($unmapped) as $type) {
            $findings[] = $this->unmappedFinding($type, $unmapped[$type]);
        }

        foreach ($this->sortedKeys($unclassified) as $method) {
            $findings[] = $this->unclassifiedFinding($method, $unclassified[$method]);
        }

        if ($findings === []) {
            $findings[] = new FixableFinding(Finding::pass(
                self::CHECK.'.covered',
                sprintf(
                    'Every declared column type across %d convergent stub(s) is mapped — %d distinct type(s) '.
                    'seen, all present in ColumnTypeEquivalence. Nothing here would report `unverified`, so '.
                    'nothing here would newly refuse under a populated-table escalation.',
                    count($files),
                    count($mappedSeen),
                ),
            ));
        }

        $findings[] = new FixableFinding(Finding::pass(
            self::CHECK.'.scope',
            sprintf(
                'Scanned %d convergent stub(s) against a map covering %d declared type(s). %s',
                count($files),
                count($this->inventory()),
                self::SCOPE_NOTE,
            ),
        ));

        return $findings;
    }

    /**
     * One finding per unmapped TYPE, not per occurrence: the remedy is a single entry added to the map,
     * so the enumeration is per remedy. The methods that produced it ride along, because
     * `nullableUuidMorphs` reaching the report as `ulid` is otherwise unfindable in the stub.
     *
     * @param  array<string, mixed>  $seen
     */
    private function unmappedFinding(string $type, array $seen): FixableFinding
    {
        $files = array_keys($seen['__files'] ?? []);
        unset($seen['__files']);
        $methods = array_keys($seen);
        sort($methods);

        return new FixableFinding(
            Finding::warn(
                self::CHECK.'.unmapped',
                sprintf(
                    'Declared type `%s` has no entry in ColumnTypeEquivalence, so every convergent guard '.
                    'declaring it reports UNVERIFIED — never a conflict today, and a refusal the day the '.
                    'populated-table escalation ships. Declared through %s in %d stub(s): %s. The remedy is '.
                    'one entry in the map (Laravel type => driver => accepted `type_name` values), which '.
                    'makes the escalation moot for this pairing.',
                    $type,
                    implode(', ', array_map(static fn (string $m) => "`\$table->{$m}()`", $methods)),
                    count($files),
                    implode(', ', array_map(static fn (string $f) => basename($f), array_slice($files, 0, 6)))
                        .(count($files) > 6 ? sprintf(' (+%d more)', count($files) - 6) : ''),
                ),
            ),
            OperationSuggestion::advisory(
                sprintf('Add a `%s` entry to ColumnTypeEquivalence::ACCEPTS, or confirm the type is deliberately unmapped.', $type),
                'rushing/laravel-schema-convergence',
            ),
        );
    }

    /**
     * A Blueprint method this audit could not place in any of its three lists. Reported rather than
     * guessed: assuming an unrecognised method is a column type manufactures an unmapped-type finding
     * for something like a macro or a package's own Blueprint extension, and the remedy would then be
     * to pollute the equivalence map with a name that is not a type.
     *
     * @param  list<string>  $files
     */
    private function unclassifiedFinding(string $method, array $files): FixableFinding
    {
        $files = array_values(array_unique($files));

        return new FixableFinding(
            Finding::warn(
                self::CHECK.'.unclassified',
                sprintf(
                    '`$table->%s()` is called in %d convergent stub(s) (%s) and this audit cannot say what '.
                    'it declares — it is in none of the structural, expansion or type lists. It is either a '.
                    'Blueprint method added since these lists were written, a macro, or a package Blueprint '.
                    'extension. Reported rather than assumed to be a type: guessing would manufacture an '.
                    'unmapped-type finding whose remedy is to put a non-type into the equivalence map.',
                    $method,
                    count($files),
                    implode(', ', array_map(static fn (string $f) => basename($f), array_slice($files, 0, 6)))
                        .(count($files) > 6 ? sprintf(' (+%d more)', count($files) - 6) : ''),
                ),
            ),
            OperationSuggestion::advisory(
                sprintf('Classify `$table->%s()` into UnmappedConvergentTypeAudit::STRUCTURAL, ::EXPANSIONS or ::TYPES.', $method),
                'rushing/laravel-surgeon',
            ),
        );
    }

    /**
     * The declared types in one stub, keyed by the Blueprint method that produced them. A `null` value
     * means the method could not be classified — distinct from an empty list, which means the method
     * declares no column at all.
     *
     * @return array<string, list<string>|null>
     */
    public function declaredIn(string $file): array
    {
        $contents = (string) @file_get_contents($file);

        if (preg_match_all('/\$table\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $contents, $matches) === 0) {
            return [];
        }

        $found = [];

        foreach (array_unique($matches[1]) as $method) {
            if (in_array($method, self::STRUCTURAL, true)) {
                continue;
            }

            if (isset(self::EXPANSIONS[$method])) {
                $found[$method] = self::EXPANSIONS[$method];

                continue;
            }

            $found[$method] = in_array($method, self::TYPES, true) ? [$method] : null;
        }

        return $found;
    }

    /**
     * Every installed package's migration stubs plus the host's own, filtered to the files that actually
     * name {@see self::MARKER}. Provenance comes from composer's own install record for the reason
     * {@see PublishedMigrationDriftAudit} documents: `vendor/<vendor>/<pkg>` is routinely a symlink into
     * a co-dev checkout carrying its own `vendor/`, so a recursive walk does not terminate usefully.
     *
     * @return list<string>
     */
    public function convergentFiles(): array
    {
        $roots = [];

        foreach (InstalledPackages::fromHostRoot($this->appRoot)->packages as $package) {
            $roots[] = rtrim($package['path'], '/').'/database/migrations';
        }

        $roots[] = rtrim($this->appRoot, '/').'/'.trim($this->migrationsDir, '/');

        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (['/*.php', '/*/*.php', '/*.php.stub', '/*/*.php.stub'] as $pattern) {
                foreach ((array) glob($root.$pattern) as $file) {
                    if (is_string($file) && str_contains((string) @file_get_contents($file), self::MARKER)) {
                        $files[realpath($file) ?: $file] = true;
                    }
                }
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return list<string>
     */
    private function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);

        return array_map(static fn ($k) => (string) $k, $keys);
    }
}
