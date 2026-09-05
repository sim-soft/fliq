<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

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

    #[Test]
    public function dbTransactionCommits(): void
    {
        $result = DB::transaction('test', function () {
            Connection::get('test')->execute(new \Simsoft\DB\Builder\Raw(
                'INSERT INTO test_tx (id, name) VALUES (?, ?)',
                [1, 'db_committed']
            ));
            return true;
        });

        $this->assertTrue($result);

        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT * FROM test_tx'));
        $this->assertCount(1, $rows);
        $this->assertSame('db_committed', $rows[0]['name']);
    }

    #[Test]
    public function dbTransactionRollsBack(): void
    {
        $result = DB::transaction('test', function () {
            Connection::get('test')->execute(new \Simsoft\DB\Builder\Raw(
                'INSERT INTO test_tx (id, name) VALUES (?, ?)',
                [1, 'db_rolled_back']
            ));
            return false;
        });

        $this->assertFalse($result);

        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT * FROM test_tx'));
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function dbTransactionThrowsOnException(): void
    {
        $this->expectException(\Simsoft\DB\Exceptions\QueryException::class);

        DB::transaction('test', function () {
            throw new \RuntimeException('db error');
        });
    }

    /**
     * Insert a row inside the current transaction.
     */
    private function insert(int $id, string $name): void
    {
        $this->driver->execute(new \Simsoft\DB\Builder\Raw(
            'INSERT INTO test_tx (id, name) VALUES (?, ?)',
            [$id, $name]
        ));
    }

    /**
     * Get the names currently visible in the table, sorted.
     *
     * @return array<int, string>
     */
    private function names(): array
    {
        $rows = $this->driver->query(new \Simsoft\DB\Builder\Raw('SELECT name FROM test_tx ORDER BY id'));

        return array_map(static fn(array $row): string => (string)$row['name'], $rows);
    }

    #[Test]
    public function outerRollbackAlsoDiscardsInnerCommittedWork(): void
    {
        // Without savepoints the inner call committed the outer's work too, so
        // the outer rollback had nothing left to undo and both rows survived.
        $result = $this->driver->transaction(function () {
            $this->insert(1, 'outer');

            $inner = $this->driver->transaction(function () {
                $this->insert(2, 'inner');
                return true;
            });
            $this->assertTrue($inner);

            return false;
        });

        $this->assertFalse($result);
        $this->assertSame([], $this->names());
    }

    #[Test]
    public function innerRollbackKeepsOuterWork(): void
    {
        $result = $this->driver->transaction(function () {
            $this->insert(1, 'outer');

            $inner = $this->driver->transaction(function () {
                $this->insert(2, 'inner');
                return false;
            });
            $this->assertFalse($inner);

            return true;
        });

        $this->assertTrue($result);

        // Only the inner savepoint was rolled back.
        $this->assertSame(['outer'], $this->names());
    }

    #[Test]
    public function nestedTransactionsCommitTogether(): void
    {
        $result = $this->driver->transaction(function () {
            $this->insert(1, 'outer');

            return $this->driver->transaction(function () {
                $this->insert(2, 'inner');
                return true;
            });
        });

        $this->assertTrue($result);
        $this->assertSame(['outer', 'inner'], $this->names());
    }

    #[Test]
    public function exceptionInsideNestedTransactionRollsBackEverything(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nested boom');

        try {
            $this->driver->transaction(function () {
                $this->insert(1, 'outer');

                $this->driver->transaction(function (): bool {
                    throw new \RuntimeException('nested boom');
                });

                return true;
            });
        } finally {
            $this->assertSame([], $this->names());
            $this->assertSame(0, $this->driver->getTransactionLevel());
        }
    }

    #[Test]
    public function nestingIsSupportedBeyondTwoLevels(): void
    {
        $result = $this->driver->transaction(function () {
            $this->insert(1, 'level1');

            return $this->driver->transaction(function () {
                $this->insert(2, 'level2');

                // The deepest level rolls back on its own.
                $this->driver->transaction(function () {
                    $this->insert(3, 'level3');
                    return false;
                });

                return true;
            });
        });

        $this->assertTrue($result);
        $this->assertSame(['level1', 'level2'], $this->names());
    }

    #[Test]
    public function transactionLevelTracksNestingDepth(): void
    {
        $this->assertSame(0, $this->driver->getTransactionLevel());

        $this->driver->transaction(function () {
            $this->assertSame(1, $this->driver->getTransactionLevel());

            $this->driver->transaction(function () {
                $this->assertSame(2, $this->driver->getTransactionLevel());
                return true;
            });

            // Releasing the savepoint returns to the outer level.
            $this->assertSame(1, $this->driver->getTransactionLevel());

            return true;
        });

        $this->assertSame(0, $this->driver->getTransactionLevel());
    }

    #[Test]
    public function transactionLevelIsRestoredAfterAFailedTransaction(): void
    {
        try {
            $this->driver->transaction(function (): bool {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // A later transaction must not be mistaken for a nested one.
        $this->assertSame(0, $this->driver->getTransactionLevel());

        $this->driver->transaction(function () {
            $this->insert(1, 'after_failure');
            return true;
        });

        $this->assertSame(['after_failure'], $this->names());
    }
}
