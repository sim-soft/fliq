<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Select;

/**
 * Tests Select builder condition handling.
 */
class SelectBuilderTest extends TestCase
{
    #[Test]
    public function conditionWithActiveQuery(): void
    {
        $condition = (new ActiveQuery())->where('status', 1)->orderBy('name');
        $select = new Select('user', ['name', 'email'], $condition);

        $sql = $select->getSQL();
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
    }

    #[Test]
    public function conditionWithRaw(): void
    {
        $raw = new Raw('status = ? AND role = ?', [1, 'admin']);
        $select = new Select('user', ['*'], $raw);

        $sql = $select->getSQL();
        $this->assertStringContainsString('WHERE status = ? AND role = ?', $sql);
        $this->assertEquals([1, 'admin'], $select->getBinds());
    }

    #[Test]
    public function conditionWithEmptyStringDoesNotAddWhere(): void
    {
        $select = new Select('user', ['*'], '');

        $sql = $select->getSQL();
        $this->assertStringNotContainsString('WHERE', $sql);
    }

    #[Test]
    public function conditionWithStringPrependsWhere(): void
    {
        $select = new Select('user', ['*'], 'status = 1');

        $sql = $select->getSQL();
        $this->assertStringContainsString('WHERE status = 1', $sql);
    }

    #[Test]
    public function conditionWithWhereStringDoesNotDoubleWhere(): void
    {
        $select = new Select('user', ['*'], 'WHERE status = 1');

        $sql = $select->getSQL();
        // Should NOT produce "WHERE WHERE status = 1"
        $this->assertStringNotContainsString('WHERE WHERE', $sql);
        $this->assertStringContainsString('WHERE status = 1', $sql);
    }

    #[Test]
    public function distinctSelect(): void
    {
        $select = new Select('user', ['name', 'email']);
        $select->distinct();

        $sql = $select->getSQL();
        $this->assertStringContainsString('SELECT DISTINCT', $sql);
    }
}
