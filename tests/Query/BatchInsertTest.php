<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Model;

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $email
 */
class BatchModel extends Model
{
    protected string $table = 'batch_users';
    protected string $connection = 'mysql';
}

class BatchInsertTest extends TestCase
{
    #[Test]
    public function insertBatchReturnsZeroForEmptyArray(): void
    {
        $result = BatchModel::insertBatch([]);
        $this->assertSame(0, $result);
    }
}
