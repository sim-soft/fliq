<?php

namespace Query;

use Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Select;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;

/**
 * Reading a builder's SQL must not freeze it.
 *
 * getSQL() memoises, and nothing used to invalidate that cache, so the first
 * read fixed the statement for good and every later mutation was dropped in
 * silence. Reading early is not unusual — dump(), dd(), explain() and string
 * interpolation all do it, and DB::sqlOnly() hands back a builder for the
 * express purpose of being inspected — so this covers the mutators that a
 * caller can reach after such a read.
 */
class BuilderRebuildTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
        Connection::add('pg', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'sample_db',
            'username' => 'postgres',
            'password' => 'postgres',
            'schema' => 'public',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Connection::reset();
    }

    /**
     * Each case reads the SQL, mutates, and names what must then appear.
     *
     * @return array<string, array{callable(): object, string}>
     */
    public static function mutationsAfterARead(): array
    {
        return [
            'Select::distinct' => [
                static fn(): Select => (new Select('user', ['username']))->withConnection('mysql'),
                'DISTINCT',
            ],
            'Insert::ignore' => [
                static fn(): Insert => (new Insert('user', ['username' => 'a']))->withConnection('mysql'),
                'IGNORE',
            ],
            'Update::ignore' => [
                static fn(): Update => (new Update('user', ['username' => 'a'], 'id = 1'))->withConnection('mysql'),
                'IGNORE',
            ],
            'Update::lowPriority' => [
                static fn(): Update => (new Update('user', ['username' => 'a'], 'id = 1'))->withConnection('mysql'),
                'LOW_PRIORITY',
            ],
            'Delete::quick' => [
                static fn(): Delete => (new Delete('user', 'id = 1'))->withConnection('mysql'),
                'QUICK',
            ],
            'Delete::lowPriority' => [
                static fn(): Delete => (new Delete('user', 'id = 1'))->withConnection('mysql'),
                'LOW_PRIORITY',
            ],
        ];
    }

    #[Test]
    #[DataProvider('mutationsAfterARead')]
    public function aMutationAfterAReadReachesTheStatement(callable $make, string $expected): void
    {
        $builder = $make();

        // The read that used to freeze it.
        $builder->getSQL();

        match ($expected) {
            'DISTINCT' => $builder->distinct(),
            'IGNORE' => $builder->ignore(),
            'LOW_PRIORITY' => $builder->lowPriority(),
            'QUICK' => $builder->quick(),
            default => self::fail("no mutation defined for '$expected'"),
        };

        $this->assertStringContainsString($expected, $builder->getSQL());
    }

    #[Test]
    public function aConditionSetAfterAReadReachesTheStatement(): void
    {
        $select = new Select('user', ['id']);
        $select->withConnection('mysql');

        $this->assertStringNotContainsString('WHERE', $select->getSQL());

        $select->condition('id = 5');

        $this->assertStringContainsString('WHERE id = 5', $select->getSQL());
    }

    #[Test]
    public function returningAddedAfterAReadReachesTheStatement(): void
    {
        // On an engine that has RETURNING at all — MySQL omitting it is correct,
        // not staleness, so the check has to be made where it is supported.
        $update = new Update('user', ['status_code' => 1], 'id = 1');
        $update->withConnection('pg');

        $update->getSQL();
        $update->returning('id');

        $this->assertStringContainsString('RETURNING', $update->getSQL());
    }

    #[Test]
    public function aConnectionChangeRequotesTheStatement(): void
    {
        $update = new Update('user', ['status_code' => 1], 'id = 1');

        $update->withConnection('mysql');
        $this->assertStringContainsString('`user`', $update->getSQL());

        // The statement is quoted for one grammar; it is no more valid across a
        // connection change than the cached grammar that withConnection() has
        // always discarded.
        $update->withConnection('pg');
        $this->assertStringContainsString('"user"', $update->getSQL());
        $this->assertStringNotContainsString('`', $update->getSQL());
    }

    #[Test]
    public function rebuildingDoesNotDoubleTheBinds(): void
    {
        // The cache was load-bearing: buildSQL() appends a bind for every
        // placeholder it emits, so running it twice over the same binds used to
        // produce [3, 3] for a statement with one placeholder. Clearing before
        // each build is what makes dropping the cache safe.
        $update = new Update('user', ['status_code' => 3], 'id = 1');
        $update->withConnection('mysql');

        $update->getSQL();
        $update->ignore();
        $update->getSQL();
        $update->lowPriority();

        $this->assertSame([3], $update->getBinds());
    }

    #[Test]
    public function readingTheSqlRepeatedlyDoesNotChangeTheBinds(): void
    {
        $update = new Update('user', ['status_code' => 3], 'id = 1');
        $update->withConnection('mysql');

        $update->getSQL();
        $update->getSQL();
        $update->getSQL();

        $this->assertSame([3], $update->getBinds());
    }

    #[Test]
    public function settingTheConditionTwiceDiscardsTheFirstBinds(): void
    {
        // condition() used to absorb the source's binds as it rendered, and a
        // second call replaced the SQL but kept the binds the first had
        // contributed. The statement then held one placeholder and two values,
        // and the driver refused it.
        $select = new Select('user', ['id']);
        $select->withConnection('mysql');

        $select->condition(new Raw('id = ?', [111]));
        $select->condition(new Raw('id = ?', [222]));

        $this->assertSame([222], $select->getBinds());
        $this->assertSame(1, substr_count($select->getSQL(), '?'));
    }

    #[Test]
    public function settingAnActiveQueryConditionTwiceDiscardsTheFirstBinds(): void
    {
        $select = new Select('user', ['id']);
        $select->withConnection('mysql');

        $select->condition(User::find()->where('id', 111));
        $select->condition(User::find()->where('id', 222));

        $this->assertSame([222], $select->getBinds());
        $this->assertSame(1, substr_count($select->getSQL(), '?'));
    }

    #[Test]
    public function anEmptyStringConditionDoesNotDisplaceOneAlreadySet(): void
    {
        // The constructor's default is '', and it must mean "none given"
        // rather than "clear what is there".
        $select = new Select('user', ['id'], 'id = 5');
        $select->withConnection('mysql');
        $select->condition('');

        $this->assertStringContainsString('WHERE id = 5', $select->getSQL());
    }

    #[Test]
    public function bindsAreAvailableBeforeTheSqlIsRead(): void
    {
        // The values are produced by the build, so reading them first used to
        // answer null for a statement that plainly had them. Both reads now
        // agree, in either order, on every builder.
        $insert = new Insert('user', ['username' => 'a', 'email' => 'b']);
        $insert->withConnection('mysql');

        $this->assertSame(['a', 'b'], $insert->getBinds());
        $this->assertSame(['a', 'b'], $insert->getBinds());
        $insert->getSQL();
        $this->assertSame(['a', 'b'], $insert->getBinds());
    }
}
