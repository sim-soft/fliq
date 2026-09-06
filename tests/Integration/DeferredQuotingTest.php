<?php

namespace Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

/**
 * The deferred-quoting fixes, run against both engines.
 *
 * Several of these defects only appear when the grammar quoting the statement
 * is not the grammar it is run against, so they cannot be seen on MySQL alone.
 * Every expectation here is checked against a value the database itself
 * computes, not against an expected SQL string.
 */
class DeferredQuotingTest extends DatabaseTestCase
{
    protected static bool $pgAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('pg', [
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
            Connection::get('pg');
            static::$pgAvailable = true;
        } catch (\Throwable) {
            static::$pgAvailable = false;
        }
    }

    private function requirePostgres(): void
    {
        if (!static::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    /**
     * The row count the database itself reports for a table.
     *
     * @param string $connection The connection name.
     * @param string $table The quoted table name.
     * @return int
     */
    private function truthCount(string $connection, string $table): int
    {
        $rows = DB::query("SELECT COUNT(*) AS c FROM $table", [], $connection);
        return (int)$rows[0]['c'];
    }

    #[Test]
    public function dbTableRunsAgainstTheConnectionItNames(): void
    {
        // DB::table() calls from() before withConnection(), so the FROM clause
        // was quoted for the default grammar and then run on another engine.
        $this->requirePostgres();

        $expected = $this->truthCount('pg', '"user"');
        $rows = iterator_to_array(DB::table('user', 'pg')->getArray());

        $this->assertCount($expected, $rows);
    }

    #[Test]
    public function aJoinBuiltBeforeTheConnectionIsNamedStillRuns(): void
    {
        $this->requirePostgres();

        $expected = (int)DB::query(
            'SELECT COUNT(*) AS c FROM "user" u INNER JOIN "post" p ON p.user_id = u.id',
            [],
            'pg'
        )[0]['c'];

        $rows = iterator_to_array(
            (new ActiveQuery())
                ->from('user u')
                ->join('post p', ['user_id' => '!u.id'])
                ->withConnection('pg')
                ->getArray()
        );

        $this->assertCount($expected, $rows);
    }

    #[Test]
    public function aggregatesOnAnAliasedQueryUseTheTableNameAlone(): void
    {
        // getTable() returned the rendered "`user` `u`", which Aggregate tried
        // to unpick with trim($t, '`"'); that leaves the interior backticks, so
        // the server was asked for a table named "user` `u".
        $truth = DB::query('SELECT COUNT(*) c, SUM(score) s, MIN(score) mn, MAX(score) mx FROM `user`')[0];

        $query = fn(): ActiveQuery => (new ActiveQuery())->from('user u')->withConnection('mysql');

        $this->assertEquals($truth['c'], $query()->count());
        $this->assertEquals($truth['s'], $query()->sum('score'));
        $this->assertEquals($truth['mn'], $query()->min('score'));
        $this->assertEquals($truth['mx'], $query()->max('score'));
    }

    #[Test]
    public function aggregatesOnAnAliasedQueryKeepTheirConditions(): void
    {
        $expected = (int)DB::query("SELECT COUNT(*) c FROM `user` WHERE role = 'admin'")[0]['c'];

        $count = (new ActiveQuery())
            ->from('user u')
            ->where('role', 'admin')
            ->withConnection('mysql')
            ->count();

        $this->assertEquals($expected, $count);
    }

    #[Test]
    public function anAggregateIsQuotedForTheConnectionItRunsOn(): void
    {
        $this->requirePostgres();

        $expected = $this->truthCount('pg', '"user"');

        $count = (new ActiveQuery())->from('user u')->withConnection('pg')->count();

        $this->assertEquals($expected, $count);
    }

    #[Test]
    public function anAggregateOverASubQuerySourceCountsTheSubQuery(): void
    {
        // The aggregate emitted "FROM ``" for a query with no table name,
        // because the sub-query lived somewhere it never looked.
        $expected = (int)DB::query("SELECT COUNT(*) c FROM `user` WHERE role = 'admin'")[0]['c'];

        $sub = (new ActiveQuery())->from('user')->where('role', 'admin');
        $count = (new ActiveQuery())->from(['t' => $sub])->withConnection('mysql')->count();

        $this->assertEquals($expected, $count);
    }

    #[Test]
    public function anAggregateOverASubQuerySourceBindsInStatementOrder(): void
    {
        // The sub-query's value belongs ahead of the outer condition's, since
        // FROM precedes WHERE. Collected the other way round, the statement
        // still runs and quietly returns the wrong total.
        $expected = DB::query(
            'SELECT SUM(score) s FROM (SELECT * FROM `user` WHERE score > ?) t WHERE t.score < ?',
            [50, 90]
        )[0]['s'];

        $sub = (new ActiveQuery())->from('user')->where('score', '>', 50);
        $sum = (new ActiveQuery())
            ->from(['t' => $sub])
            ->where('score', '<', 90)
            ->withConnection('mysql')
            ->sum('score');

        $this->assertEquals($expected, $sum);
    }

    #[Test]
    public function updateAllOnAnAliasedQueryWritesToTheRealTable(): void
    {
        $username = 'dq_' . bin2hex(random_bytes(5));

        (new Insert('user', [
            'username' => $username,
            'email' => $username . '@example.test',
            'password' => 'x',
            'role' => 'member',
            'score' => 5,
            'status_code' => 1,
        ]))->withConnection('mysql')->execute();

        try {
            $updated = (new ActiveQuery())
                ->from('user u')
                ->where('username', $username)
                ->withConnection('mysql')
                ->updateAll(['score' => 42]);

            $this->assertTrue($updated);

            $score = DB::query('SELECT score FROM `user` WHERE username = ?', [$username])[0]['score'];
            $this->assertEquals(42, $score);
        } finally {
            // The fixture is reloaded once per class, not per test, and several
            // integration classes read the sample rows without reloading
            // anything at all. A row left here turns up as an extra user in
            // whichever class runs next, failing an assertion that has nothing
            // to do with this one.
            DB::raw('DELETE FROM `user` WHERE username = ?', [$username], 'mysql');
        }
    }

    #[Test]
    public function anAliasEqualToTheTableNameStillSelects(): void
    {
        $expected = $this->truthCount('mysql', '`user`');

        $rows = iterator_to_array(
            (new ActiveQuery())->from('user u')->alias('user')->withConnection('mysql')->getArray()
        );

        $this->assertCount($expected, $rows);
    }

    #[Test]
    public function explainReturnsThePlanInTheRequestedShape(): void
    {
        // MySQL used to ignore $format and hand back a traditional plan for
        // format: 'json' — twelve columns where the caller expected one.
        $traditional = (new ActiveQuery())->from('user u')->withConnection('mysql')->explain();
        $this->assertArrayHasKey('select_type', $traditional[0]);

        $json = (new ActiveQuery())->from('user u')->withConnection('mysql')->explain(format: 'json');
        $this->assertSame(['EXPLAIN'], array_keys($json[0]));
        $this->assertNotNull(json_decode((string)$json[0]['EXPLAIN']), 'The JSON plan should parse.');
    }

    #[Test]
    public function postgresExplainAnalyzeWithAFormatIsAcceptedByTheServer(): void
    {
        // The documented call. It used to emit "EXPLAIN ANALYZE (FORMAT JSON)",
        // which PostgreSQL rejects with a syntax error at "FORMAT".
        $this->requirePostgres();

        $plan = (new ActiveQuery())
            ->from('user u')
            ->withConnection('pg')
            ->explain(analyze: true, format: 'json');

        $this->assertNotEmpty($plan);
        $this->assertNotNull(json_decode((string)reset($plan[0])), 'The JSON plan should parse.');
    }

    #[Test]
    public function explainCarriesTheStatementsBindValues(): void
    {
        $this->requirePostgres();

        $plan = (new ActiveQuery())
            ->from('user u')
            ->where('role', 'admin')
            ->withConnection('pg')
            ->explain();

        $this->assertNotEmpty($plan);
    }

    #[Test]
    public function anUnsupportedExplainFormatIsRefusedBeforeTheQueryRuns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ActiveQuery())->from('user u')->withConnection('mysql')->explain(format: 'yaml');
    }

