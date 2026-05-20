<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;

/**
 * Tests Connection::reset() behavior including default connection name.
 */
class ConnectionResetTest extends TestCase
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
    public function resetClearsAllConfigs(): void
    {
        Connection::add('test1', ['driver' => 'sqlite', 'database' => ':memory:']);
        Connection::add('test2', ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->assertTrue(Connection::has('test1'));
        $this->assertTrue(Connection::has('test2'));

        Connection::reset();

        $this->assertFalse(Connection::has('test1'));
        $this->assertFalse(Connection::has('test2'));
    }

    #[Test]
    public function resetResetsDefaultConnectionName(): void
    {
        Connection::setDefault('custom_connection');
        $this->assertSame('custom_connection', Connection::getDefaultName());

        Connection::reset();

        $this->assertSame('mysql', Connection::getDefaultName());
    }

    #[Test]
    public function setDefaultPersistsUntilReset(): void
    {
        Connection::setDefault('postgres');
        $this->assertSame('postgres', Connection::getDefaultName());

        Connection::setDefault('sqlite');
        $this->assertSame('sqlite', Connection::getDefaultName());
    }
}
