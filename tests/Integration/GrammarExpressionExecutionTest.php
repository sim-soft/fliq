<?php

namespace Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

/**
 * Array-column and full-text expressions run against every engine.
 *
 * The point of these is agreement: the same call over the same logical data
 * should select the same rows whether the arrays are native (PostgreSQL) or
 * emulated over JSON (MySQL, SQLite). They did not. MySQL rejected every
 * string handed to arrayContains outright, compared integers as strings, and
 * ran every search in BOOLEAN MODE, where punctuation in the caller's term is
 * query syntax.
 *
 * Neither fixture is reloaded for this class, so every case creates the table
 * it needs and drops it in a finally block.
 */
class GrammarExpressionExecutionTest extends TestCase
{
    protected static bool $mysqlAvailable = false;
    protected static bool $pgAvailable = false;
    protected static bool $liteAvailable = false;

    public static function setUpBeforeClass(): void
    {
        if (extension_loaded('pdo_mysql')) {
            Connection::add('gx_mysql', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'sample_db',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
            ]);

            try {
                Connection::get('gx_mysql');
                static::$mysqlAvailable = true;
            } catch (\Throwable) {
                static::$mysqlAvailable = false;
            }
        }

        if (extension_loaded('pdo_pgsql')) {
            Connection::add('gx_pg', [
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
                Connection::get('gx_pg');
                static::$pgAvailable = true;
            } catch (\Throwable) {
                static::$pgAvailable = false;
            }
        }

        if (extension_loaded('pdo_sqlite')) {
            Connection::add('gx_lite', ['driver' => 'sqlite', 'database' => ':memory:']);

            try {
                Connection::get('gx_lite');
                static::$liteAvailable = true;
            } catch (\Throwable) {
                static::$liteAvailable = false;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::remove('gx_mysql');
        Connection::remove('gx_pg');
        Connection::remove('gx_lite');
    }

    private function requireMySQL(): void
    {
        if (!static::$mysqlAvailable) {
            $this->markTestSkipped('MySQL not available.');
        }
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
     * The ids a query selects, as ints.
     *
     * @param ActiveQuery $query The query to run.
     * @return array<int, int> The id column of every row.
     */
    private function ids(ActiveQuery $query): array
    {
        $rows = iterator_to_array($query->all());

        return array_values(array_map(
            fn(mixed $row): int => (int)((array)$row)['id'],
            $rows
        ));
    }

    /**
     * Create the array fixture: two rows, one with tags/nums, one without.
     *
     * @param string $connection The connection name.
     * @return void
     */
    private function makeArrayTable(string $connection): void
    {
        if ($connection === 'gx_pg') {
            DB::raw('DROP TABLE IF EXISTS gx_arr', [], $connection);
            DB::raw('CREATE TABLE gx_arr (id int PRIMARY KEY, tags text[], nums int[])', [], $connection);
            DB::raw(
                "INSERT INTO gx_arr VALUES (1, ARRAY['php','sql'], ARRAY[1,2]), (2, ARRAY['go'], ARRAY[3])",
                [],
                $connection
            );
            return;
        }

        $type = $connection === 'gx_mysql' ? 'JSON' : 'TEXT';
        $key = $connection === 'gx_mysql' ? 'INT PRIMARY KEY' : 'INTEGER PRIMARY KEY';

        DB::raw('DROP TABLE IF EXISTS gx_arr', [], $connection);
        DB::raw("CREATE TABLE gx_arr (id $key, tags $type, nums $type)", [], $connection);
        DB::raw(
            'INSERT INTO gx_arr VALUES (1, \'["php","sql"]\', \'[1,2]\'), (2, \'["go"]\', \'[3]\')',
            [],
            $connection
        );
    }

    /**
     * A fresh query over the array fixture.
     *
     * @param string $connection The connection name.
     * @return ActiveQuery
     */
    private function arrayQuery(string $connection): ActiveQuery
    {
        return (new ActiveQuery())->withConnection($connection)->from('gx_arr')->select('id');
    }

    // ------------------------------------------------------------------
    // ARRAY COLUMNS
    // ------------------------------------------------------------------

    #[Test]
    public function mysqlFindsAStringInAnArrayColumnInsteadOfRejectingIt(): void
    {
        // JSON_CONTAINS was handed the bare value, which is not JSON text:
        // every string raised "Invalid JSON text in argument 1 to function
        // json_contains" — arrayContains never worked for a string on MySQL.
        $this->requireMySQL();

        try {
            $this->makeArrayTable('gx_mysql');

            $this->assertSame([1], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('tags', 'php')));
            $this->assertSame([2], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('tags', 'go')));
            $this->assertSame([], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('tags', 'rust')));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_arr', [], 'gx_mysql');
        }
    }

