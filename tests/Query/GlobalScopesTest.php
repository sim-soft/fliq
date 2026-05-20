<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Model;

/**
 * @property int|null $id
 * @property string|null $name
 * @property int|null $status
 */
class ScopedModel extends Model
{
    protected string $table = 'scoped_items';
    protected string $connection = 'mysql';
}

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $deleted_at
 */
class ScopedSoftDeleteModel extends Model
{
    use \Simsoft\DB\Traits\SoftDeletes;

    protected string $table = 'soft_items';
    protected string $connection = 'mysql';
}

class GlobalScopesTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any previously registered scopes
        ScopedModel::removeGlobalScope('active');
        ScopedModel::removeGlobalScope('ordered');
        ScopedSoftDeleteModel::removeGlobalScope('active');
    }

    protected function tearDown(): void
    {
        ScopedModel::removeGlobalScope('active');
        ScopedModel::removeGlobalScope('ordered');
        ScopedSoftDeleteModel::removeGlobalScope('active');
    }

    #[Test]
    public function addGlobalScopeAppliesOnFind(): void
    {
        ScopedModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        $query = ScopedModel::find();
        $sql = (string)$query;

        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('`status` = ?', $sql);
        $this->assertSame([1], $query->getBinds());
    }

    #[Test]
    public function multipleGlobalScopesApply(): void
    {
        ScopedModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        ScopedModel::addGlobalScope('ordered', function (ActiveQuery $query): void {
            $query->orderBy('name');
        });

        $query = ScopedModel::find();
        $sql = (string)$query;

        $this->assertStringContainsString('`status` = ?', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
    }

    #[Test]
    public function removeGlobalScopeWorks(): void
    {
        ScopedModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        ScopedModel::removeGlobalScope('active');

        $query = ScopedModel::find();
        $sql = (string)$query;

        $this->assertStringNotContainsString('WHERE', $sql);
    }

    #[Test]
    public function withoutGlobalScopesReturnsCleanQuery(): void
    {
        ScopedModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        $query = ScopedModel::withoutGlobalScopes();
        $sql = (string)$query;

        $this->assertStringNotContainsString('WHERE', $sql);
        $this->assertStringContainsString('`scoped_items`', $sql);
    }

    #[Test]
    public function withoutGlobalScopeExcludesSpecificScope(): void
    {
        ScopedModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        ScopedModel::addGlobalScope('ordered', function (ActiveQuery $query): void {
            $query->orderBy('name');
        });

        $query = ScopedModel::withoutGlobalScope('active');
        $sql = (string)$query;

        $this->assertStringNotContainsString('`status`', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
    }

    #[Test]
    public function globalScopesWorkWithSoftDeletes(): void
    {
        ScopedSoftDeleteModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        $query = ScopedSoftDeleteModel::find();
        $sql = (string)$query;

        // Should have both soft delete scope and global scope
        $this->assertStringContainsString('`deleted_at` IS NULL', $sql);
        $this->assertStringContainsString('`status` = ?', $sql);
    }

    #[Test]
    public function globalScopesArePerModel(): void
    {
        ScopedModel::addGlobalScope('active', function (ActiveQuery $query): void {
            $query->where('status', 1);
        });

        // ScopedSoftDeleteModel should NOT have the 'active' scope from ScopedModel
        $query = ScopedSoftDeleteModel::find();
        $sql = (string)$query;

        // Only soft delete scope, not the 'active' scope from ScopedModel
        $this->assertStringContainsString('`deleted_at` IS NULL', $sql);
        $this->assertNull($query->getBinds());
    }
}
