<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\CaseExpression;
use Simsoft\DB\Connection;

/**
 * Unit tests for CaseExpression fluent builder.
 */
class CaseExpressionTest extends TestCase
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

    // ------------------------------------------------------------------
    // BASIC CASE WHEN
    // ------------------------------------------------------------------

    #[Test]
    public function singleWhenThen(): void
    {
        $case = CaseExpression::when('score', '>', 90)->then('A');

        $sql = $case->getSQL();
        $this->assertStringContainsString('CASE', $sql);
        $this->assertStringContainsString('WHEN', $sql);
        $this->assertStringContainsString('THEN ?', $sql);
        $this->assertStringContainsString('END', $sql);
        $this->assertEquals([90, 'A'], $case->getBinds());
    }

    #[Test]
    public function multipleWhenThen(): void
    {
        $case = CaseExpression::when('score', '>', 90)->then('A')
            ->andWhen('score', '>', 70)->then('B')
            ->andWhen('score', '>', 50)->then('C');

        $sql = $case->getSQL();
        $this->assertEquals(3, substr_count($sql, 'WHEN'));
        $this->assertEquals(3, substr_count($sql, 'THEN'));
        $this->assertEquals([90, 'A', 70, 'B', 50, 'C'], $case->getBinds());
    }

    #[Test]
    public function withElse(): void
    {
        $case = CaseExpression::when('score', '>', 90)->then('A')
            ->else('F');

        $sql = $case->getSQL();
        $this->assertStringContainsString('ELSE ?', $sql);
        $this->assertEquals([90, 'A', 'F'], $case->getBinds());
    }

    #[Test]
    public function withAlias(): void
    {
        $case = CaseExpression::when('score', '>', 90)->then('A')
            ->else('F')
            ->as('grade');

        $sql = $case->getSQL();
        $this->assertStringContainsString('END AS grade', $sql);
    }

    #[Test]
    public function withoutElse(): void
    {
        $case = CaseExpression::when('status', '=', 'active')->then(1);

        $sql = $case->getSQL();
        $this->assertStringNotContainsString('ELSE', $sql);
        $this->assertEquals(['active', 1], $case->getBinds());
    }

    // ------------------------------------------------------------------
    // COLUMN COMPARISON
    // ------------------------------------------------------------------

    #[Test]
    public function whenColumnSameTable(): void
    {
        $case = CaseExpression::whenColumn('score', '>', 'min_score')->then('pass')
            ->else('fail');

        $sql = $case->getSQL();
        $this->assertStringContainsString('WHEN {score} > {min_score} THEN ?', $sql);
        $this->assertEquals(['pass', 'fail'], $case->getBinds());
    }

    #[Test]
    public function whenColumnCrossTable(): void
    {
        $case = CaseExpression::whenColumn('order.total', '>', 'customer.credit_limit')
            ->then('over')
            ->else('ok');

        $sql = $case->getSQL();
        $this->assertStringContainsString('`order`.`total`', $sql);
        $this->assertStringContainsString('`customer`.`credit_limit`', $sql);
        $this->assertEquals(['over', 'ok'], $case->getBinds());
    }

    #[Test]
    public function andWhenColumn(): void
    {
        $case = CaseExpression::whenColumn('score', '>', 'max_score')->then('exceptional')
            ->andWhenColumn('score', '>', 'min_score')->then('pass')
            ->else('fail');

        $sql = $case->getSQL();
        $this->assertEquals(2, substr_count($sql, 'WHEN'));
        $this->assertEquals(['exceptional', 'pass', 'fail'], $case->getBinds());
    }

    // ------------------------------------------------------------------
    // RAW WHEN
    // ------------------------------------------------------------------

    #[Test]
    public function whenRawWithBinds(): void
    {
        $case = CaseExpression::whenRaw('age >= ? AND age < ?', [18, 30])->then('young')
            ->andWhenRaw('age >= ? AND age < ?', [30, 50])->then('middle')
            ->else('senior');

        $sql = $case->getSQL();
        $this->assertStringContainsString('WHEN age >= ? AND age < ? THEN ?', $sql);
        $this->assertEquals([18, 30, 'young', 30, 50, 'middle', 'senior'], $case->getBinds());
    }

    #[Test]
    public function whenRawNoBinds(): void
    {
        $case = CaseExpression::whenRaw('deleted_at IS NULL')->then('active')
            ->else('deleted');

        $sql = $case->getSQL();
        $this->assertStringContainsString('WHEN deleted_at IS NULL THEN ?', $sql);
        $this->assertEquals(['active', 'deleted'], $case->getBinds());
    }

    // ------------------------------------------------------------------
    // INTEGRATION WITH ACTIVEQUERY
    // ------------------------------------------------------------------

    #[Test]
    public function inSelect(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('mysql')
            ->select(
                'name',
                CaseExpression::when('score', '>', 90)->then('A')
                    ->andWhen('score', '>', 70)->then('B')
                    ->else('C')
                    ->as('grade')
            );

        $sql = $query->getSQL();
        $this->assertStringContainsString('CASE WHEN', $sql);
        $this->assertStringContainsString('END AS grade', $sql);
        $this->assertStringContainsString('`user`.`name`', $sql);
        $this->assertEquals([90, 'A', 70, 'B', 'C'], $query->getBinds());
    }

    #[Test]
    public function inWhere(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('mysql')
            ->where(CaseExpression::when('role', '=', 'admin')->then(1)->else(0), '=', 1);

        $sql = $query->getSQL();
        $this->assertStringContainsString('CASE WHEN', $sql);
    }

    #[Test]
    public function columnResolutionWithAlias(): void
    {
        // When used inside ActiveQuery, alias is applied and {attr} is resolved
        $query = (new ActiveQuery())
            ->from('user u')
            ->withConnection('mysql')
            ->select(
                CaseExpression::when('score', '>', 90)->then('A')
                    ->else('F')
                    ->as('grade')
            );

        $sql = $query->getSQL();
        $this->assertStringContainsString('`u`.`score`', $sql);
        $this->assertStringContainsString('END AS grade', $sql);
    }

    // ------------------------------------------------------------------
    // MIXED WHEN TYPES
    // ------------------------------------------------------------------

    #[Test]
    public function mixedWhenAndWhenColumn(): void
    {
        $case = CaseExpression::when('status', '=', 'vip')->then('premium')
            ->andWhenColumn('score', '>', 'threshold')->then('qualified')
            ->else('standard');

        $sql = $case->getSQL();
        $this->assertEquals(2, substr_count($sql, 'WHEN'));
        // First WHEN has 2 binds (value comparison + then), column comparison only has then
        $this->assertEquals(['vip', 'premium', 'qualified', 'standard'], $case->getBinds());
    }

    #[Test]
    public function mixedWhenAndWhenRaw(): void
    {
        $case = CaseExpression::when('age', '>=', 18)->then('adult')
            ->andWhenRaw('age < ?', [18])->then('minor');

        $sql = $case->getSQL();
        $this->assertEquals(2, substr_count($sql, 'WHEN'));
        $this->assertEquals([18, 'adult', 18, 'minor'], $case->getBinds());
    }

    // ------------------------------------------------------------------
    // NUMERIC VALUES
    // ------------------------------------------------------------------

    #[Test]
    public function numericThenValues(): void
    {
        $case = CaseExpression::when('role', '=', 'admin')->then(1)
            ->andWhen('role', '=', 'editor')->then(2)
            ->else(3);

        $case->getSQL();
        $binds = $case->getBinds() ?? [];
        $this->assertContains(1, $binds);
        $this->assertContains(2, $binds);
        $this->assertContains(3, $binds);
    }

    #[Test]
    public function nullElseValue(): void
    {
        $case = CaseExpression::when('status', '=', 'active')->then('yes')
            ->else(null);

        $case->getSQL();
        $binds = $case->getBinds() ?? [];
        $this->assertNull($binds[count($binds) - 1]);
    }
}
