<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\Driver;

/**
 * Tests transaction behavior including rollback on exception.
 */
class TransactionTest extends TestCase
{
    private \Simsoft\DB\Drivers\Driver $driver;

    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->driver = Connection::get('test');
        $this->driver->execute(new \Simsoft\DB\Builder\Raw(
            'CREATE TABLE test_tx (id INTEGER PRIMARY KEY, name TEXT)'
        ));
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function transactionCommitsOnTrue(): void
    {
        $result = $this->driver->transaction(function () {
            $this->driver->execute(new \Simsoft\DB\Builder\Raw(
                'INSERT INTO test_tx (id, name) VALUES (?, ?)',
                [1, 'committed']
            ));
            return true;
        });

        $this->assertTrue($result);

        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT * FROM test_tx'));
        $this->assertCount(1, $rows);
        $this->assertSame('committed', $rows[0]['name']);
    }

    #[Test]
    public function transactionRollsBackOnFalse(): void
    {
        $result = $this->driver->transaction(function () {
            $this->driver->execute(new \Simsoft\DB\Builder\Raw(
                'INSERT INTO test_tx (id, name) VALUES (?, ?)',
                [1, 'rolled_back']
            ));
            return false;
        });

        $this->assertFalse($result);

        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT * FROM test_tx'));
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function transactionRollsBackOnException(): void
    {
        $exceptionCaught = false;

        try {
            $this->driver->transaction(function () {
                $this->driver->execute(new \Simsoft\DB\Builder\Raw(
                    'INSERT INTO test_tx (id, name) VALUES (?, ?)',
                    [1, 'should_rollback']
                ));
                throw new \RuntimeException('Something went wrong');
            });
        } catch (\RuntimeException $e) {
            $exceptionCaught = true;
            $this->assertSame('Something went wrong', $e->getMessage());
        }

        $this->assertTrue($exceptionCaught);

        // Data should be rolled back
        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT * FROM test_tx'));
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function transactionRethrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $this->driver->transaction(function () {
            throw new \RuntimeException('test error');
        });
    }

    #[Test]
    public function transactionRollsBackOnNonTrueReturn(): void
    {
        // Returning null (not explicitly true) should rollback
        $result = $this->driver->transaction(function () {
            $this->driver->execute(new \Simsoft\DB\Builder\Raw(
                'INSERT INTO test_tx (id, name) VALUES (?, ?)',
                [1, 'null_return']
            ));
            // no return statement = null
        });

        $this->assertFalse($result);

        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT * FROM test_tx'));
        $this->assertCount(0, $rows);
    }
}
