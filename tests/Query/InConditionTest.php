<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Conditions\InCondition;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Select;
use Simsoft\DB\Connection;

/**
 * Unit tests for IN / NOT IN conditions.
 *
 * The set operators have three shapes of right-hand side — a list, a subquery
 * and a Raw expression — plus the empty list, which is a set rather than a
 * missing condition.
 */
class InConditionTest extends TestCase
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

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function inListBindsEveryValue(): void
    {
        $query = (new ActiveQuery())->from('user')->in('id', [1, 2, 3]);

        $this->assertStringContainsString('`user`.`id` IN (?,?,?)', $query->getSQL());
        $this->assertSame([1, 2, 3], $query->getBinds());
    }

    #[Test]
    public function notInListBindsEveryValue(): void
    {
        $query = (new ActiveQuery())->from('user')->notIn('id', [1, 2, 3]);

        $this->assertStringContainsString('`user`.`id` NOT IN (?,?,?)', $query->getSQL());
        $this->assertSame([1, 2, 3], $query->getBinds());
    }

    /**
     * An empty list is a set with nothing in it, not an absent condition.
     *
     * Dropping it made `in('id', [])` match every row, so an empty allow-list
     * widened the query to the whole table instead of narrowing it to nothing.
     */
    #[Test]
    public function emptyInMatchesNothing(): void
    {
        $query = (new ActiveQuery())->from('user')->in('id', []);

        $this->assertStringContainsString('1 = 0', $query->getSQL());
        $this->assertStringNotContainsString('IN ()', $query->getSQL());
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function emptyNotInMatchesEverything(): void
    {
        $query = (new ActiveQuery())->from('user')->notIn('id', []);

        $this->assertStringContainsString('1 = 1', $query->getSQL());
        $this->assertStringNotContainsString('NOT IN ()', $query->getSQL());
        $this->assertNull($query->getBinds());
    }

    /**
     * Under OR the constant has to be emitted, not skipped.
     *
     * Skipping is invisible beside an AND, which is why it survived: the
     * preceding condition still narrows. Beside an OR it decides the answer.
     */
    #[Test]
    public function emptySetKeepsItsMeaningBesideOr(): void
    {
        $inSql = (new ActiveQuery())->from('user')
            ->where('id', 1)->orIn('id', [])->getSQL();

        $this->assertStringContainsString('OR 1 = 0', $inSql);

        $notInSql = (new ActiveQuery())->from('user')
            ->where('id', 1)->orNotIn('id', [])->getSQL();

        $this->assertStringContainsString('OR 1 = 1', $notInSql);
    }

    #[Test]
    public function emptySetKeepsItsMeaningBesideAnd(): void
    {
        $sql = (new ActiveQuery())->from('user')
            ->where('id', 1)->in('id', [])->getSQL();

        $this->assertStringContainsString('AND 1 = 0', $sql);
        // The other condition's bind is still there; the empty set adds none.
        $this->assertSame([1], (new ActiveQuery())->from('user')
            ->where('id', 1)->in('id', [])->getBinds());
    }

    #[Test]
    public function inSubqueryCarriesItsBinds(): void
    {
        $sub = (new ActiveQuery())->from('post')->select('user_id')->where('view_count', '>', 100);
        $query = (new ActiveQuery())->from('user')->in('id', $sub);

        $this->assertStringContainsString('IN (SELECT `post`.`user_id`', $query->getSQL());
        $this->assertSame([100], $query->getBinds());
    }

    /**
     * A subquery with nothing to bind must contribute no binds.
     *
     * getBinds() says "none" with null, and appending that as a value bound a
     * SQL NULL for it — leaving one bind with no placeholder to fill, which
     * the driver rejected as "Invalid parameter number". `IN (SELECT ...)`
     * with no WHERE could not run at all.
     */
    #[Test]
    public function bindlessSubqueryAddsNoBinds(): void
    {
        $query = (new ActiveQuery())->from('user')
            ->in('id', (new ActiveQuery())->from('post')->select('user_id'));

        $this->assertStringContainsString('IN (SELECT `post`.`user_id`', $query->getSQL());
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function bindlessRawAddsNoBinds(): void
    {
        $query = (new ActiveQuery())->from('user')
            ->in('id', new Raw('SELECT user_id FROM post'));

        $this->assertStringContainsString('IN (SELECT user_id FROM post)', $query->getSQL());
        $this->assertNull($query->getBinds());
    }

    #[Test]
    public function inRawCarriesItsBinds(): void
    {
        $query = (new ActiveQuery())->from('user')
            ->in('id', new Raw('SELECT user_id FROM post WHERE view_count > ?', [100]));

        $this->assertStringContainsString('IN (SELECT user_id FROM post WHERE view_count > ?)', $query->getSQL());
        $this->assertSame([100], $query->getBinds());
    }

    /**
     * The same null-means-none confusion, in the other place that had it.
     */
    #[Test]
    public function selectWithBindlessConditionAddsNoBinds(): void
    {
        $select = new Select('user', ['id'], new Raw('id > 0'));

        $this->assertStringContainsString('WHERE id > 0', $select->getSQL());
        $this->assertNull($select->getBinds());
    }

    #[Test]
    public function selectWithBoundConditionKeepsItsBinds(): void
    {
        $select = new Select('user', ['id'], new Raw('id > ?', [8]));

        $this->assertSame([8], $select->getBinds());
    }

    /**
     * A value that is none of the three accepted shapes is refused.
     *
     * The three branches were consecutive ifs with no else and no throw, so a
     * scalar fell through all of them and left `id IN ()` — a syntax error
     * raised by the server, naming nothing that led back to the call.
     */
    #[Test]
    public function scalarValueIsRefusedWhenBuilding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN on "id" needs an array, subquery or Raw expression; got int.');

        (new InCondition('id', 5))->getSQL();
    }

    #[Test]
    public function nullValueIsRefusedWhenBuilding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('got null');

        (new InCondition('id', null))->getSQL();
    }

    #[Test]
    public function negatedRefusalNamesNotIn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NOT IN on "id"');

        (new InCondition('id', 'abc', false))->getSQL();
    }

    /**
     * in() qualifies the column against the query's alias.
     *
     * The array case used to be built inline in ActiveQuery rather than by
     * InCondition, so the two could drift; this pins the qualification that
     * the inline path provided.
     */
    #[Test]
    public function inQualifiesAgainstTheQueryAlias(): void
    {
        $sql = (new ActiveQuery())->from(['u' => 'user'])->in('id', [1, 2])->getSQL();

        $this->assertStringContainsString('`u`.`id` IN (?,?)', $sql);
    }

    #[Test]
    public function whereInAliasesToIn(): void
    {
        $viaWhereIn = (new ActiveQuery())->from('user')->whereIn('id', [1, 2])->getSQL();
        $viaIn = (new ActiveQuery())->from('user')->in('id', [1, 2])->getSQL();

        $this->assertSame($viaIn, $viaWhereIn);
    }

    #[Test]
    public function whereNotInAliasesToNotIn(): void
    {
        $viaWhereNotIn = (new ActiveQuery())->from('user')->whereNotIn('id', [1, 2])->getSQL();
        $viaNotIn = (new ActiveQuery())->from('user')->notIn('id', [1, 2])->getSQL();

        $this->assertSame($viaNotIn, $viaWhereNotIn);
    }

    /**
     * where('id', 'IN', [...]) routes to the same builder as in().
     */
    #[Test]
    public function whereOperatorFormMatchesInDirectly(): void
    {
        $viaWhere = (new ActiveQuery())->from('user')->where('id', 'IN', [1, 2])->getSQL();
        $viaIn = (new ActiveQuery())->from('user')->in('id', [1, 2])->getSQL();

        $this->assertSame($viaIn, $viaWhere);
    }

    #[Test]
    public function keysAreDiscardedSoPlaceholdersStayPositional(): void
    {
        $query = (new ActiveQuery())->from('user')->where('id', 'IN', [3 => 'a', 7 => 'b']);

        $this->assertStringContainsString('IN (?,?)', $query->getSQL());
        $this->assertSame(['a', 'b'], $query->getBinds());
    }
}
