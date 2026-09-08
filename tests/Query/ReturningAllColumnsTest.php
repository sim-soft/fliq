<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;
use Simsoft\DB\Grammar\MySQLGrammar;
use Simsoft\DB\Grammar\PostgresGrammar;
use Simsoft\DB\Grammar\SQLiteGrammar;

/**
 * returning() with no arguments asks for RETURNING *.
 *
 * The columns were held in an array defaulting to [], and buildSQL() decided
 * whether to emit the clause with empty(). A bare returning() stores [] too, so
 * "asked for every column" and "never asked" were the same value and the clause
 * was dropped: the statement ran, returned nothing, and getReturningResult()
 * answered null with no indication why. Every grammar implements
 * returningColumnsSQL([]) as RETURNING * for exactly this case, and no caller
 * could reach it.
 */
class ReturningAllColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('ret_pg', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x']);
        Connection::add('ret_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        Connection::add('ret_mysql', ['driver' => 'mysqli', 'host' => '127.0.0.1', 'database' => 'x']);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /** @return array<string, array{string}> */
    public static function returningConnections(): array
    {
        return ['postgres' => ['ret_pg'], 'sqlite' => ['ret_sqlite']];
    }

    #[Test]
    #[DataProvider('returningConnections')]
    public function updateReturningWithNoArgumentsAsksForEveryColumn(string $connection): void
    {
        $update = new Update('t', ['n' => 1], 'id = 1');
        $update->withConnection($connection)->returning();

        $this->assertStringEndsWith('RETURNING *', $update->getSQL());
    }

    #[Test]
    #[DataProvider('returningConnections')]
    public function deleteReturningWithNoArgumentsAsksForEveryColumn(string $connection): void
    {
        $delete = new Delete('t', 'id = 1');
        $delete->withConnection($connection)->returning();

        $this->assertStringEndsWith('RETURNING *', $delete->getSQL());
    }

    #[Test]
    public function notCallingReturningStillEmitsNoClause(): void
    {
        $update = new Update('t', ['n' => 1], 'id = 1');
        $delete = new Delete('t', 'id = 1');

        $this->assertStringNotContainsString('RETURNING', $update->withConnection('ret_pg')->getSQL());
        $this->assertStringNotContainsString('RETURNING', $delete->withConnection('ret_pg')->getSQL());
    }

    #[Test]
    public function askingForNothingAndNeverAskingAreDistinguishable(): void
    {
        $silent = new Update('t', ['n' => 1], 'id = 1');
        $asked = new Update('t', ['n' => 1], 'id = 1');
        $asked->returning();

        $this->assertFalse($silent->hasReturning(), 'a builder that never asked has no RETURNING');
        $this->assertTrue($asked->hasReturning(), 'returning() with no arguments is still a request');
    }

    #[Test]
    public function theSameHoldsForDelete(): void
    {
        $silent = new Delete('t', 'id = 1');
        $asked = new Delete('t', 'id = 1');
        $asked->returning();

        $this->assertFalse($silent->hasReturning());
        $this->assertTrue($asked->hasReturning());
    }

    #[Test]
    public function namedColumnsAreQuotedAndListed(): void
    {
        $update = new Update('t', ['n' => 1], 'id = 1');
        $update->withConnection('ret_pg')->returning('id', 'name');

        $this->assertStringEndsWith('RETURNING "id", "name"', $update->getSQL());
    }

    #[Test]
    public function returningIsDroppedOnAGrammarThatHasNoSuchClause(): void
    {
        $update = new Update('t', ['n' => 1], 'id = 1');
        $update->withConnection('ret_mysql')->returning();

        $delete = new Delete('t', 'id = 1');
        $delete->withConnection('ret_mysql')->returning();

        $this->assertStringNotContainsString('RETURNING', $update->getSQL());
        $this->assertStringNotContainsString('RETURNING', $delete->getSQL());
    }

    #[Test]
    public function askingForEveryColumnOnMySQLStillReportsTheRequest(): void
    {
        // The clause cannot be emitted, but the caller did ask, and the drivers
        // gate their row capture on this. MySQL's driver never captures either
        // way; what must not happen is the request quietly becoming "no request"
        // depending on which columns were named.
        $update = new Update('t', ['n' => 1], 'id = 1');
        $update->withConnection('ret_mysql')->returning();

        $this->assertTrue($update->hasReturning());
    }

    #[Test]
    public function returningCanBeNarrowedAfterBeingAskedForBroadly(): void
    {
        $update = new Update('t', ['n' => 1], 'id = 1');
        $update->withConnection('ret_pg')->returning();
        $this->assertStringEndsWith('RETURNING *', $update->getSQL());

        $update->returning('id');
        $this->assertStringEndsWith('RETURNING "id"', $update->getSQL(), 'the statement is rebuilt');
    }

    #[Test]
    public function returningCanBeWidenedAfterBeingAskedForNarrowly(): void
    {
        $delete = new Delete('t', 'id = 1');
        $delete->withConnection('ret_pg')->returning('id');
        $this->assertStringEndsWith('RETURNING "id"', $delete->getSQL());

        $delete->returning();
        $this->assertStringEndsWith('RETURNING *', $delete->getSQL(), 'the statement is rebuilt');
    }

    #[Test]
    public function everyGrammarThatSupportsReturningRendersTheEmptyCaseAsStar(): void
    {
        foreach ([new PostgresGrammar(), new SQLiteGrammar()] as $grammar) {
            $this->assertSame(
                'RETURNING *',
                $grammar->returningColumnsSQL([]),
                $grammar::class . ' renders no columns as every column'
            );
        }
    }

    #[Test]
    public function aGrammarWithoutReturningRendersNothingAtAll(): void
    {
        $this->assertSame('', new MySQLGrammar()->returningColumnsSQL([]));
        $this->assertSame('', new MySQLGrammar()->returningColumnsSQL(['id']));
    }

    #[Test]
    public function insertNamesASingleColumnAndCannotAskForEveryOne(): void
    {
        // Insert::returning() takes one required column, so it has no empty case
        // to get wrong — it already used null as its "never asked" marker, which
        // is the shape Update and Delete now follow.
        $insert = new Insert('t', ['n' => 1]);
        $insert->withConnection('ret_pg')->returning('id');

        $this->assertStringEndsWith('RETURNING "id"', $insert->getSQL());
        $this->assertTrue($insert->hasReturning());
        $this->assertFalse(new Insert('t', ['n' => 1])->hasReturning());
    }

    #[Test]
    public function theClauseSurvivesTheOtherStatementModifiers(): void
    {
        $delete = new Delete('t', 'id = 1');
        $delete->withConnection('ret_sqlite')->returning();

        $sql = $delete->getSQL();
        $this->assertStringEndsWith('RETURNING *', $sql);
        $this->assertStringStartsWith('DELETE FROM', $sql);
    }

    #[Test]
    public function anExecutedStatementRunsAndReturnsEveryColumn(): void
    {
        $driver = Connection::get('ret_sqlite');
        $driver->execute(new Raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, n INT)'));
        $driver->execute(new Raw("INSERT INTO t (id, name, n) VALUES (1, 'a', 1), (2, 'b', 2)"));

        $update = new Update('t', ['n' => 9], 'id = 1');
        $update->withConnection('ret_sqlite')->returning();
        $update->execute();

        $this->assertSame([['id' => 1, 'name' => 'a', 'n' => 9]], $update->getReturningResult());

        $delete = new Delete('t', 'id = 2');
        $delete->withConnection('ret_sqlite')->returning();
        $delete->execute();

        $this->assertSame([['id' => 2, 'name' => 'b', 'n' => 2]], $delete->getReturningResult());
    }

    #[Test]
    public function aStatementThatMatchesNoRowReturnsAnEmptyList(): void
    {
        $driver = Connection::get('ret_sqlite');
        $driver->execute(new Raw('CREATE TABLE t (id INTEGER PRIMARY KEY, n INT)'));

        $update = new Update('t', ['n' => 9], 'id = 404');
        $update->withConnection('ret_sqlite')->returning();
        $update->execute();

        // Distinct from null, which is what a statement that never asked answers.
        $this->assertSame([], $update->getReturningResult());
    }
}
