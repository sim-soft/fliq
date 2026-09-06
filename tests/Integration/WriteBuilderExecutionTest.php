<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Simsoft\DB\Drivers\SQLiteDriver;

/**
 * What the write builders do when the statements they emit are actually run.
 *
 * Everything here was verified against a real server before it was written: the
 * shape of a statement is not evidence that it does what it says, and three of
 * these defects produced SQL that looked correct and read or wrote the wrong
 * thing.
 *
 * The PostgreSQL fixture is not reloaded between test classes, so nothing here
 * touches the shared tables — each case owns a table it creates and drops.
 */
class WriteBuilderExecutionTest extends TestCase
{
    protected static bool $pgAvailable = false;
    protected static bool $liteAvailable = false;

    public static function setUpBeforeClass(): void
    {
        if (extension_loaded('pdo_pgsql')) {
            Connection::add('wb_pg', [
                'driver' => 'pgsql',
                'host' => getenv('PG_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('PG_PORT') ?: 5432),
                'database' => getenv('PG_DATABASE') ?: 'sample_db',
                'username' => getenv('PG_USERNAME') ?: 'postgres',
                'password' => getenv('PG_PASSWORD') ?: '',
                'charset' => 'utf8',
                'schema' => 'public',
            ]);

            try {
                Connection::get('wb_pg');
                static::$pgAvailable = true;
            } catch (\Throwable) {
                static::$pgAvailable = false;
            }
        }

        if (extension_loaded('pdo_sqlite')) {
            Connection::add('wb_lite', ['driver' => 'sqlite', 'database' => ':memory:']);

            try {
                Connection::get('wb_lite');
                static::$liteAvailable = true;
            } catch (\Throwable) {
                static::$liteAvailable = false;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::remove('wb_pg');
        Connection::remove('wb_lite');
    }

    private function requirePostgres(): void
    {
        if (!static::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    private function requireSQLite(): void
    {
        if (!static::$liteAvailable) {
            $this->markTestSkipped('ext-pdo_sqlite not available.');
        }
    }

    /**
     * Create a scratch table on the SQLite connection.
     *
     * The in-memory database lives as long as the connection, which outlives
     * this class, so each test names its own table and drops it after.
     *
     * @param string $table The table name.
     * @return void
     */
    private function makeLiteTable(string $table): void
    {
        /** @var SQLiteDriver $driver */
        $driver = Connection::get('wb_lite');
        $pdo = $driver->getPdo();
        $this->assertNotNull($pdo);
        $pdo->exec("DROP TABLE IF EXISTS $table");
        $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INTEGER)");
    }

    /**
     * @param string $table The table name.
     * @return void
     */
    private function dropLiteTable(string $table): void
    {
        /** @var SQLiteDriver $driver */
        $driver = Connection::get('wb_lite');
        $driver->getPdo()?->exec("DROP TABLE IF EXISTS $table");
    }

    #[Test]
    public function postgresUpdateWithIgnoreWritesToTheTableItNames(): void
    {
        // The one that matters. "UPDATE IGNORE t SET ..." is not a syntax error
        // on PostgreSQL — it parses as an update of a table named ignore,
        // aliased t. Where such a table exists the statement succeeds, reports
        // rows affected, and writes to the wrong one; the intended row is
        // untouched and nothing anywhere says so.
        $this->requirePostgres();

        try {
            DB::raw('DROP TABLE IF EXISTS "ignore"', [], 'wb_pg');
            DB::raw('CREATE TABLE "ignore" (id int, score int)', [], 'wb_pg');
            DB::raw('INSERT INTO "ignore" (id, score) VALUES (1, 0)', [], 'wb_pg');

            DB::raw('DROP TABLE IF EXISTS wb_target', [], 'wb_pg');
            DB::raw('CREATE TABLE wb_target (id int, score int)', [], 'wb_pg');
            DB::raw('INSERT INTO wb_target (id, score) VALUES (1, 10)', [], 'wb_pg');

            (new Update('wb_target', ['score' => 99], 'id = 1'))
                ->ignore()
                ->withConnection('wb_pg')
                ->execute();

            $target = DB::query('SELECT score FROM wb_target WHERE id = 1', [], 'wb_pg');
            $decoy = DB::query('SELECT score FROM "ignore" WHERE id = 1', [], 'wb_pg');

            $this->assertSame(99, (int)$target[0]['score'], 'the named table was not updated');
            $this->assertSame(0, (int)$decoy[0]['score'], 'a table named ignore was updated instead');
        } finally {
            DB::raw('DROP TABLE IF EXISTS "ignore"', [], 'wb_pg');
            DB::raw('DROP TABLE IF EXISTS wb_target', [], 'wb_pg');
        }
    }

    #[Test]
    public function postgresDeleteWithMysqlModifiersStillDeletes(): void
    {
        // lowPriority() raised 'relation "low_priority" does not exist' and
        // quick() 'relation "quick" does not exist' — the modifiers were read
        // as the table name. A scheduling hint MySQL alone understands is not
        // worth failing a delete over.
        $this->requirePostgres();

        try {
            DB::raw('DROP TABLE IF EXISTS wb_del', [], 'wb_pg');
            DB::raw('CREATE TABLE wb_del (id int)', [], 'wb_pg');
            DB::raw('INSERT INTO wb_del (id) VALUES (1), (2)', [], 'wb_pg');

            (new Delete('wb_del', 'id = 1'))
                ->lowPriority()
                ->quick()
                ->ignore()
                ->withConnection('wb_pg')
                ->execute();

            $rows = DB::query('SELECT id FROM wb_del ORDER BY id', [], 'wb_pg');

            $this->assertSame([['id' => 2]], $rows);
        } finally {
            DB::raw('DROP TABLE IF EXISTS wb_del', [], 'wb_pg');
        }
    }

    #[Test]
    public function postgresBulkInsertOrIgnoreInsertsItsRows(): void
    {
        // The grammar parenthesised placeholders that already were, giving
        // "VALUES ((?,?),(?,?))" and SQLSTATE[42601] "INSERT has more target
        // columns than expressions". Bulk insertOrIgnore had never worked here.
        $this->requirePostgres();

        try {
            DB::raw('DROP TABLE IF EXISTS wb_bulk', [], 'wb_pg');
            DB::raw('CREATE TABLE wb_bulk (id int PRIMARY KEY, name text)', [], 'wb_pg');
            DB::raw("INSERT INTO wb_bulk (id, name) VALUES (1, 'existing')", [], 'wb_pg');

            (new Insert('wb_bulk', [
                ['id' => 1, 'name' => 'conflicting'],
                ['id' => 2, 'name' => 'fresh'],
                ['id' => 3, 'name' => 'also fresh'],
            ]))->ignore()->withConnection('wb_pg')->execute();

            $rows = DB::query('SELECT id, name FROM wb_bulk ORDER BY id', [], 'wb_pg');

            $this->assertSame([
                ['id' => 1, 'name' => 'existing'],
                ['id' => 2, 'name' => 'fresh'],
                ['id' => 3, 'name' => 'also fresh'],
            ], $rows);
        } finally {
            DB::raw('DROP TABLE IF EXISTS wb_bulk', [], 'wb_pg');
        }
    }

    #[Test]
    public function postgresIgnoredInsertReturnsTheRowItInserted(): void
    {
        $this->requirePostgres();

        try {
            DB::raw('DROP TABLE IF EXISTS wb_ret', [], 'wb_pg');
            DB::raw('CREATE TABLE wb_ret (id int PRIMARY KEY, name text)', [], 'wb_pg');

            $query = (new Insert('wb_ret', ['id' => 1, 'name' => 'a']))
                ->ignore()
                ->returning('id')
                ->withConnection('wb_pg');
            $query->execute();

            $this->assertSame([['id' => 1]], $query->getReturningResult());
            $this->assertSame('1', $query->getLastInsertId());
        } finally {
            DB::raw('DROP TABLE IF EXISTS wb_ret', [], 'wb_pg');
        }
    }

    #[Test]
    public function postgresGivesNoInsertIdForAnInsertItSkipped(): void
    {
        // ON CONFLICT DO NOTHING returns no row, so RETURNING named none; the
        // code then fell through to the driver and answered with the sequence's
        // current value. That is an id belonging to no row the statement wrote,
        // and to no row at all — the conflicting attempt consumed a sequence
        // number without leaving anything behind it.
        $this->requirePostgres();

        try {
            DB::raw('DROP TABLE IF EXISTS wb_skip', [], 'wb_pg');
            DB::raw('CREATE TABLE wb_skip (id serial PRIMARY KEY, name text UNIQUE)', [], 'wb_pg');
            DB::raw("INSERT INTO wb_skip (name) VALUES ('taken')", [], 'wb_pg');

            $query = (new Insert('wb_skip', ['name' => 'taken']))
                ->ignore()
                ->returning('id')
                ->withConnection('wb_pg');
            $query->execute();

            $this->assertSame([], $query->getReturningResult());
            $this->assertNull($query->getLastInsertId(), 'reported an id for a row that does not exist');
        } finally {
            DB::raw('DROP TABLE IF EXISTS wb_skip', [], 'wb_pg');
        }
    }

    #[Test]
    public function postgresWritesToASchemaQualifiedTable(): void
    {
        // Quoted as one identifier this named a table called "public.wb_schema",
        // and the insert failed with 'relation "public.wb_schema" does not
        // exist' — a table the caller had just created.
        $this->requirePostgres();

        try {
            DB::raw('DROP TABLE IF EXISTS public.wb_schema', [], 'wb_pg');
            DB::raw('CREATE TABLE public.wb_schema (id int, name text)', [], 'wb_pg');

            (new Insert('public.wb_schema', ['id' => 1, 'name' => 'a']))
                ->withConnection('wb_pg')
                ->execute();

            $this->assertSame(
                [['id' => 1, 'name' => 'a']],
                DB::query('SELECT id, name FROM public.wb_schema', [], 'wb_pg')
            );
        } finally {
            DB::raw('DROP TABLE IF EXISTS public.wb_schema', [], 'wb_pg');
        }
    }

    #[Test]
    public function sqliteInsertReturnsTheRowItInserted(): void
    {
        // SQLite has had RETURNING since 3.35 and the grammar says so, so the
        // clause was emitted — and the driver never fetched the rows it
        // produced. getReturningResult() answered null, which is what it also
        // answers for a statement that returned nothing.
        $this->requireSQLite();

        try {
            $this->makeLiteTable('wb_lite_ins');

            $query = (new Insert('wb_lite_ins', ['name' => 'a', 'score' => 5]))
                ->returning('id')
                ->withConnection('wb_lite');
            $query->execute();

            $this->assertSame([['id' => 1]], $query->getReturningResult());
            $this->assertSame('1', $query->getLastInsertId());
        } finally {
            $this->dropLiteTable('wb_lite_ins');
        }
    }

    #[Test]
    public function sqliteUpdateReturnsTheRowsItChanged(): void
    {
        $this->requireSQLite();

        try {
            $this->makeLiteTable('wb_lite_upd');
            DB::raw("INSERT INTO wb_lite_upd (name, score) VALUES ('a', 1), ('b', 2)", [], 'wb_lite');

            $query = (new Update('wb_lite_upd', ['score' => 9], 'score < 2'))
                ->returning('id', 'score')
                ->withConnection('wb_lite');
            $query->execute();

            $this->assertSame([['id' => 1, 'score' => 9]], $query->getReturningResult());
        } finally {
            $this->dropLiteTable('wb_lite_upd');
        }
    }

    #[Test]
    public function sqliteDeleteReturnsTheRowsItRemoved(): void
    {
        $this->requireSQLite();

        try {
            $this->makeLiteTable('wb_lite_del');
            DB::raw("INSERT INTO wb_lite_del (name, score) VALUES ('a', 1), ('b', 2)", [], 'wb_lite');

            $query = (new Delete('wb_lite_del', 'score = 2'))
                ->returning('id', 'name')
                ->withConnection('wb_lite');
            $query->execute();

            $this->assertSame([['id' => 2, 'name' => 'b']], $query->getReturningResult());
            $this->assertSame(
                [['id' => 1]],
                DB::query('SELECT id FROM wb_lite_del', [], 'wb_lite')
            );
        } finally {
            $this->dropLiteTable('wb_lite_del');
        }
    }

    #[Test]
    public function sqliteUpdateWithMysqlModifiersStillUpdates(): void
    {
        // ignore() and lowPriority() made this a syntax error on SQLite.
        $this->requireSQLite();

        try {
            $this->makeLiteTable('wb_lite_mod');
            DB::raw("INSERT INTO wb_lite_mod (name, score) VALUES ('a', 1)", [], 'wb_lite');

            (new Update('wb_lite_mod', ['score' => 7], 'id = 1'))
                ->ignore()
                ->lowPriority()
                ->withConnection('wb_lite')
                ->execute();

            $this->assertSame(
                [['score' => 7]],
                DB::query('SELECT score FROM wb_lite_mod WHERE id = 1', [], 'wb_lite')
            );
        } finally {
            $this->dropLiteTable('wb_lite_mod');
        }
    }

    #[Test]
    public function sqliteBulkInsertWritesEveryRow(): void
    {
        $this->requireSQLite();

        try {
            $this->makeLiteTable('wb_lite_bulk');

            (new Insert('wb_lite_bulk', [
                ['name' => 'a', 'score' => 1],
                ['name' => 'b', 'score' => 2],
                ['name' => 'c', 'score' => 3],
            ]))->withConnection('wb_lite')->execute();

            $this->assertSame(
                [
                    ['name' => 'a', 'score' => 1],
                    ['name' => 'b', 'score' => 2],
                    ['name' => 'c', 'score' => 3],
                ],
                DB::query('SELECT name, score FROM wb_lite_bulk ORDER BY id', [], 'wb_lite')
            );
        } finally {
            $this->dropLiteTable('wb_lite_bulk');
        }
    }
}
