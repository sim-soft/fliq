<?php

namespace Integration;

use DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

/**
 * The debug renderings, checked against the statements they stand for.
 *
 * getFullSQL(), dump() and dd() are only worth anything if what they show means
 * what was executed, so these tests run both forms and compare the rows the
 * server returns — never the SQL string, which is the thing under suspicion.
 * The interesting values render one way and compare another, which only shows
 * against rows that actually hold them.
 */
class ReadableSQLTest extends DatabaseTestCase
{
    protected static bool $pgAvailable = false;

    /** @var array<int, string> Usernames seeded for these tests. */
    private const AWKWARD = ['007', '7', '1e3', 'a\\b', 'back\\', "o'brien"];

    /** @var string The marker used to find and remove the seeded rows again. */
    private const MARKER = 'readable-sql-test.invalid';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Connection::add('lite', ['driver' => 'sqlite', 'database' => ':memory:']);

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed('mysql', '`user`');
    }

    protected function tearDown(): void
    {
        $this->unseed('mysql', '`user`');
    }

    /**
     * Insert the awkward usernames into a user table.
     *
     * @param string $connection The connection name.
     * @param string $table The quoted table name for that engine.
     * @return void
     */
    private function seed(string $connection, string $table): void
    {
        foreach (self::AWKWARD as $index => $username) {
            DB::raw(
                "INSERT INTO $table (username, email, password, role, score, status_code)"
                . ' VALUES (?, ?, ?, ?, ?, ?)',
                [$username, "readable$index@" . self::MARKER, 'x', 'member', 1, 1],
                $connection
            );
        }
    }

    /**
     * Remove the rows seeded by {@see self::seed()}.
     *
     * The PostgreSQL fixture is loaded once and not per test class, so leaving
     * these behind would change the counts other classes assert against.
     *
     * @param string $connection The connection name.
     * @param string $table The quoted table name for that engine.
     * @return void
     */
    private function unseed(string $connection, string $table): void
    {
        DB::raw("DELETE FROM $table WHERE email LIKE ?", ['%@' . self::MARKER], $connection);
    }

    private function requirePostgres(): void
    {
        if (!static::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    /**
     * Assert the rendered statement selects exactly what the bound one selects.
     *
     * @param ActiveQuery $query The query to check, already bound to a connection.
     * @param string $connection The connection to run both forms against.
     * @return void
     */
    private function assertRenderingAgrees(ActiveQuery $query, string $connection): void
    {
        $bound = DB::query($query->getSQL(), $query->getBinds() ?? [], $connection);
        $rendered = DB::query($query->getFullSQL(), [], $connection);

        $this->assertSame(
            count($bound),
            count($rendered),
            'The rendered statement should find what the bound one finds: ' . $query->getFullSQL()
        );
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function awkwardValueProvider(): array
    {
        return [
            // Numeric-looking strings went through bare on is_numeric(), so they
            // compared as numbers where the bound value compared as text:
            // '007' = 7 holds and '007' = '7' does not.
            'a leading zero' => ['007', 1],
            'scientific notation' => ['1e3', 1],
            // Only the apostrophe was escaped, so on MySQL — where a backslash
            // is an escape inside a literal — these read as something else.
            'an embedded backslash' => ['a\\b', 1],
            'a trailing backslash' => ['back\\', 1],
            'an apostrophe' => ["o'brien", 1],
            // A value that matches nothing must still render to a statement that
            // parses and also matches nothing.
            'absent' => ['no-such-user', 0],
        ];
    }

    #[Test]
    #[DataProvider('awkwardValueProvider')]
    public function theRenderedStatementFindsWhatTheBoundOneFinds(string $username, int $expected): void
    {
        $query = (new ActiveQuery())->from('user')->where('username', $username)->withConnection('mysql');

        $this->assertCount($expected, DB::query($query->getFullSQL(), [], 'mysql'));
        $this->assertRenderingAgrees($query, 'mysql');
    }

    #[Test]
    public function aRenderedTrailingBackslashDoesNotSwallowTheRestOfTheStatement(): void
    {
        // 'back\' never closed the literal, so the rest of the statement was
        // read as part of the value and MySQL reported a syntax error — for a
        // query that had executed without complaint.
        $query = (new ActiveQuery())
            ->from('user')
            ->where('username', 'back\\')
            ->where('score', '>', 0)
            ->withConnection('mysql');

        $rows = DB::query($query->getFullSQL(), [], 'mysql');

        $this->assertCount(1, $rows);
        $this->assertSame('back\\', $rows[0]['username']);
    }

    #[Test]
    public function aNullBindRendersAsTheKeyword(): void
    {
        // Rendered as the string '?', MySQL rejected it against a timestamp
        // column: "Incorrect TIMESTAMP value: '?'".
        $this->assertRenderingAgrees(
            (new ActiveQuery())->from('user')->whereRaw('(deleted_at <=> ?)', [null])->withConnection('mysql'),
            'mysql'
        );
    }

    #[Test]
    public function aBoolBindRendersTheWayPdoSendsIt(): void
    {
        // TRUE and FALSE read better but compare differently: PDO sends
        // (string)false, which is '', and against a varchar column `= FALSE`
        // matches every row where a bound false matches none.
        $this->assertRenderingAgrees(
            (new ActiveQuery())->from('user')->whereRaw('(username = ?)', [false])->withConnection('mysql'),
            'mysql'
        );
        $this->assertRenderingAgrees(
            (new ActiveQuery())->from('user')->whereRaw('(username = ?)', [true])->withConnection('mysql'),
            'mysql'
        );
    }

    #[Test]
    public function anIntBindComparesTheWayTheBoundOneDoes(): void
    {
        // Against a text column an int and its string form differ, and PDO
        // sends the int as a string. Rendered bare, this matched both '007'
        // and '7'; bound, it matches only '7'.
        $query = (new ActiveQuery())->from('user')->where('username', 7)->withConnection('mysql');

        $this->assertCount(1, DB::query($query->getFullSQL(), [], 'mysql'));
        $this->assertRenderingAgrees($query, 'mysql');
    }

    #[Test]
    public function postgresKeepsABackslashItDoesNotTreatAsAnEscape(): void
    {
        // standard_conforming_strings is on here, so doubling the backslash
        // would show a value with two where the bound one had one — the same
        // error as MySQL's, in the other direction.
        $this->requirePostgres();

        try {
            $this->seed('pg', '"user"');

            $query = (new ActiveQuery())->from('user')->where('username', 'a\\b')->withConnection('pg');

            $this->assertStringContainsString("'a\\b'", $query->getFullSQL());
            $this->assertCount(1, DB::query($query->getFullSQL(), [], 'pg'));
            $this->assertRenderingAgrees($query, 'pg');
        } finally {
            $this->unseed('pg', '"user"');
        }
    }

    #[Test]
    public function postgresDoublesAnApostrophe(): void
    {
        $this->requirePostgres();

        try {
            $this->seed('pg', '"user"');

            $query = (new ActiveQuery())->from('user')->where('username', "o'brien")->withConnection('pg');

            $this->assertCount(1, DB::query($query->getFullSQL(), [], 'pg'));
            $this->assertRenderingAgrees($query, 'pg');
        } finally {
            $this->unseed('pg', '"user"');
        }
    }

    #[Test]
    public function sqliteRendersTypedValuesTheWayItsDriverBindsThem(): void
    {
        // SQLiteDriver::bindTypedValues() binds an int as PDO::PARAM_INT rather
        // than letting PDO send it as a string, and SQLite does not compare 1
        // equal to '1'. The rendering has to follow the driver: quoting the int
        // here would show a comparison SQLite never made.
        DB::raw('CREATE TABLE IF NOT EXISTS readable (v TEXT, n INTEGER)', [], 'lite');
        DB::raw('DELETE FROM readable', [], 'lite');
        DB::raw("INSERT INTO readable VALUES ('007', 7), ('7', 70)", [], 'lite');

        $query = (new ActiveQuery())->from('readable')->where('n', 7)->withConnection('lite');

        $bound = DB::query($query->getSQL(), $query->getBinds() ?? [], 'lite');
        $rendered = DB::query($query->getFullSQL(), [], 'lite');

        $this->assertCount(1, $bound);
        $this->assertSame(count($bound), count($rendered), $query->getFullSQL());
    }

    #[Test]
    public function rawDumpsTheSameInterpolatedFormAsEveryOtherBuilder(): void
    {
        // Raw has no Qualifier, so dump() fell through to a two-line
        // "SQL\nBinds: [...]" form — not the output the documentation shows, and
        // not something that can be pasted into a client, though that is the
        // whole reason to dump it.
        ob_start();
        (new Raw('SELECT * FROM `user` WHERE username = ?', ['back\\']))
            ->withConnection('mysql')
            ->dump();
        $dumped = trim((string)ob_get_clean());

        $this->assertStringNotContainsString('Binds:', $dumped);

        $rows = DB::query($dumped, [], 'mysql');
        $this->assertCount(1, $rows);
        $this->assertSame('back\\', $rows[0]['username']);
    }

    #[Test]
    public function dumpOnAStatementWithNoBindsPrintsItAlone(): void
    {
        ob_start();
        (new Raw('SELECT 1'))->withConnection('mysql')->dump();

        $this->assertSame('SELECT 1', trim((string)ob_get_clean()));
    }

    #[Test]
    public function aValueThatCannotBeBoundIsNamedInsteadOfRaisingFromTheDebugHelper(): void
    {
        // Casting one of these raised "Object of class DateTime could not be
        // converted to string" — an Error thrown out of dump(), from inside the
        // debugging the caller was doing to find the original problem.
        ob_start();
        (new Raw('SELECT ?, ?', [new DateTime(), ['x']]))->withConnection('mysql')->dump();

        $this->assertSame("SELECT '[DateTime]', '[array]'", trim((string)ob_get_clean()));
    }
}
