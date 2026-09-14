<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;

/**
 * The statements the write builders emit.
 *
 * INSERT, UPDATE, DELETE and UPSERT quoted their table and column names without
 * validating them, and emitted MySQL's statement modifiers to every engine.
 * Neither is visible from a SELECT, which is where the identifier checks and the
 * grammar dispatch were already in place.
 */
class WriteBuilderShapeTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
        Connection::add('pg', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'postgres',
            'password' => 'postgres',
        ]);
        Connection::add('lite', ['driver' => 'sqlite', 'database' => ':memory:']);
        Connection::setDefault('mysql');
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /** @return array<string, array{0: string}> */
    public static function hostileIdentifierProvider(): array
    {
        return [
            // The grammars double the quote character and leave everything
            // around it alone, so this arrived as usable SQL rather than as a
            // name the server would reject.
            'a backtick and a comment' => ['user` -- '],
            'a backtick opening a second value list' => ['user` (id) VALUES (1) -- '],
            'a double quote and a comment' => ['user" -- '],
            'a comma splitting one name into two' => ['username` , `role'],
            'a space' => ['my table'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function insertRefusesATableNameItCannotSafelyEmit(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Insert($table, ['username' => 'a']))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function insertRefusesAColumnNameItCannotSafelyEmit(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Insert('user', [$column => 'a']))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function bulkInsertRefusesAColumnNameItCannotSafelyEmit(string $column): void
    {
        // The bulk path built its column list separately and so had to be
        // covered separately.
        $this->expectException(InvalidArgumentException::class);

        (new Insert('user', [[$column => 'a']]))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function updateRefusesATableNameItCannotSafelyEmit(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Update($table, ['score' => 1], 'id = 1'))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function updateRefusesAColumnNameItCannotSafelyEmit(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Update('user', [$column => 1], 'id = 1'))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function deleteRefusesATableNameItCannotSafelyEmit(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Delete($table, 'id = 1'))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function upsertRefusesATableNameItCannotSafelyEmit(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Upsert($table, ['a' => 1]))->withConnection('mysql')->getSQL();
    }

    #[Test]
    #[DataProvider('hostileIdentifierProvider')]
    public function upsertRefusesAColumnNameItCannotSafelyEmit(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Upsert('user', [$column => 1]))->withConnection('mysql')->getSQL();
    }

    #[Test]
    public function updateRefusesACounterColumnItCannotSafelyEmit(): void
    {
        // setCounter() writes the column name twice — once on each side of the
        // assignment — so it is worth its own check.
        $this->expectException(InvalidArgumentException::class);

        (new Update('user', [], 'id = 1'))
            ->withConnection('mysql')
            ->setCounter('score` = 0, `role', 1);
    }

    #[Test]
    public function aSchemaQualifiedTableIsQuotedAsTwoNames(): void
    {
        // The write builders quoted the whole string as one identifier, naming
        // a table called "public.user" that no server has. SELECT had handled
        // this since getQualifiedTable() was written.
        $this->assertSame(
            'INSERT INTO "public"."user" ("username") VALUES (?)',
            (new Insert('public.user', ['username' => 'a']))->withConnection('pg')->getSQL()
        );

        $this->assertSame(
            'UPDATE "public"."user" SET "score" = ? WHERE id = 1',
            (new Update('public.user', ['score' => 1], 'id = 1'))->withConnection('pg')->getSQL()
        );

        $this->assertSame(
            'DELETE FROM "public"."user" WHERE id = 1',
            (new Delete('public.user', 'id = 1'))->withConnection('pg')->getSQL()
        );
    }

    /** @return array<string, array{0: string}> */
    public static function modifierlessConnectionProvider(): array
    {
        return ['postgres' => ['pg'], 'sqlite' => ['lite']];
    }

    #[Test]
    #[DataProvider('modifierlessConnectionProvider')]
    public function updateOmitsMysqlModifiersOnEnginesThatHaveNoneField(string $connection): void
    {
        $sql = (new Update('user', ['score' => 1], 'id = 1'))
            ->lowPriority()
            ->ignore()
            ->withConnection($connection)
            ->getSQL();

        $this->assertStringNotContainsString('LOW_PRIORITY', $sql);
        $this->assertStringNotContainsString('IGNORE', $sql);
    }

    #[Test]
    #[DataProvider('modifierlessConnectionProvider')]
    public function deleteOmitsMysqlModifiersOnEnginesThatHaveNone(string $connection): void
    {
        $sql = (new Delete('user', 'id = 1'))
            ->lowPriority()
            ->quick()
            ->ignore()
            ->withConnection($connection)
            ->getSQL();

        $this->assertStringNotContainsString('LOW_PRIORITY', $sql);
        $this->assertStringNotContainsString('QUICK', $sql);
        $this->assertStringNotContainsString('IGNORE', $sql);
    }

    #[Test]
    public function mysqlKeepsItsOwnModifiers(): void
    {
        // Dropping them everywhere would have been the wrong fix.
        $this->assertSame(
            'UPDATE LOW_PRIORITY IGNORE `user` SET `score` = ? WHERE id = 1',
            (new Update('user', ['score' => 1], 'id = 1'))
                ->lowPriority()->ignore()->withConnection('mysql')->getSQL()
        );

        $this->assertSame(
            'DELETE LOW_PRIORITY QUICK IGNORE FROM `user` WHERE id = 1',
            (new Delete('user', 'id = 1'))
                ->lowPriority()->quick()->ignore()->withConnection('mysql')->getSQL()
        );
    }

    #[Test]
    public function aModifierFollowsTheConnectionNamedAfterItWasSet(): void
    {
        // The modifiers are resolved when the statement is built, not when the
        // flag is set, so a builder assembled before withConnection() still
        // matches the engine it ends up on.
        $query = (new Delete('user', 'id = 1'))->quick()->ignore();

        $this->assertStringNotContainsString('QUICK', $query->withConnection('pg')->getSQL());
    }

    #[Test]
    public function aBulkIgnoreParenthesisesEveryRowOnPostgres(): void
    {
        // The grammar wraps what it is handed in one pair of parentheses, which
        // suits a single row and not a bulk set that brings its own. Written as
        // "VALUES ((?,?),(?,?))" PostgreSQL read the lot as one row holding two
        // row-constructors and refused it, so bulk insertOrIgnore never ran.
        $this->assertSame(
            'INSERT INTO "user" ("username", "email") VALUES (?,?),(?,?) ON CONFLICT DO NOTHING',
            (new Insert('user', [
                ['username' => 'a', 'email' => 'a@x.test'],
                ['username' => 'b', 'email' => 'b@x.test'],
            ]))->ignore()->withConnection('pg')->getSQL()
        );
    }

    #[Test]
    public function aSingleRowIgnoreStillHasExactlyOnePairOfParentheses(): void
    {
        $this->assertSame(
            'INSERT INTO "user" ("username") VALUES (?) ON CONFLICT DO NOTHING',
            (new Insert('user', ['username' => 'a']))->ignore()->withConnection('pg')->getSQL()
        );
    }

    #[Test]
    public function anIgnoredInsertStillReturnsWhatItWasAskedFor(): void
    {
        // The grammar's override ends at DO NOTHING, and the RETURNING clause
        // was appended only on the path that override replaced — so asking for
        // one alongside ignore() silently produced a statement without it.
        $this->assertSame(
            'INSERT INTO "user" ("username") VALUES (?) ON CONFLICT DO NOTHING RETURNING "id"',
            (new Insert('user', ['username' => 'a']))
                ->ignore()->returning('id')->withConnection('pg')->getSQL()
        );
    }

    #[Test]
    public function aRowNamingAColumnTheFirstRowDoesNotIsRefused(): void
    {
        // Every row is written against the first row's columns, so a key only a
        // later row carries was dropped in silence: the insert succeeded, said
        // nothing, and left the column at its default.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names columns the first row does not: score');

        (new Insert('user', [
            ['username' => 'a', 'email' => 'a@x.test'],
            ['username' => 'b', 'email' => 'b@x.test', 'score' => 9],
        ]))->withConnection('mysql')->getSQL();
    }

    #[Test]
    public function aRowShortOfAColumnStillSuppliesNullForIt(): void
    {
        // An absent value is a reasonable thing to read as null; a value the
        // caller supplied and the database never saw is not.
        $query = (new Insert('user', [
            ['username' => 'a', 'email' => 'a@x.test'],
            ['username' => 'b'],
        ]))->withConnection('mysql');

        $this->assertSame(
            'INSERT INTO `user` (`username`, `email`) VALUES (?,?),(?,?)',
            $query->getSQL()
        );
        $this->assertSame(['a', 'a@x.test', 'b', null], $query->getBinds());
    }

    #[Test]
    public function aReorderedRowBindsAgainstTheColumnItNames(): void
    {
        $query = (new Insert('user', [
            ['username' => 'a', 'email' => 'a@x.test'],
            ['email' => 'b@x.test', 'username' => 'b'],
        ]))->withConnection('mysql');

        $this->assertSame(
            'INSERT INTO `user` (`username`, `email`) VALUES (?,?),(?,?)',
            $query->getSQL()
        );
        $this->assertSame(['a', 'a@x.test', 'b', 'b@x.test'], $query->getBinds());
    }

    #[Test]
    public function anInsertWithNothingToInsertIsRefused(): void
    {
        // This built "INSERT INTO `user` () VALUES ()", which no engine takes,
        // after raising "Undefined array key 0" and a TypeError out of
        // array_keys() — both naming a line in the builder rather than the
        // argument at fault.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one column => value pair');

        (new Insert('user', []))->withConnection('mysql')->getSQL();
    }

    #[Test]
    public function aListOfBareValuesIsRefusedAsARowSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row 0 is string');

        (new Insert('user', ['a', 'b']))->withConnection('mysql')->getSQL();
    }

    #[Test]
    public function anEmptyRowInABulkSetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row 1 is empty');

        (new Insert('user', [['username' => 'a'], []]))->withConnection('mysql')->getSQL();
    }

    #[Test]
    public function aSingleRowPassedAsAListOfOneIsStillASingleRow(): void
    {
        $query = (new Insert('user', [['username' => 'a']]))->withConnection('mysql');

        $this->assertSame('INSERT INTO `user` (`username`) VALUES (?)', $query->getSQL());
        $this->assertSame(['a'], $query->getBinds());
    }
}
