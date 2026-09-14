<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;
use Simsoft\DB\Interfaces\ReturnsRows;

/**
 * Which write builders can be asked what they wrote.
 *
 * RETURNING is the only statement-scoped answer on PostgreSQL and SQLite, whose
 * lastInsertId() is session-scoped and will happily report a sequence number
 * that no row carries. Insert, Update and Delete each declared the contract
 * separately and the drivers dispatched on those three class names; Upsert had
 * no way to declare it, so it was the one builder that could never be asked —
 * and every upsert fell through to the session-scoped id.
 *
 * These hold the contract as a set rather than one class at a time, so a
 * builder added later is either in it or visibly not.
 */
class ReturnsRowsContractTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();

        Connection::add('mysql', [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'test',
            'username' => 'root', 'password' => '',
        ]);
        Connection::add('pgsql', [
            'driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test',
            'username' => 'postgres', 'password' => '',
        ]);
        Connection::add('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * Every write builder that runs a statement able to write a row.
     *
     * @return array<string, array{0: ReturnsRows}>
     */
    public static function writeBuilders(): array
    {
        return [
            'insert' => [new Insert('t', ['a' => 1])],
            'update' => [new Update('t', ['a' => 1])],
            'delete' => [new Delete('t')],
            'upsert' => [new Upsert('t', ['a' => 1], ['a'], ['a'])],
        ];
    }

    #[Test]
    #[DataProvider('writeBuilders')]
    public function everyWriteBuilderDeclaresTheReturningContract(ReturnsRows $builder): void
    {
        // The point of the interface: the drivers and Execute::getLastInsertId()
        // used to name Insert, Update and Delete one at a time, which is the set
        // that happened to declare the methods rather than the set that can
        // carry the clause. Upsert was outside it and was silently skipped.
        $this->assertInstanceOf(ReturnsRows::class, $builder);
    }

    #[Test]
    #[DataProvider('writeBuilders')]
    public function aBuilderNeverAskedHasNoResultAndSaysSo(ReturnsRows $builder): void
    {
        $this->assertFalse($builder->hasReturning());

        // Null, not []: the first says the statement was never asked, the
        // second that it ran and named no row. getLastInsertId() reads the
        // difference to decide whether it may fall through to the driver.
        $this->assertNull($builder->getReturningResult());
    }

    #[Test]
    #[DataProvider('writeBuilders')]
    public function capturedRowsAreReadableBack(ReturnsRows $builder): void
    {
        $builder->setReturningResult([['id' => 7]]);

        $this->assertSame([['id' => 7]], $builder->getReturningResult());

        // True once rows are in hand even though returning() was never called,
        // so a caller asking whether it is worth reading is not told no while
        // the result sits there.
        $this->assertTrue($builder->hasReturning());
    }

    #[Test]
    #[DataProvider('writeBuilders')]
    public function anEmptyCaptureIsStillACapture(ReturnsRows $builder): void
    {
        // What a statement skipped by ON CONFLICT DO NOTHING leaves behind.
        $builder->setReturningResult([]);

        $this->assertSame([], $builder->getReturningResult());
        $this->assertTrue($builder->hasReturning());
    }

    #[Test]
    public function upsertEmitsTheClauseAfterTheConflictAction(): void
    {
        // RETURNING has to follow the whole conflict action, not the VALUES
        // list. Placed anywhere earlier the statement does not parse.
        $upsert = new Upsert('up', ['a' => 'x', 'v' => 'NEW'], ['v'], ['a']);
        $upsert->withConnection('pgsql')->returning('id');

        $this->assertSame(
            'INSERT INTO "up" ("a", "v") VALUES (?, ?) ON CONFLICT ("a") DO UPDATE SET "v" = EXCLUDED."v"'
            . ' RETURNING "id"',
            $upsert->getSQL()
        );
    }

    #[Test]
    public function upsertReturningWithNoColumnsAsksForEveryColumn(): void
    {
        $upsert = new Upsert('up', ['a' => 'x'], ['a'], ['a']);
        $upsert->withConnection('pgsql')->returning();

        $this->assertStringEndsWith('RETURNING *', $upsert->getSQL());

        // The request is recorded as an empty array, which an emptiness test
        // would read as no request at all. Update and Delete test against null
        // for the same reason.
        $this->assertTrue($upsert->hasReturning());
    }

    #[Test]
    public function upsertOnSqliteEmitsTheClauseToo(): void
    {
        // SQLite has had RETURNING since 3.35 and the grammar says so.
        $upsert = new Upsert('up', ['a' => 'x'], ['a'], ['a']);
        $upsert->withConnection('sqlite')->returning('id');

        $this->assertStringEndsWith('RETURNING "id"', $upsert->getSQL());
    }

    #[Test]
    public function upsertOnMysqlOmitsAClauseTheEngineCannotParse(): void
    {
        // MySQL has no RETURNING, and does not need one: LAST_INSERT_ID() is
        // per-statement there and already reports nothing when nothing was
        // written. Asking is accepted and produces a statement MySQL accepts.
        $upsert = new Upsert('up', ['a' => 'x'], ['a'], ['a']);
        $upsert->withConnection('mysql')->returning('id');

        $this->assertStringNotContainsStringIgnoringCase('RETURNING', $upsert->getSQL());
    }

    #[Test]
    public function askingForTheClauseRebuildsAStatementAlreadyRead(): void
    {
        // returning() has to invalidate the cached SQL. Without that, a builder
        // whose SQL had been read once — by dump(), or by an earlier execute()
        // on a reused builder — kept handing back the clause-less statement.
        $upsert = new Upsert('up', ['a' => 'x'], ['a'], ['a']);
        $upsert->withConnection('pgsql');

        $before = $upsert->getSQL();
        $this->assertStringNotContainsString('RETURNING', $before);

        $upsert->returning('id');

        $this->assertStringEndsWith('RETURNING "id"', $upsert->getSQL());
    }
}
