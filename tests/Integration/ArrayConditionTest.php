<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Throwable;

/**
 * The PostgreSQL array conditions and their aliases, run against a server.
 *
 * `arrayContains` and `arrayOverlaps` build the `@>` and `&&` operators with a
 * cast to the declared element type. Their where-prefixed and or-prefixed
 * aliases are one-line forwarders, and the element type is one of the
 * arguments they pass along — so an alias that dropped or reordered it would
 * produce a query that either matches nothing or fails the cast, neither of
 * which is visible from reading the alias.
 *
 * Requires ext-pdo_pgsql and a running PostgreSQL server.
 */
class ArrayConditionTest extends TestCase
{
    /** @var bool Whether a PostgreSQL server answered at setup. */
    private static bool $available = false;

    /** @var string Scratch table, created and dropped by this class alone. */
    private const TABLE = 'array_condition_fixture';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('arrpg', [
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
            Connection::get('arrpg');
            self::$available = true;
        } catch (Throwable) {
            self::$available = false;

            return;
        }

        DB::raw('DROP TABLE IF EXISTS ' . self::TABLE, [], 'arrpg');
        DB::raw('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, tags TEXT[], nums INT[])', [], 'arrpg');

        foreach ([
            [1, '{a,b}', '{1,2}'],
            [2, '{b,c}', '{2,3}'],
            [3, '{c}', '{3}'],
            [4, '{}', '{}'],
        ] as [$id, $tags, $nums]) {
            DB::raw(
                'INSERT INTO ' . self::TABLE . ' (id, tags, nums) VALUES (?, ?, ?)',
                [$id, $tags, $nums],
                'arrpg'
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        // The PostgreSQL fixture is not reloaded between classes, so the
        // scratch table must be removed here or it outlives the run.
        if (self::$available) {
            DB::raw('DROP TABLE IF EXISTS ' . self::TABLE, [], 'arrpg');
        }

        try {
            Connection::remove('arrpg');
        } catch (Throwable) {
            // Already gone.
        }
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from(self::TABLE)->on('arrpg')->select('id');
    }

    /**
     * The ids a query returns, ascending.
     *
     * @return array<int, int>
     */
    private function ids(ActiveQuery $query): array
    {
        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            iterator_to_array($query->getArray())
        );
        sort($ids);

        return $ids;
    }

    #[Test]
    public function arrayContainsFindsRowsHoldingTheValue(): void
    {
        $this->assertSame([2, 3], $this->ids($this->query()->arrayContains('tags', 'c')));
    }

    #[Test]
    public function whereArrayContainsMatchesItsTarget(): void
    {
        $this->assertSame(
            $this->ids($this->query()->arrayContains('tags', 'c')),
            $this->ids($this->query()->whereArrayContains('tags', 'c'))
        );
    }

    #[Test]
    public function orWhereArrayContainsWidensTheResult(): void
    {
        $this->assertSame(
            [1, 2, 3],
            $this->ids($this->query()->where('id', '=', 1)->orWhereArrayContains('tags', 'c'))
        );
    }

    #[Test]
    public function orArrayContainsMatchesItsAlias(): void
    {
        $this->assertSame(
            $this->ids($this->query()->where('id', '=', 1)->orArrayContains('tags', 'c')),
            $this->ids($this->query()->where('id', '=', 1)->orWhereArrayContains('tags', 'c'))
        );
    }

    #[Test]
    public function theElementTypeSurvivesTheAliases(): void
    {
        // An int[] column needs ARRAY[?]::int[]; a dropped or defaulted type
        // makes PostgreSQL refuse to compare text[] against int[].
        $this->assertSame([2, 3], $this->ids($this->query()->whereArrayContains('nums', 3, 'int')));
        $this->assertSame(
            [1, 2, 3],
            $this->ids($this->query()->where('id', '=', 1)->orWhereArrayContains('nums', 3, 'int'))
        );
        $this->assertSame([1], $this->ids($this->query()->whereArrayOverlaps('nums', [1], 'int')));
    }

    #[Test]
    public function arrayOverlapsFindsRowsSharingAnyValue(): void
    {
        $this->assertSame([1, 2, 3], $this->ids($this->query()->arrayOverlaps('tags', ['a', 'c'])));
        $this->assertSame([1], $this->ids($this->query()->arrayOverlaps('tags', ['a'])));
    }

    #[Test]
    public function whereArrayOverlapsMatchesItsTarget(): void
    {
        $this->assertSame(
            $this->ids($this->query()->arrayOverlaps('tags', ['a', 'c'])),
            $this->ids($this->query()->whereArrayOverlaps('tags', ['a', 'c']))
        );
    }

    #[Test]
    public function orWhereArrayOverlapsWidensTheResult(): void
    {
        $this->assertSame(
            [1, 3],
            $this->ids($this->query()->where('id', '=', 3)->orWhereArrayOverlaps('tags', ['a']))
        );
    }

    #[Test]
    public function orArrayOverlapsMatchesItsAlias(): void
    {
        $this->assertSame(
            $this->ids($this->query()->where('id', '=', 3)->orArrayOverlaps('tags', ['a'])),
            $this->ids($this->query()->where('id', '=', 3)->orWhereArrayOverlaps('tags', ['a']))
        );
    }

    #[Test]
    public function overlappingNothingMatchesNothing(): void
    {
        $this->assertSame([], $this->ids($this->query()->whereArrayOverlaps('tags', [])));
    }
}
