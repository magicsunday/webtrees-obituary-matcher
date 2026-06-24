<?php

/**
 * This file is part of the package magicsunday/webtrees-obituary-matcher.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ObituaryMatcher\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Throwable;

use function getenv;
use function preg_split;

/**
 * Bootstrap for tests that exercise the webtrees-coupled adapter layer against a
 * real GEDCOM tree. It mirrors the production schema (no test-only fixtures) so
 * the assertions read the same tables the live module reads.
 *
 * The database engine is selected via the `WT_TEST_DB_DRIVER` environment
 * variable and defaults to in-memory SQLite, which keeps the common test run
 * dependency-free. Setting it to `mysql` (with the matching `WT_TEST_DB_HOST` /
 * `_PORT` / `_NAME` / `_USER` / `_PASSWORD`) points the suite at a real MySQL
 * server instead, which is the gate for the whole `ONLY_FULL_GROUP_BY` class of
 * engine-strictness bugs that SQLite silently tolerates.
 *
 * Each test gets a fresh schema via {@see setUp()} and an importer that loads a
 * GEDCOM string into a fresh tree.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-obituary-matcher/
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Whether the persistent-server schema has already been built this process.
     * A real MySQL keeps its schema across tests, so the expensive migration
     * runs once and every later test only empties the data; the throwaway
     * in-memory SQLite database is rebuilt per test instead, which is
     * effectively free.
     *
     * @var bool
     */
    private static bool $persistentSchemaReady = false;

    /**
     * Put a freshly-seeded production schema in front of each test, then log in
     * an administrator so the GEDCOM import path stores real names.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Drop any site preferences cached from a previous test —
        // MigrationService reads WT_SCHEMA_VERSION from this static and
        // a stale value would skip the schema build.
        Site::$preferences = [];

        // Boot webtrees' DI container so Registry::container() resolves —
        // every service the import path touches (Log, Site, Gedcom factory)
        // looks the container up statically.
        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);

        $this->prepareDatabase();

        I18N::init('en-US');

        // Element factory normally bound by webtrees' RoutingMiddleware.
        (new Gedcom())->registerTags(Registry::elementFactory(), true);

        // Webtrees treats the visitor (guest) user as no-access by default,
        // so name records imported via GedcomImportService are stored with
        // n_givn = "Private". Log in as an admin so the import path sees
        // the real names — production trees run the same code path for
        // their own admin during import.
        $userService = new UserService();
        $admin       = $userService->create('admin', 'Admin', 'admin@example.test', 'secret');
        $admin->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');
        Auth::login($admin);
    }

    /**
     * Hand the test a freshly-seeded schema. The throwaway SQLite path connects
     * and migrates anew per test (cheap, in-memory). The persistent MySQL path
     * migrates once per process and afterwards only empties and re-seeds,
     * because re-running the full migration against a real server costs roughly
     * twenty seconds per test.
     *
     * @return void
     */
    private function prepareDatabase(): void
    {
        if ($this->resolvedDriver() === DB::SQLITE) {
            $this->connectDatabase();
            $this->migrateAndSeed();

            return;
        }

        if (!self::$persistentSchemaReady) {
            $this->connectDatabase();

            // Clear any schema left behind by an earlier run before the
            // one-time migration rebuilds it from scratch.
            DB::connection()->getSchemaBuilder()->dropAllTables();
            $this->migrateAndSeed();

            self::$persistentSchemaReady = true;

            return;
        }

        $this->emptyAllTables();

        // Restore the sentinel DEFAULT_USER / DEFAULT_TREE / default_resn rows
        // the wipe removed (all three seeders are idempotent upserts).
        (new MigrationService())->seedDatabase();
    }

    /**
     * Run every webtrees migration and seed the default data.
     *
     * @return void
     */
    private function migrateAndSeed(): void
    {
        $migrationService = new MigrationService();
        $migrationService->updateSchema('\Fisharebest\Webtrees\Schema', 'WT_SCHEMA_VERSION', Webtrees::SCHEMA_VERSION);
        $migrationService->seedDatabase();
    }

    /**
     * Empty every table on the persistent connection so the next test starts
     * from a clean slate without paying for a full schema rebuild. Foreign-key
     * checks are suspended for the duration so the order does not matter.
     *
     * Uses `DELETE` rather than `TRUNCATE`: the fixtures are tiny, and on MySQL
     * each `TRUNCATE` recreates the table's tablespace file — orders of
     * magnitude slower than deleting a handful of rows across the suite.
     *
     * @return void
     */
    private function emptyAllTables(): void
    {
        $connection = DB::connection();
        $tables     = $connection->getSchemaBuilder()->getTableListing(null, false);

        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->statement('DELETE FROM `' . $table . '`');
            }
        } finally {
            // Restore enforcement even if a DELETE throws: the persistent
            // connection is reused across the whole suite (never reconnected
            // on the MySQL path), so a leaked `FOREIGN_KEY_CHECKS = 0` would
            // silently disable referential-integrity for every later test.
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Open the configured test connection. Defaults to a throwaway in-memory
     * SQLite database; honours `WT_TEST_DB_DRIVER` (plus the matching
     * `WT_TEST_DB_HOST` / `_PORT` / `_NAME` / `_USER` / `_PASSWORD`) to target a
     * real MySQL server instead.
     *
     * @return void
     */
    private function connectDatabase(): void
    {
        $driver = $this->resolvedDriver();

        if ($driver === DB::SQLITE) {
            DB::connect(
                driver: DB::SQLITE,
                host: '',
                port: '',
                database: ':memory:',
                username: '',
                password: '',
                prefix: 'wt_',
                key: '',
                certificate: '',
                ca: '',
                verify_certificate: false,
            );

            return;
        }

        DB::connect(
            driver: $driver,
            host: $this->databaseEnv('WT_TEST_DB_HOST', '127.0.0.1'),
            port: $this->databaseEnv('WT_TEST_DB_PORT', '3306'),
            database: $this->databaseEnv('WT_TEST_DB_NAME', 'webtrees_test'),
            username: $this->databaseEnv('WT_TEST_DB_USER', 'root'),
            password: $this->databaseEnv('WT_TEST_DB_PASSWORD', ''),
            prefix: 'wt_',
            key: '',
            certificate: '',
            ca: '',
            verify_certificate: false,
        );

        // webtrees sets `sql_mode = 'ANSI,STRICT_ALL_TABLES'` on connect, and on
        // MySQL ANSI implies ONLY_FULL_GROUP_BY with full functional-dependency
        // detection — the engine strictness this lane exists to gate.
        $this->tuneThrowawayDatabase();
    }

    /**
     * Trade durability for speed on the throwaway CI database. The container is
     * discarded after the run and the per-test wipe commits often, so skipping
     * the redo-log and binlog fsyncs cuts the suite's wall-clock sharply.
     * Best-effort: ignored when the account lacks SYSTEM_VARIABLES_ADMIN.
     *
     * @return void
     */
    private function tuneThrowawayDatabase(): void
    {
        try {
            DB::connection()->statement('SET GLOBAL innodb_flush_log_at_trx_commit = 2');
            DB::connection()->statement('SET GLOBAL sync_binlog = 0');
        } catch (Throwable) {
            // Non-fatal — the suite still runs, just slower.
        }
    }

    /**
     * Resolve the configured database driver, defaulting to SQLite when the
     * `WT_TEST_DB_DRIVER` environment variable is unset or empty.
     *
     * @return string
     */
    private function resolvedDriver(): string
    {
        return $this->databaseEnv('WT_TEST_DB_DRIVER', DB::SQLITE);
    }

    /**
     * Read a database connection setting from the environment, falling back to
     * the supplied default when the variable is unset or empty.
     *
     * @param string $name    Name of the environment variable to read
     * @param string $default Value to use when the variable is unset or empty
     *
     * @return string
     */
    private function databaseEnv(string $name, string $default): string
    {
        $value = getenv($name);

        if (($value === false) || ($value === '')) {
            return $default;
        }

        return $value;
    }

    /**
     * Reset the per-test auth/session state and drop only the throwaway SQLite
     * connection; the persistent MySQL connection and its one-time schema
     * survive for the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::logout();
        Session::clear();

        // Keep the persistent connection (and its one-time schema) alive for
        // the next test; only the throwaway SQLite database is dropped per run.
        if ($this->resolvedDriver() === DB::SQLITE) {
            DB::connection()->disconnect();
        }

        // Wipe the static site-preferences cache so the next test does not see
        // this run's schema version.
        Site::$preferences = [];

        parent::tearDown();
    }

    /**
     * Import a GEDCOM string into a fresh tree and return the resulting Tree.
     * The placeholder header and INDI that {@see TreeService::create()} seeds
     * are removed before importing so the assertions read only the fixture.
     *
     * @param string $gedcom The full GEDCOM document to import
     * @param string $name   The tree name; pass a distinct value to import two
     *                       coexisting trees in one test (defaults to "fixture")
     *
     * @return Tree
     */
    protected function importFixtureTree(string $gedcom, string $name = 'fixture'): Tree
    {
        $gedcomImportService = new GedcomImportService();
        $treeService         = new TreeService($gedcomImportService);
        $tree                = $treeService->create($name, $name);

        // TreeService::create seeds a placeholder header + INDI — wipe
        // before importing the fixture so the assertions are deterministic.
        $treeId = $tree->id();

        foreach (
            [
                'individuals' => 'i_file',
                'families'    => 'f_file',
                'sources'     => 's_file',
                'other'       => 'o_file',
                'places'      => 'p_file',
                'placelinks'  => 'pl_file',
                'name'        => 'n_file',
                'dates'       => 'd_file',
                'change'      => 'gedcom_id',
                'link'        => 'l_file',
                'media_file'  => 'm_file',
                'media'       => 'm_file',
            ] as $table => $column
        ) {
            Capsule::table($table)
                ->where($column, '=', $treeId)
                ->delete();
        }

        $records = preg_split('/\n(?=0)/', $gedcom);

        if ($records === false) {
            $records = [];
        }

        foreach ($records as $record) {
            $gedcomImportService->importRecord($record, $tree, false);
        }

        // Drop the cached tree list so a TreeService::find() against the freshly
        // created tree resolves it (the all-trees array cache is populated lazily
        // and would otherwise miss a tree created after its first read).
        Registry::cache()->array()->forget('all-trees');

        return $tree;
    }

    /**
     * Resolve an individual by its XREF within the given tree.
     *
     * @param string $xref The XREF identifier of the individual to resolve
     * @param Tree   $tree The tree the individual belongs to
     *
     * @return Individual|null
     */
    protected function individual(string $xref, Tree $tree): ?Individual
    {
        return Registry::individualFactory()->make($xref, $tree);
    }

    /**
     * Resolve an individual, asserting it exists so PHPStan narrows away the null
     * and the test fails loudly on a broken fixture rather than on a later type
     * error.
     *
     * @param string $xref The XREF identifier of the individual to resolve
     * @param Tree   $tree The tree the individual belongs to
     *
     * @return Individual The resolved individual
     */
    protected function person(string $xref, Tree $tree): Individual
    {
        $individual = $this->individual($xref, $tree);

        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
    }
}
