<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;

class RawTest extends TestCase
{
    /** @return array<int, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            [new Raw('SELECT * FROM users'), 'SELECT * FROM users', null],
            [new Raw('SELECT * FROM users WHERE status = ?', [1]), 'SELECT * FROM users WHERE status = ?', [1]],
            [new Raw('INSERT INTO users (name, status) VALUES (?, ?)', ['John', 1]), 'INSERT INTO users (name, status) VALUES (?, ?)', ['John', 1]],
            [new Raw('UPDATE users SET name = ?, status = ?', ['John', 1]), 'UPDATE users SET name = ?, status = ?', ['John', 1]],
            [new Raw('DELETE FROM users WHERE name = ? AND status = ?', ['John', 1]), 'DELETE FROM users WHERE name = ? AND status = ?', ['John', 1]],
        ];
    }

    /**
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testRaw(Raw $q, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, $q->getSQL());
        $this->assertEqualsIgnoringCase($expected, $q);
        $this->assertEquals($expectedBinds, $q->getBinds());
    }
}
