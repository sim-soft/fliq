<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Throwable;

/**
 * The two LIKE families against a real PostgreSQL server.
 *
 * The unit tests show like() and whereLike() build different SQL. This shows
 * what that costs: on PostgreSQL, LIKE and ILIKE genuinely differ rather than
 * deferring to a column collation, so the two families called identically
 * return different rows. That is the reason "Alias for like()" was worth
 * correcting rather than leaving as loose wording.
 *
 * Requires ext-pdo_pgsql and a running PostgreSQL server.
 */
class LikeCaseSensitivityExecutionTest extends TestCase
{
    private static bool $available = false;
    private static string $conn = 'like_case_pg';
    private static string $table = 'like_case_probe';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add(self::$conn, [
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
            Connection::get(self::$conn);
            self::$available = true;
        } catch (Throwable) {
            self::$available = false;

            return;
        }

        // A scratch table, so the shared fixture is neither read nor written.
        self::exec('DROP TABLE IF EXISTS ' . self::$table);
        self::exec('CREATE TABLE ' . self::$table . ' (id SERIAL PRIMARY KEY, username TEXT)');
        self::exec(
            'INSERT INTO ' . self::$table . ' (username) VALUES (?), (?), (?)',
            ['alice', 'ALICE', 'Alice']
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$available) {
            self::exec('DROP TABLE IF EXISTS ' . self::$table);
        }

        Connection::remove(self::$conn);
    }

    /**
     * Execute a statement on the scratch connection.
     *
     * @param string $sql The statement.
     * @param array<int, mixed> $binds Bound values.
     * @return void
     */
    private static function exec(string $sql, array $binds = []): void
    {
        new Raw($sql, $binds)->withConnection(self::$conn)->execute();
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    /**
     * Usernames matched by a query built on the scratch table.
     *
     * @param callable(ActiveQuery): ActiveQuery $build Applies the condition.
     * @return array<int, string> Matching usernames, sorted.
     */
    private function usernamesMatching(callable $build): array
    {
        $query = new ActiveQuery()
            ->from(self::$table)
            ->select('username')
            ->withConnection(self::$conn);

        $rows = iterator_to_array($build($query)->getArray());
        $names = array_map(strval(...), array_column($rows, 'username'));
        sort($names);

        return $names;
    }

    #[Test]
    public function likeMatchesOnlyTheExactCase(): void
    {
        $this->assertSame(
            ['Alice'],
            $this->usernamesMatching(static fn(ActiveQuery $q) => $q->like('username', 'Alice'))
        );
    }

    #[Test]
    public function whereLikeMatchesEveryCase(): void
    {
        // Same argument, same column, three rows instead of one.
        $this->assertSame(
            ['ALICE', 'Alice', 'alice'],
            $this->usernamesMatching(static fn(ActiveQuery $q) => $q->whereLike('username', 'Alice'))
        );
    }

    #[Test]
    public function theTwoFamiliesReturnDifferentRowsForTheSameCall(): void
    {
        $viaBase = $this->usernamesMatching(static fn(ActiveQuery $q) => $q->like('username', 'Alice'));
        $viaWrapper = $this->usernamesMatching(static fn(ActiveQuery $q) => $q->whereLike('username', 'Alice'));

        $this->assertNotSame($viaBase, $viaWrapper);
    }

    #[Test]
    public function statingSensitivityMakesTheFamiliesAgree(): void
    {
        $viaBase = $this->usernamesMatching(
            static fn(ActiveQuery $q) => $q->like('username', 'Alice', caseSensitive: false)
        );
        $viaWrapper = $this->usernamesMatching(
            static fn(ActiveQuery $q) => $q->whereLike('username', 'Alice', caseSensitive: false)
        );

        $this->assertSame($viaBase, $viaWrapper);
        $this->assertSame(['ALICE', 'Alice', 'alice'], $viaBase);
    }

    #[Test]
    public function ilikeMatchesRegardlessOfCase(): void
    {
        $this->assertSame(
            ['ALICE', 'Alice', 'alice'],
            $this->usernamesMatching(
                static fn(ActiveQuery $q) => $q->like('username', 'alice', caseSensitive: false)
            )
        );
    }

    #[Test]
    public function notIlikeExcludesEveryCase(): void
    {
        $this->assertSame(
            [],
            $this->usernamesMatching(
                static fn(ActiveQuery $q) => $q->notLike('username', 'alice', caseSensitive: false)
            )
        );
    }

    #[Test]
    public function notLikeExcludesOnlyTheExactCase(): void
    {
        $this->assertSame(
            ['ALICE', 'alice'],
            $this->usernamesMatching(static fn(ActiveQuery $q) => $q->notLike('username', 'Alice'))
        );
    }

    #[Test]
    public function orNotLikeExecutesAndWidensTheResult(): void
    {
        // orNotLike() had no caller in the suite and never ran against a
        // server. OR'd against a condition matching one row, the negation
        // pulls in everything it does not exclude.
        $this->assertSame(
            ['ALICE', 'Alice', 'alice'],
            $this->usernamesMatching(
                static fn(ActiveQuery $q) => $q->where('username', 'alice')
                    ->orNotLike('username', 'nobody')
            )
        );
    }

    #[Test]
    public function orNotLikeNegatesWhatItMatches(): void
    {
        $this->assertSame(
            ['ALICE', 'alice'],
            $this->usernamesMatching(static fn(ActiveQuery $q) => $q->orNotLike('username', 'Alice'))
        );
    }

    #[Test]
    public function ilikeAcrossAnArrayOfPatternsExecutes(): void
    {
        // matchAll defaults to true, so both patterns must match.
        $this->assertSame(
            ['ALICE', 'Alice', 'alice'],
            $this->usernamesMatching(
                static fn(ActiveQuery $q) => $q->like(
                    'username',
                    ['ali%', '%ice'],
                    caseSensitive: false
                )
            )
        );
    }

    #[Test]
    public function anIlikePatternIsBoundNotInterpolated(): void
    {
        // A pattern carrying a quote must reach the server as a value.
        $this->assertSame(
            [],
            $this->usernamesMatching(
                static fn(ActiveQuery $q) => $q->like(
                    'username',
                    "' OR 1=1 --",
                    caseSensitive: false
                )
            )
        );
    }
}
