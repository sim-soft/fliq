<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\ConnectionException;

class ConnectionSplitTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function getThrowsExceptionForMissingConnection(): void
    {
        $this->expectException(ConnectionException::class);
        Connection::get('nonexistent');
    }

    #[Test]
    public function getWithSplitConfigCreatesReadConnection(): void
    {
        Connection::add('split_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
        ]);

        $readDriver = Connection::get('split_test', 'read');
        $writeDriver = Connection::get('split_test', 'write');

        $this->assertInstanceOf(\Simsoft\DB\Drivers\Driver::class, $readDriver);
        $this->assertInstanceOf(\Simsoft\DB\Drivers\Driver::class, $writeDriver);
    }

    #[Test]
    public function getWithoutSplitConfigReturnsSameDriver(): void
    {
        Connection::add('no_split', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $readDriver = Connection::get('no_split', 'read');
        $writeDriver = Connection::get('no_split', 'write');

        // Without split config, both should return the same driver instance
        $this->assertSame($readDriver, $writeDriver);
    }

    #[Test]
    public function getReturnsCachedDriverOnSubsequentCalls(): void
    {
        Connection::add('cached_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
        ]);

        $first = Connection::get('cached_test', 'read');
        $second = Connection::get('cached_test', 'read');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function disconnectRemovesAllSplitConnections(): void
    {
        Connection::add('disc_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
        ]);

        // Create connections
        Connection::get('disc_test', 'read');
        Connection::get('disc_test', 'write');

        // Disconnect
        Connection::disconnect('disc_test');

        // Should create new instances after disconnect
        $newRead = Connection::get('disc_test', 'read');
        $this->assertInstanceOf(\Simsoft\DB\Drivers\Driver::class, $newRead);
    }

    #[Test]
    public function removeDeletesConfigAndConnections(): void
    {
        Connection::add('remove_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->assertTrue(Connection::has('remove_test'));

        Connection::remove('remove_test');

        $this->assertFalse(Connection::has('remove_test'));
    }

    #[Test]
    public function getWithPartialSplitConfigFallsBackToBase(): void
    {
        // Only read is configured, write should fall back to base
        Connection::add('partial_split', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
        ]);

        $writeDriver = Connection::get('partial_split', 'write');
        $this->assertInstanceOf(\Simsoft\DB\Drivers\Driver::class, $writeDriver);
    }

    #[Test]
    public function reconnectCreatesNewDriver(): void
    {
        Connection::add('reconnect_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $first = Connection::get('reconnect_test');
        $second = Connection::reconnect('reconnect_test');

        // After reconnect, should be a new instance
        $this->assertNotSame($first, $second);
    }
}
