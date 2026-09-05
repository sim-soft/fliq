<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Conditions\Condition;
use Simsoft\DB\Connection;

/**
 * Unit tests for operator/value shape handling in conditions.
 *
 * Condition was the lowest-covered file in src at 47.22%. Every operator on
 * the documented whitelist is accepted by validateOperator(), but IN, NOT IN,
 * BETWEEN, NOT BETWEEN, IS and IS NOT need a right-hand side that is not a
 * single placeholder — and nothing built one. Each case below either emitted
 * SQL the server rejects, or quietly matched the wrong rows.
 */
class ConditionShapeTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]);
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user u');
    }

    #[Test]
    public function isNullOperatorBuildsAnIsNullCheck(): void
    {
        // IS is on the documented operator whitelist, but nothing handled it.
        // The shorthand saw a null value, took the operator as the value, and
        // built `deleted_at = 'IS'` — which runs, matches nothing, and reports
        // no error at all.
        $query = $this->query()->where('deleted_at', 'IS', null);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`deleted_at` IS NULL', (string)$query);
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function isNotNullOperatorBuildsAnIsNotNullCheck(): void
    {
        $query = $this->query()->where('deleted_at', 'IS NOT', null);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`deleted_at` IS NOT NULL', (string)$query);
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function theIsOperatorIsCaseInsensitiveLikeEveryOtherWordOperator(): void
    {
        // The docs say word operators are case-insensitive, so 'is' must
        // behave as 'IS' does.
        $query = $this->query()->where('deleted_at', 'is', null);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`deleted_at` IS NULL', (string)$query);
    }

    #[Test]
    public function theDiamondOperatorAgainstNullBuildsAnIsNotNullCheck(): void
    {
        // `!=` was already routed to IS NOT NULL; `<>` means the same thing
        // and is on the same whitelist, but fell through to `col = '<>'`.
        $query = $this->query()->where('deleted_at', '<>', null);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`deleted_at` IS NOT NULL', (string)$query);
    }

    #[Test]
    public function theInOperatorBuildsAPlaceholderGroup(): void
    {
        // Only one placeholder was emitted, so this built `id IN ?` and the
        // server rejected the statement. It is the same condition in() already
        // builds correctly, so it is routed there.
        $query = $this->query()->where('id', 'IN', [1, 2, 3]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`id` IN (?,?,?)', (string)$query);
        $this->assertSame([1, 2, 3], $query->getBinds());
    }

    #[Test]
    public function theNotInOperatorBuildsANegatedPlaceholderGroup(): void
    {
        $query = $this->query()->where('id', 'NOT IN', [1, 2]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`id` NOT IN (?,?)', (string)$query);
        $this->assertSame([1, 2], $query->getBinds());
    }

    #[Test]
    public function theBetweenOperatorBuildsBothBounds(): void
    {
        // `id BETWEEN ?` with two binds — a syntax error and a placeholder
        // count that did not match the values.
        $query = $this->query()->where('id', 'BETWEEN', [2, 4]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`id` BETWEEN ? AND ?', (string)$query);
        $this->assertSame([2, 4], $query->getBinds());
    }

    #[Test]
    public function theNotBetweenOperatorBuildsBothBounds(): void
    {
        $query = $this->query()->where('id', 'NOT BETWEEN', [2, 4]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`id` NOT BETWEEN ? AND ?', (string)$query);
        $this->assertSame([2, 4], $query->getBinds());
    }

    #[Test]
    public function aSetOperatorKeepsOnlyTheValuesOfAMap(): void
    {
        // Placeholders are positional, so a map's keys have nowhere to go.
        $query = $this->query()->where('id', 'IN', ['a' => 1, 'b' => 2]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`id` IN (?,?)', (string)$query);
        $this->assertSame([1, 2], $query->getBinds());
    }

    #[Test]
    public function aRangeOperatorNeedsExactlyTwoBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BETWEEN on "id" needs exactly two bounds; got 3 values.');

        $this->query()->where('id', 'BETWEEN', [1, 2, 3]);
    }

    #[Test]
    public function aRangeOperatorRejectsANonArrayRightHandSide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BETWEEN on "id" needs exactly two bounds; got int.');

        $this->query()->where('id', 'BETWEEN', 5);
    }

    #[Test]
    public function aSetOperatorRejectsAScalarRightHandSide(): void
    {
        // `where('id', 'IN', 5)` built `id IN ?`, which the server rejects.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN on "id" needs an array, subquery or Raw expression; got int.');

        $this->query()->where('id', 'IN', 5);
    }

    #[Test]
    public function anEmptySetSkipsTheConditionAsInDoes(): void
    {
        // Routed to in(), which already returns early for an empty list.
        $query = $this->query()->where('id', 'IN', []);

        $this->assertSame('SELECT `u`.* FROM `user` `u`', (string)$query);
    }

    #[Test]
    public function aComparisonOperatorRejectsSeveralValues(): void
    {
        // One placeholder against two values left the statement short, and
        // the driver reported "must consist of exactly 1 elements" without
        // naming the attribute responsible.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"username" with operator "LIKE" takes a single value; got 2.');

        (string)$this->query()->where('username', 'LIKE', ['%a%', '%b%']);
    }

    #[Test]
    public function aConditionBuiltDirectlyRejectsASetOperator(): void
    {
        // The clause is exported and constructible on its own, where nothing
        // routes the operator elsewhere. It emits a single placeholder, so it
        // says what to use instead rather than building SQL that cannot run.
        $condition = (new Condition('id', [1, 2]))->operator('IN');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition cannot build "IN"; use in(), notIn(), between() or notBetween() instead.');

        (string)$condition;
    }

    #[Test]
    public function anOrdinaryComparisonIsUnaffected(): void
    {
        $query = $this->query()->where('id', '>', 5)->where('username', 'LIKE', '%bob%');

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE `u`.`id` > ? AND `u`.`username` LIKE ?',
            (string)$query
        );
        $this->assertSame([5, '%bob%'], $query->getBinds());
    }
}
