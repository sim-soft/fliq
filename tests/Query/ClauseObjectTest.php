<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\HavingClause;
use Simsoft\DB\Builder\Clauses\OrderByClause;
use Simsoft\DB\Builder\Clauses\SelectClause;
use Simsoft\DB\Builder\Conditions\BetweenCondition;
use Simsoft\DB\Builder\Conditions\LikeCondition;
use Simsoft\DB\Connection;

/**
 * Unit tests for the standalone clause objects.
 *
 * `select()` and `where()` both accept a Clause, so these classes are public
 * API, but none of them had a single test — every one sat at 0.00% coverage.
 * They were ActiveQuery's own implementation until the builder inlined its
 * clause construction, which left them in place, exported, and unexercised.
 *
 * Each defect below produced SQL the server rejected, or a bind list that did
 * not match the placeholders, and reported it far from the call responsible.
 */
class ClauseObjectTest extends TestCase
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

    /**
     * A query on `user` aliased to `u`.
     *
     * @return ActiveQuery
     */
    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user u')->on('mysql');
    }

    // ---------------------------------------------------------------
    // LikeCondition
    // ---------------------------------------------------------------

    #[Test]
    public function likeConditionBuildsASingleComparison(): void
    {
        $query = $this->query()->where(new LikeCondition('username', '%a%'));

        $this->assertStringContainsString('`u`.`username` LIKE ?', $query->getSQL());
        $this->assertSame(['%a%'], $query->getBinds());
    }

    #[Test]
    public function likeConditionJoinsSeveralPatternsWithAnd(): void
    {
        $query = $this->query()->where(new LikeCondition('username', ['%a%', '%b%']));

        $this->assertStringContainsString('(`u`.`username` LIKE ? AND `u`.`username` LIKE ?)', $query->getSQL());
        $this->assertSame(['%a%', '%b%'], $query->getBinds());
    }

    #[Test]
    public function likeConditionJoinsWithOrWhenMatchAllIsOff(): void
    {
        $condition = (new LikeCondition('username', ['%a%', '%b%']))->matchAll(false);

        $this->assertStringContainsString(
            '(`u`.`username` LIKE ? OR `u`.`username` LIKE ?)',
            $this->query()->where($condition)->getSQL()
        );
    }

    #[Test]
    public function likeConditionNegates(): void
    {
        $this->assertStringContainsString(
            '`u`.`username` NOT LIKE ?',
            $this->query()->where(new LikeCondition('username', '%a%', false))->getSQL()
        );
    }

    #[Test]
    public function likeConditionWithNoPatternsAddsNothing(): void
    {
        // Previously built an empty group, `WHERE ()`, which the server rejects.
        $sql = $this->query()->where(new LikeCondition('username', []))->getSQL();

        $this->assertSame('SELECT `u`.* FROM `user` `u`', $sql);
        $this->assertStringNotContainsString('()', $sql);
    }

    #[Test]
    public function anEmptyClauseLeavesNoDanglingOperator(): void
    {
        // The logical operator used to be appended before the clause was built,
        // so an empty one left `AND` with nothing after it.
        $sql = $this->query()
            ->where('status_code', 1)
            ->where(new LikeCondition('username', []))
            ->getSQL();

        $this->assertSame('SELECT `u`.* FROM `user` `u` WHERE `u`.`status_code` = ?', $sql);
        $this->assertStringNotContainsString('AND', $sql);
    }

    // ---------------------------------------------------------------
    // BetweenCondition
    // ---------------------------------------------------------------

    #[Test]
    public function betweenConditionBuildsARange(): void
    {
        $query = $this->query()->where(new BetweenCondition('id', [2, 8]));

        $this->assertStringContainsString('`u`.`id` BETWEEN ? AND ?', $query->getSQL());
        $this->assertSame([2, 8], $query->getBinds());
    }

    #[Test]
    public function betweenConditionNegates(): void
    {
        $this->assertStringContainsString(
            '`u`.`id` NOT BETWEEN ? AND ?',
            $this->query()->where(new BetweenCondition('id', [2, 8], false))->getSQL()
        );
    }

    #[Test]
    public function betweenConditionRejectsTheWrongNumberOfBounds(): void
    {
        // Two placeholders are always emitted, so any other count left the
        // statement with a placeholder/bind mismatch the driver reported
        // without naming the attribute.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between() on "id" needs exactly two bounds; got 1 values.');

        $this->query()->where(new BetweenCondition('id', [5]))->getSQL();
    }

    #[Test]
    public function betweenConditionRejectsNoBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('got 0 values');

        $this->query()->where(new BetweenCondition('id', []))->getSQL();
    }

    #[Test]
    public function betweenConditionRejectsTooManyBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('got 3 values');

        $this->query()->where(new BetweenCondition('id', [1, 2, 3]))->getSQL();
    }

    // ---------------------------------------------------------------
    // SelectClause
    // ---------------------------------------------------------------

    #[Test]
    public function selectClauseListsItsColumns(): void
    {
        $this->assertSame(
            'SELECT `u`.`id`, `u`.`username` FROM `user` `u`',
            $this->query()->select(new SelectClause(['id', 'username']))->getSQL()
        );
    }

    #[Test]
    public function anEmptySelectClauseFallsBackToTheWildcard(): void
    {
        // Previously emitted `SELECT  FROM`, a syntax error.
        $this->assertSame(
            'SELECT `u`.* FROM `user` `u`',
            $this->query()->select(new SelectClause([]))->getSQL()
        );
    }

    #[Test]
    public function anEmptySelectClauseDoesNotBlankOutOtherColumns(): void
    {
        // The empty entry used to survive in the list and print as `a, , b`.
        $this->assertSame(
            'SELECT `u`.`id`, `u`.`username` FROM `user` `u`',
            $this->query()->select('id', new SelectClause([]), 'username')->getSQL()
        );
    }

    // ---------------------------------------------------------------
    // HavingClause
    // ---------------------------------------------------------------

    #[Test]
    public function havingClauseUsesItsOperator(): void
    {
        $query = $this->query()->where((new HavingClause('score', 5))->operator('>'));

        $this->assertStringContainsString('`u`.`score` > ?', $query->getSQL());
        $this->assertSame([5], $query->getBinds());
    }

    #[Test]
    public function havingClauseBindsANullValue(): void
    {
        // The placeholder is emitted unconditionally, so skipping the bind left
        // the statement one short and the driver rejected it outright.
        $query = $this->query()->where(new HavingClause('score', null));

        $this->assertStringContainsString('`u`.`score` = ?', $query->getSQL());
        $this->assertSame([null], $query->getBinds());
    }

    #[Test]
    public function havingClauseRejectsAnInvalidOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HavingClause('score', 5))->operator('; DROP TABLE user');
    }

    // ---------------------------------------------------------------
    // OrderByClause
    // ---------------------------------------------------------------

    #[Test]
    public function orderByClauseWhitelistsTheDirectionInItsArrayForm(): void
    {
        // The array branch only uppercased its input, so a direction taken from
        // a `?sort=` parameter appended arbitrary SQL — the same defect already
        // fixed in ActiveQuery::orderBy(). The scalar branch was never affected.
        // A bare column name is stored deferred as `{id}` and qualified later,
        // so it is the direction that matters here.
        $clause = new OrderByClause(['id' => 'ASC, (SELECT password FROM user LIMIT 1)']);

        $this->assertSame('{id} ASC', (string)$clause);
    }

    #[Test]
    public function orderByClauseAcceptsValidDirectionsInEitherCase(): void
    {
        $this->assertSame('{id} DESC', (string)new OrderByClause(['id' => 'desc']));
        $this->assertSame('{id} ASC', (string)new OrderByClause(['id' => 'asc']));
        $this->assertSame('{id} DESC', (string)new OrderByClause('id', 'desc'));
    }

    #[Test]
    public function orderByClauseFallsBackToAscendingForAnythingElse(): void
    {
        $this->assertSame('{id} ASC', (string)new OrderByClause(['id' => 'sideways']));
        $this->assertSame('{id} ASC', (string)new OrderByClause('id', 'sideways'));
    }

    #[Test]
    public function orderByClauseOrdersSeveralColumns(): void
    {
        $clause = new OrderByClause(['id' => 'DESC', 'username' => 'ASC']);

        $this->assertSame('{id} DESC, {username} ASC', (string)$clause);
    }

    #[Test]
    public function orderByClauseQualifiesAnExplicitlyPrefixedColumn(): void
    {
        $this->assertSame('`u`.`id` DESC', (string)new OrderByClause('u.id', 'desc'));
    }

    #[Test]
    public function orderByClausePassesRandomThrough(): void
    {
        $this->assertSame('RAND()', (string)new OrderByClause('RAND()'));
    }
}
