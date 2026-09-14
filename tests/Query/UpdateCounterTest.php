<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;

/**
 * setCounter() renders in the same pass, and the same order, as everything else.
 *
 * It used to render and bind the moment it was called. Two things followed: the
 * column was quoted for whichever grammar happened to be current then, and the
 * value was bound ahead of the attribute values even though its assignment is
 * emitted after them.
 */
class UpdateCounterTest extends TestCase
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

    #[Test]
    public function theBindsArriveInTheOrderTheirPlaceholdersDo(): void
    {
        // The counter's value was bound first though its assignment is emitted
        // last, so the two values were sent in the wrong order and each was
        // written into the other's column. On MySQL that meant a string landing
        // in an integer column, rejected with "Truncated incorrect INTEGER
        // value" — naming the value but not the cause.
        $update = new Update('user', ['username' => 'ALPHA'], 'id = 1');
        $update->withConnection('mysql');
        $update->setCounter('status_code', 7);

        $this->assertSame(
            'UPDATE `user` SET `username` = ?, `status_code` = `status_code` + ? WHERE id = 1',
            $update->getSQL()
        );
        $this->assertSame(['ALPHA', 7], $update->getBinds());
    }

    #[Test]
    public function theColumnIsQuotedForTheConnectionTheStatementRunsOn(): void
    {
        // Model::updateCounter() calls setCounter() before withConnection(), so
        // quoting at call time used the default grammar: on PostgreSQL the
        // statement was built with MySQL backticks and the server rejected it
        // outright with a syntax error.
        $update = new Update('user', [], 'id = 1');
        $update->setCounter('status_code', 1);
        $update->withConnection('pg');

        $this->assertSame(
            'UPDATE "user" SET "status_code" = "status_code" + ? WHERE id = 1',
            $update->getSQL()
        );
    }

    #[Test]
    public function theOrderOfSetCounterAndWithConnectionDoesNotMatter(): void
    {
        $before = new Update('user', [], 'id = 1');
        $before->setCounter('status_code', 1);
        $before->withConnection('pg');

        $after = new Update('user', [], 'id = 1');
        $after->withConnection('pg');
        $after->setCounter('status_code', 1);

        $this->assertSame($after->getSQL(), $before->getSQL());
    }

    #[Test]
    public function aDecrementSubtractsTheAbsoluteValue(): void
    {
        $update = new Update('user', [], 'id = 1');
        $update->withConnection('mysql');
        $update->setCounter('status_code', -5);

        $this->assertStringContainsString('`status_code` = `status_code` - ?', $update->getSQL());
        $this->assertSame([5], $update->getBinds());
    }

    #[Test]
    public function aZeroCounterAssignsRatherThanIncrements(): void
    {
        $update = new Update('user', [], 'id = 1');
        $update->withConnection('mysql');
        $update->setCounter('status_code', 0);

        $this->assertStringContainsString('`status_code` = ?', $update->getSQL());
        $this->assertStringNotContainsString('+', $update->getSQL());
        $this->assertSame([0], $update->getBinds());
    }

    #[Test]
    public function severalCountersKeepTheirOrder(): void
    {
        $update = new Update('user', [], 'id = 1');
        $update->withConnection('mysql');
        $update->setCounter('status_code', 2);
        $update->setCounter('department_id', 5);

        $this->assertSame(
            'UPDATE `user` SET `status_code` = `status_code` + ?, `department_id` = `department_id` + ? WHERE id = 1',
            $update->getSQL()
        );
        $this->assertSame([2, 5], $update->getBinds());
    }

    #[Test]
    public function aCounterAddedAfterAReadReachesTheStatement(): void
    {
        $update = new Update('user', [], 'id = 1');
        $update->withConnection('mysql');

        // Without a set clause this reads "SET 1 = 1", and the cache used to
        // keep it that way — the counter was dropped and the statement was a
        // syntax error the server refused.
        $update->getSQL();
        $update->setCounter('status_code', 1);

        $this->assertStringContainsString('`status_code` = `status_code` + ?', $update->getSQL());
    }

    #[Test]
    public function readingTheSqlTwiceDoesNotRepeatTheCounter(): void
    {
        $update = new Update('user', [], 'id = 1');
        $update->withConnection('mysql');
        $update->setCounter('status_code', 1);

        $first = $update->getSQL();

        $this->assertSame($first, $update->getSQL());
        $this->assertSame([1], $update->getBinds());
    }

    #[Test]
    public function anInvalidColumnIsRefusedByTheCallThatNamedIt(): void
    {
        // setCounter() writes the column name twice, once on each side of the
        // assignment, so it is worth refusing early and at the call site.
        $this->expectException(InvalidArgumentException::class);

        (new Update('user', [], 'id = 1'))
            ->withConnection('mysql')
            ->setCounter('score` = 0, `role', 1);
    }
}
