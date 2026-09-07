<?php

namespace Query;

use Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;

/**
 * The condition `deleteAll()` refuses.
 *
 * Its docblock promises it "requires an explicit condition to prevent
 * accidental full-table deletes", but the only thing enforcing that was the
 * parameter type, which rules out `null` and nothing else. Every other way of
 * expressing "no condition" — `''`, a `Raw` holding nothing, an `ActiveQuery`
 * whose filters all turned out to be empty — passed the type check and built a
 * bare `DELETE FROM table`.
 *
 * These run without a server: the guard has to reject the call before a
 * statement is ever built, so reaching the database at all would be a failure.
 */
class DeleteAllGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * Conditions that name no rows, and so would delete every one.
     *
     * @return array<string, array{0: string|ActiveQuery|Raw}>
     */
    public static function emptyConditions(): array
    {
        return [
            'empty string' => [''],
            'only whitespace' => ['   '],
            'newline' => ["\n"],
            'empty Raw' => [new Raw('')],
            'whitespace Raw' => [new Raw('  ')],
            'unfiltered query' => [(new ActiveQuery())->from('user')->on('mysql')],
            // A query that selects and orders but never narrows: the SELECT
            // reads one row, the DELETE built from it reads none of that and
            // removes the lot.
            'query with only a limit' => [(new ActiveQuery())->from('user')->on('mysql')->limit(1)],
            'query with only an order' => [(new ActiveQuery())->from('user')->on('mysql')->orderBy('id')],
        ];
    }

    #[Test]
    #[DataProvider('emptyConditions')]
    public function anEmptyConditionIsRefused(string|ActiveQuery|Raw $condition): void
    {
        $this->expectException(QueryException::class);

        (new User())->deleteAll($condition);
    }

    #[Test]
    public function theRefusalNamesTheDeliberateAlternative(): void
    {
        // Whoever hits this is one keystroke from doing it on purpose, so the
        // message has to say which call that is.
        $this->expectExceptionMessageMatches('/deleteAllUnchecked/');

        (new User())->deleteAll('');
    }

    /**
     * Conditions that do narrow, and so must be allowed through.
     *
     * @return array<string, array{0: string|ActiveQuery|Raw}>
     */
    public static function narrowingConditions(): array
    {
        return [
            'string' => ['id = 1'],
            'padded string' => ['  id = 1  '],
            'Raw' => [new Raw('id = ?', [1])],
            'query with a where' => [(new ActiveQuery())->from('user')->on('mysql')->where('id', '=', 1)],
            // A condition that matches everything is still a condition the
            // caller wrote on purpose. The guard is about emptiness, not about
            // second-guessing a deliberate `1=1`.
            'always-true string' => ['1 = 1'],
            'where plus limit' => [(new ActiveQuery())->from('user')->on('mysql')->where('id', '=', 1)->limit(1)],
        ];
    }

    #[Test]
    #[DataProvider('narrowingConditions')]
    public function aNarrowingConditionIsAccepted(string|ActiveQuery|Raw $condition): void
    {
        // Calling deleteAll() here would delete rows, so the guard is asked
        // directly. It is the whole difference between the two outcomes: past
        // it, deleteAll() does nothing but hand the condition to the builder.
        $guard = new ReflectionMethod(User::class, 'requireNarrowingCondition');
        $guard->setAccessible(true);

        $guard->invoke(new User(), $condition);

        // Past the guard, deleteAll() does nothing but hand the condition to
        // the builder — so what the builder makes of it is the outcome being
        // allowed through, and it must be a statement that narrows.
        $delete = new Delete('user', $condition);
        $delete->withConnection('mysql');

        $sql = new ReflectionMethod($delete, 'buildSQL');
        $sql->setAccessible(true);

        $this->assertStringContainsString('WHERE', (string)$sql->invoke($delete));
    }

    #[Test]
    public function theGuardAgreesWithWhatTheStatementWouldSay(): void
    {
        // The guard's judgement has to track the SQL the Delete builder
        // actually produces, or it protects a statement other than the one
        // that runs. An empty condition yields a DELETE with no WHERE at all.
        $delete = new Delete('user', '');
        $delete->withConnection('mysql');

        $sql = new ReflectionMethod($delete, 'buildSQL');
        $sql->setAccessible(true);

        $this->assertStringNotContainsString('WHERE', (string)$sql->invoke($delete));
    }
}
