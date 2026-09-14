<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;

/**
 * Unit tests for grouping, HAVING and the order bind values are handed over in.
 *
 * Groupable was the lowest-covered file in src at 61.54%. HAVING entries were
 * joined with a comma, which the clause does not accept; a Raw attribute lost
 * whatever operator and value came with it; and bind values were collected in
 * the order the builder methods were called rather than the order getSQL()
 * emits their placeholders.
 *
 * The last two produce SQL the server accepts and runs, so the results are
 * checked against a live database in the matching integration test. What is
 * asserted here is the SQL text and the bind order that reaches the driver.
 */
class GroupingShapeTest extends TestCase
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
    public function twoHavingCallsAreJoinedWithAnd(): void
    {
        // The entries were joined with a comma. GROUP BY takes a list, but
        // HAVING takes one boolean expression, so `HAVING a, b` is a syntax
        // error and a second having() call took the whole query down.
        $query = $this->query()->groupBy('role')
            ->having(new Raw('COUNT(*)'), '>', 1)
            ->having(new Raw('MAX(score)'), '>', 80);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` GROUP BY `u`.`role` HAVING COUNT(*) > ? AND MAX(score) > ?',
            (string)$query
        );
        $this->assertSame([1, 80], $query->getBinds());
    }

    #[Test]
    public function orHavingJoinsWithOr(): void
    {
        $query = $this->query()->groupBy('role')
            ->having(new Raw('COUNT(*)'), '>', 5)
            ->orHaving(new Raw('MAX(score)'), '>', 90);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` GROUP BY `u`.`role` HAVING COUNT(*) > ? OR MAX(score) > ?',
            (string)$query
        );
        $this->assertSame([5, 90], $query->getBinds());
    }

    #[Test]
    public function twoHavingRawCallsAreJoinedWithAnd(): void
    {
        $query = $this->query()->groupBy('role')
            ->havingRaw('COUNT(*) > ?', [1])
            ->orHavingRaw('MAX(score) > ?', [80]);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` GROUP BY `u`.`role` HAVING COUNT(*) > ? OR MAX(score) > ?',
            (string)$query
        );
        $this->assertSame([1, 80], $query->getBinds());
    }

    #[Test]
    public function aRawAttributeKeepsItsOperatorAndValue(): void
    {
        // The operator and value were dropped whenever the attribute was Raw,
        // leaving a bare `WHERE score` — a truthiness test matching every
        // non-zero row. Valid SQL, so nothing reported the missing comparison.
        $query = $this->query()->where(new Raw('score'), '>', 90);

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE score > ?', (string)$query);
        $this->assertSame([90], $query->getBinds());
    }

    #[Test]
    public function aRawHavingAttributeKeepsItsOperatorAndValue(): void
    {
        $query = $this->query()->groupBy('role')->having(new Raw('COUNT(*)'), '>', 2);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` GROUP BY `u`.`role` HAVING COUNT(*) > ?',
            (string)$query
        );
        $this->assertSame([2], $query->getBinds());
    }

    #[Test]
    public function aRawAttributeGivenAloneStandsOnItsOwn(): void
    {
        $query = $this->query()->where(new Raw('score > 90'));

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE score > 90', (string)$query);
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function bindsFollowThePlaceholdersNotTheCallOrder(): void
    {
        // GROUP BY is emitted after WHERE, but groupByRaw() was called first
        // and its value was collected first, so the driver received the two
        // swapped: the WHERE placeholder took 50 and the GROUP BY took 1.
        $query = $this->query()
            ->groupByRaw('CASE WHEN score > ? THEN 1 ELSE 0 END', [50])
            ->where('status_code', '=', 1);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE `u`.`status_code` = ? '
            . 'GROUP BY CASE WHEN score > ? THEN 1 ELSE 0 END',
            (string)$query
        );
        $this->assertSame([1, 50], $query->getBinds());
    }

    #[Test]
    public function havingBindsFollowTheWhereBindsWheneverTheyWereAdded(): void
    {
        $query = $this->query()
            ->havingRaw('COUNT(*) > ?', [1])
            ->groupBy('role')
            ->where('status_code', '=', 1);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE `u`.`status_code` = ? '
            . 'GROUP BY `u`.`role` HAVING COUNT(*) > ?',
            (string)$query
        );
        $this->assertSame([1, 1], $query->getBinds());
    }

    #[Test]
    public function aRawSelectExpressionCarriesItsOwnBinds(): void
    {
        // The binds of a Raw select expression were dropped, leaving the
        // statement one value short of its placeholders — the driver refused
        // to execute it at all.
        $query = $this->query()
            ->select('id', new Raw('IF(score > ?, 1, 0) AS high', [50]))
            ->where('status_code', '=', 1);

        $this->assertSame(
            'SELECT `u`.`id`, IF(score > ?, 1, 0) AS high FROM `user` `u` WHERE `u`.`status_code` = ?',
            (string)$query
        );
        $this->assertSame([50, 1], $query->getBinds());
    }

    #[Test]
    public function unionBindsComeAfterTheOuterQuerysOwn(): void
    {
        $query = $this->query()->select('id')
            ->union((new ActiveQuery())->from('user')->select('id')->where('score', '>', 90))
            ->where('score', '<', 40);

        $this->assertSame([40, 90], $query->getBinds());
    }

    #[Test]
    public function havingRoutesTheIsOperatorToANullCheck(): void
    {
        // where() routes IS to a NULL check; having() did not, so its own value
        // shorthand took the operator as the value and built `HAVING col = 'IS'`
        // — valid SQL matching nothing, with no error to say so.
        $query = $this->query()->groupBy('id')->having('deleted_at', 'IS', null);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` GROUP BY `u`.`id` HAVING `u`.`deleted_at` IS NULL',
            (string)$query
        );
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function havingRoutesTheIsNotOperatorToANotNullCheck(): void
    {
        $query = $this->query()->groupBy('id')->having('deleted_at', 'IS NOT', null);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` GROUP BY `u`.`id` HAVING `u`.`deleted_at` IS NOT NULL',
            (string)$query
        );
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function theIsOperatorRejectsANonNullValue(): void
    {
        // IS takes NULL, TRUE or FALSE, not a placeholder, so this built
        // `col IS ?` — rejected by the server with a message naming only the
        // position in the statement.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"IS" compares against NULL; got string.');

        $this->query()->where('deleted_at', 'IS', 'x');
    }

    #[Test]
    public function theIsNotOperatorRejectsANonNullValueInHaving(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"IS NOT" compares against NULL; got int.');

        $this->query()->groupBy('id')->having('deleted_at', 'IS NOT', 5);
    }

    #[Test]
    public function aJoinedSubQueryIsNotQuotedAsAnIdentifier(): void
    {
        // The sub-query was folded into the table string and handed to quote(),
        // which wrapped the whole SELECT in backticks as one column name — the
        // server refused it as an over-long identifier. It was aliased twice as
        // well, and its bind value was dropped.
        $sub = (new ActiveQuery())->from('post')->select('user_id')->where('view_count', '>', 100);
        $query = $this->query()->select('id')
            ->join(['p' => $sub], ['user_id' => 'id'])
            ->where('score', '>', 50);

        $this->assertSame(
            'SELECT `u`.`id` FROM `user` `u` '
            . 'INNER JOIN (SELECT `post`.`user_id` FROM `post` WHERE `post`.`view_count` > ?) AS `p` '
            . 'ON `p`.`user_id` = `u`.`id` WHERE `u`.`score` > ?',
            (string)$query
        );
        $this->assertSame([100, 50], $query->getBinds());
    }

    #[Test]
    public function aJoinedRawSubQueryKeepsItsBinds(): void
    {
        $query = $this->query()->select('id')->join(
            ['p' => new Raw('SELECT user_id FROM post WHERE view_count > ?', [100])],
            ['user_id' => 'id']
        );

        $this->assertSame(
            'SELECT `u`.`id` FROM `user` `u` '
            . 'INNER JOIN (SELECT user_id FROM post WHERE view_count > ?) AS `p` '
            . 'ON `p`.`user_id` = `u`.`id`',
            (string)$query
        );
        $this->assertSame([100], $query->getBinds());
    }

    #[Test]
    public function mergeKeepsEachBindListInItsOwnSection(): void
    {
        // The incoming binds were appended wholesale to the WHERE list, so the
        // merged query's HAVING value landed behind this query's own WHERE
        // placeholder.
        $other = $this->query()->havingRaw('COUNT(*) > ?', [1])->where('role', '=', 'member');
        $query = $this->query()->select('role')
            ->where('status_code', '=', 1)
            ->groupBy('role')
            ->merge($other);

        $this->assertSame([1, 'member', 1], $query->getBinds());
    }

    #[Test]
    public function clearBindsEmptiesEverySection(): void
    {
        $query = $this->query()
            ->select(new Raw('IF(score > ?, 1, 0)', [50]))
            ->where('status_code', '=', 1)
            ->groupByRaw('CASE WHEN score > ? THEN 1 ELSE 0 END', [10])
            ->havingRaw('COUNT(*) > ?', [1]);

        $this->assertSame([50, 1, 10, 1], $query->getBinds());

        $query->clearBinds();

        $this->assertNull($query->getBinds());
    }
}
