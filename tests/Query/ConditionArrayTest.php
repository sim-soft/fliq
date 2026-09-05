<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Conditions\Condition;
use Simsoft\DB\Connection;

/**
 * Unit tests for the array forms of where().
 *
 * Two shapes are accepted: a list of [attribute, operator, value] triplets,
 * and a map of attribute => value where an array value means IN. Both built
 * `IN ()` for an empty set, and the list form destructured its entries
 * without checking their shape.
 */
class ConditionArrayTest extends TestCase
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
    public function anEmptySetInTheMapFormSkipsThatEntry(): void
    {
        // `IN ()` is a syntax error, so a filter narrowed to nothing took the
        // whole query down. The entry is dropped instead, as in() already does
        // for an empty value list.
        $query = $this->query()->where(['role' => 'admin', 'id' => []]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE (`u`.`role` = ?)', (string)$query);
        $this->assertSame(['admin'], $query->getBinds());
    }

    #[Test]
    public function aMapOfNothingButEmptySetsAddsNoCondition(): void
    {
        $query = $this->query()->where(['id' => [], 'role' => []]);

        $this->assertSame('SELECT `u`.* FROM `user` `u`', (string)$query);
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function anEmptyMapConditionLeavesOtherConditionsIntact(): void
    {
        // The condition builds an empty string, which onCondition() drops
        // without leaving the operator dangling behind it.
        $query = $this->query()->where('status_code', 1)->where(['id' => []]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`status_code` = ?', (string)$query);
        $this->assertSame([1], $query->getBinds());
    }

    #[Test]
    public function anEmptySetInTheListFormSkipsThatEntry(): void
    {
        $query = $this->query()->where([['role', '=', 'admin'], ['id', 'IN', []]]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`role` = ?', (string)$query);
        $this->assertSame(['admin'], $query->getBinds());
    }

    #[Test]
    public function anEmptyNotInSetInTheListFormSkipsThatEntry(): void
    {
        $query = $this->query()->where([['id', 'NOT IN', []]]);

        $this->assertSame('SELECT `u`.* FROM `user` `u`', (string)$query);
    }

    #[Test]
    public function conditionsOnBothSidesOfADroppedEntryAreJoinedToEachOther(): void
    {
        // Dropping the middle entry must not leave the survivors unjoined.
        $query = $this->query()->where([
            ['role', '=', 'admin'],
            ['id', 'IN', []],
            ['status_code', '=', 1],
        ]);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE `u`.`role` = ? AND `u`.`status_code` = ?',
            (string)$query
        );
        $this->assertSame(['admin', 1], $query->getBinds());
    }

    #[Test]
    public function aShortTripletNamesTheEntryAtFault(): void
    {
        // The triplet was destructured without checking its shape, so this
        // raised "Undefined array key 2" and then bound null — a warning in
        // the log and a condition matching nothing.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition #0 must be [attribute, operator, value]; got 2 elements.');

        (string)$this->query()->where([['id', '=']]);
    }

    #[Test]
    public function aNonArrayEntryNamesItsPosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition #1 must be [attribute, operator, value]; got string.');

        (string)$this->query()->where([['id', '=', 1], 'id = 1']);
    }

    #[Test]
    public function aSetOperatorInTheListFormRejectsAScalar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN on "id" needs an array of values; got int.');

        (string)$this->query()->where([['id', 'IN', 5]]);
    }

    #[Test]
    public function theListFormStillBuildsItsOrdinaryShape(): void
    {
        $query = $this->query()->where([
            ['height', '>=', 150],
            ['id', 'IN', [1, 2]],
        ]);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE `u`.`height` >= ? AND `u`.`id` IN (?,?)',
            (string)$query
        );
        $this->assertSame([150, 1, 2], $query->getBinds());
    }

    #[Test]
    public function theMapFormStillBuildsItsOrdinaryShape(): void
    {
        $query = $this->query()->where(['role' => 'admin', 'id' => [1, 2]]);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE (`u`.`role` = ? AND `u`.`id` IN (?,?))',
            (string)$query
        );
        $this->assertSame(['admin', 1, 2], $query->getBinds());
    }

    #[Test]
    public function aNullValueInTheMapFormBindsAsAValue(): void
    {
        // The map form has no null shorthand — an explicit null is a value.
        $query = $this->query()->where(['deleted_at' => null]);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE (`u`.`deleted_at` = ?)', (string)$query);
        $this->assertSame([null], $query->getBinds());
    }

    #[Test]
    public function havingBindsANullValueRatherThanTheOperator(): void
    {
        // having('id') left the value null, and the clause bound the operator
        // in its place — the literal "=" — then reset the operator to '='.
        // The shorthand predates operator() validating its input, so it could
        // only ever bind that literal and match nothing.
        $query = $this->query()->groupBy('id')->having('id');

        $this->assertSame('SELECT `u`.* FROM `user` `u` GROUP BY `u`.`id` HAVING `u`.`id` = ?', (string)$query);
        $this->assertSame([null], $query->getBinds());
    }

    #[Test]
    public function aClauseAgainstANullValueKeepsItsOperator(): void
    {
        // The operator was bound in the value's place and then reset to '=',
        // so this built `score = '>'` — the column compared against the literal
        // operator text, which a non-null column value could have matched.
        // having() cannot reach this: its own shorthand treats a null value as
        // a signal that the operator slot holds the value, so a clause built
        // directly is the only way in.
        $condition = (new Condition('score', null))->operator('>');

        $this->assertSame('{score} > ?', (string)$condition);
        $this->assertSame([null], $condition->getBinds());
    }

    #[Test]
    public function havingStillBuildsAnOrdinaryComparison(): void
    {
        $query = $this->query()->groupBy('role')->having('id', '>', 2);

        $this->assertSame('SELECT `u`.* FROM `user` `u` GROUP BY `u`.`role` HAVING `u`.`id` > ?', (string)$query);
        $this->assertSame([2], $query->getBinds());
    }
}