    #[Test]
    public function postgresReturningSurvivesExecutionThroughAnotherBuilder(): void
    {
        // execute() cloned its target, so the RETURNING row the driver wrote
        // back landed on the copy and was discarded with it. The caller's
        // builder reported no returned row and no last insert id, for a
        // statement that had run and returned one.
        $this->requirePostgres();

        $username = 'dqpg_' . bin2hex(random_bytes(5));

        $insert = (new Insert('user', [
            'username' => $username,
            'email' => $username . '@example.test',
            'password' => 'x',
            'role' => 'member',
            'score' => 1,
            'status_code' => 1,
        ]))->returning('id')->withConnection('pg');

        $runner = (new ActiveQuery())->from('user')->withConnection('pg');

        try {
            $this->assertTrue($runner->execute($insert));

            $returned = $insert->getReturningResult();
            $this->assertIsArray($returned, 'The RETURNING row should be on the builder that was executed.');
            $this->assertNotEmpty($returned);
            $this->assertNotNull($insert->getLastInsertId());

            $stored = DB::query('SELECT id FROM "user" WHERE username = ?', [$username], 'pg');
            $this->assertEquals($stored[0]['id'], $returned[0]['id']);
        } finally {
            // The PostgreSQL fixture is not reloaded per test class, so a row
            // left behind here changes the counts other classes assert on.
            DB::raw('DELETE FROM "user" WHERE username = ?', [$username], 'pg');
        }
    }
}