    #[Test]
    public function mysqlComparesAnIntegerElementAsAnIntegerNotAsAString(): void
    {
        // PDO binds as a string, so JSON_ARRAY(?) given 2 built ["2"] and
        // matched nothing in a stored [1,2].
        $this->requireMySQL();

        try {
            $this->makeArrayTable('gx_mysql');

            $this->assertSame([1], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('nums', 2, 'int')));
            $this->assertSame([2], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('nums', 3, 'int')));
            $this->assertSame(
                [1],
                $this->ids($this->arrayQuery('gx_mysql')->arrayOverlaps('nums', [2, 9], 'int'))
            );
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_arr', [], 'gx_mysql');
        }
    }

    #[Test]
    public function aValueCarryingADoubleQuoteIsStillJustAValue(): void
    {
        $this->requireMySQL();

        try {
            DB::raw('DROP TABLE IF EXISTS gx_arr', [], 'gx_mysql');
            DB::raw('CREATE TABLE gx_arr (id INT PRIMARY KEY, tags JSON)', [], 'gx_mysql');
            DB::raw('INSERT INTO gx_arr VALUES (1, JSON_ARRAY(?)), (2, JSON_ARRAY(?))', ['a"b', 'plain'], 'gx_mysql');

            $this->assertSame([1], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('tags', 'a"b')));
            $this->assertSame([], $this->ids($this->arrayQuery('gx_mysql')->arrayContains('tags', 'a"c')));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_arr', [], 'gx_mysql');
        }
    }

    #[Test]
    public function allThreeEnginesSelectTheSameRowsForTheSameArrayCall(): void
    {
        // This is the assertion the emulations exist to satisfy, and the one
        // that failed: on the identical data, MySQL threw where PostgreSQL and
        // SQLite both returned row 1.
        $this->requireMySQL();
        $this->requirePostgres();
        $this->requireSQLite();

        $connections = ['gx_mysql', 'gx_pg', 'gx_lite'];

        try {
            foreach ($connections as $connection) {
                $this->makeArrayTable($connection);
            }

            foreach ($connections as $connection) {
                $this->assertSame(
                    [1],
                    $this->ids($this->arrayQuery($connection)->arrayContains('tags', 'php')),
                    "$connection disagreed on contains('tags','php')"
                );
                $this->assertSame(
                    [1],
                    $this->ids($this->arrayQuery($connection)->arrayContains('nums', 2, 'int')),
                    "$connection disagreed on contains('nums',2)"
                );
                $this->assertSame(
                    [2],
                    $this->ids($this->arrayQuery($connection)->arrayOverlaps('tags', ['go', 'rust'])),
                    "$connection disagreed on overlaps('tags',['go','rust'])"
                );
                $this->assertSame(
                    [],
                    $this->ids($this->arrayQuery($connection)->arrayOverlaps('tags', [])),
                    "$connection disagreed on overlaps('tags',[])"
                );
            }
        } finally {
            foreach ($connections as $connection) {
                DB::raw('DROP TABLE IF EXISTS gx_arr', [], $connection);
            }
        }
    }

    #[Test]
    public function anEmptyOverlapRunsRatherThanFailingToParse(): void
    {
        // SQLite emitted IN (), which it accepts and no other engine does.
        $this->requireSQLite();

        try {
            $this->makeArrayTable('gx_lite');

            $query = $this->arrayQuery('gx_lite')->arrayOverlaps('tags', []);
            $this->assertStringNotContainsString('IN ()', $query->getSQL());
            $this->assertSame([], $this->ids($query));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_arr', [], 'gx_lite');
        }
    }

    // ------------------------------------------------------------------
    // FULL-TEXT SEARCH
    // ------------------------------------------------------------------

    /**
     * Create the full-text fixture on a server-backed connection.
     *
     * @param string $connection The connection name.
     * @return void
     */
    private function makeTextTable(string $connection): void
    {
        DB::raw('DROP TABLE IF EXISTS gx_txt', [], $connection);

        if ($connection === 'gx_mysql') {
            DB::raw(
                'CREATE TABLE gx_txt (id INT PRIMARY KEY, title TEXT, body TEXT, '
                . 'FULLTEXT KEY ft (title, body)) ENGINE=InnoDB',
                [],
                $connection
            );
        } else {
            DB::raw('CREATE TABLE gx_txt (id int PRIMARY KEY, title text, body text)', [], $connection);
        }

        DB::raw(
            "INSERT INTO gx_txt VALUES (1, 'database systems', 'about query planners'), "
            . "(2, 'graph systems', 'about databases')",
            [],
            $connection
        );
    }

    /**
     * Run a full-text search over the text fixture.
     *
     * @param string $connection The connection name.
     * @param string $term The search term.
     * @param string $mode The search mode.
     * @return array<int, int> The ids selected.
     */
    private function search(string $connection, string $term, string $mode): array
    {
        return $this->ids(
            (new ActiveQuery())->withConnection($connection)->from('gx_txt')->select('id')
                ->whereFulltext(['title', 'body'], $term, $mode)
        );
    }

    #[Test]
    public function mysqlPlainSearchNoLongerTreatsAHyphenAsAnExclusion(): void
    {
        // In BOOLEAN MODE 'database -systems' excluded every row that said
        // "systems" and returned nothing. An ordinary search term should not
        // carry operators the caller never wrote.
        $this->requireMySQL();

        try {
            $this->makeTextTable('gx_mysql');

            $this->assertSame([1, 2], $this->search('gx_mysql', 'database -systems', 'plain'));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_mysql');
        }
    }

    #[Test]
    public function mysqlPlainSearchNoLongerCrashesOnATermContainingAPlus(): void
    {
        // 'C++ database' raised QueryException: syntax error, unexpected '+'.
        $this->requireMySQL();

        try {
            $this->makeTextTable('gx_mysql');

            $this->assertSame([1], $this->search('gx_mysql', 'C++ database', 'plain'));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_mysql');
        }
    }

    #[Test]
    public function mysqlPhraseSearchRequiresTheWordsToBeAdjacent(): void
    {
        // Phrase mode emitted the same SQL as every other mode, so it was not
        // a phrase search at all.
        $this->requireMySQL();

        try {
            $this->makeTextTable('gx_mysql');

            $this->assertSame([1], $this->search('gx_mysql', 'database systems', 'phrase'));
            $this->assertSame([], $this->search('gx_mysql', 'systems database', 'phrase'));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_mysql');
        }
    }

    #[Test]
    public function aQuoteInAPhraseTermCannotReopenTheQueryAsOperators(): void
    {
        // With the quote passed through, 'data"base systems' closed the phrase
        // and the rest was read as boolean syntax — it matched both rows,
        // neither of which contains the phrase.
        $this->requireMySQL();

        try {
            $this->makeTextTable('gx_mysql');

            $this->assertSame([], $this->search('gx_mysql', 'data"base systems', 'phrase'));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_mysql');
        }
    }

    #[Test]
    public function mysqlWebsearchStillHonoursTheOperatorsTheCallerWrote(): void
    {
        $this->requireMySQL();

        try {
            $this->makeTextTable('gx_mysql');

            $this->assertSame([], $this->search('gx_mysql', 'database -systems', 'websearch'));
            $this->assertSame([1], $this->search('gx_mysql', '+database +planners', 'websearch'));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_mysql');
        }
    }

    #[Test]
    public function mysqlAndPostgresAgreeOnWhatEachSearchModeMeans(): void
    {
        $this->requireMySQL();
        $this->requirePostgres();

        try {
            $this->makeTextTable('gx_mysql');
            $this->makeTextTable('gx_pg');

            foreach ([
                ['plain', 'database systems'],
                ['phrase', 'database systems'],
                ['phrase', 'systems database'],
                ['phrase', 'data"base systems'],
                ['websearch', 'database -systems'],
            ] as [$mode, $term]) {
                $this->assertSame(
                    $this->search('gx_pg', $term, $mode),
                    $this->search('gx_mysql', $term, $mode),
                    "the engines disagreed on $mode '$term'"
                );
            }
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_mysql');
            DB::raw('DROP TABLE IF EXISTS gx_txt', [], 'gx_pg');
        }
    }

    #[Test]
    public function sqliteSearchesAnFts5TableItIsPointedAt(): void
    {
        $this->requireSQLite();

        try {
            DB::raw('DROP TABLE IF EXISTS gx_fts', [], 'gx_lite');
            DB::raw('CREATE VIRTUAL TABLE gx_fts USING fts5(title, body)', [], 'gx_lite');
            DB::raw(
                "INSERT INTO gx_fts (rowid, title, body) VALUES "
                . "(1, 'database systems', 'about planners'), (2, 'graph theory', 'about databases')",
                [],
                'gx_lite'
            );

            $query = fn(string $term, string $mode): ActiveQuery => (new ActiveQuery())
                ->withConnection('gx_lite')->from('gx_fts')->select(new Raw('rowid AS id'))
                ->whereFulltext('title', $term, $mode);

            $this->assertSame([1], $this->ids($query('database', 'plain')));
            $this->assertSame([1], $this->ids($query('database systems', 'phrase')));
            $this->assertSame([], $this->ids($query('systems database', 'phrase')));
        } finally {
            DB::raw('DROP TABLE IF EXISTS gx_fts', [], 'gx_lite');
        }
    }

    #[Test]
    public function sqliteSaysSoRatherThanSearchingOneColumnOfSeveral(): void
    {
        // whereFulltext(['title', 'body'], ...) searched title and dropped
        // body without a word, so the answer was quietly incomplete.
        $this->requireSQLite();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matches one column at a time');

        (new ActiveQuery())->withConnection('gx_lite')->from('gx_fts')
            ->whereFulltext(['title', 'body'], 'database');
    }
}
