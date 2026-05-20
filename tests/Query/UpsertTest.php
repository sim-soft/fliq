<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Upsert;

class UpsertTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            'UPSERT all columns' => [
                new Upsert('user', ['name' => 'John', 'email' => 'john@test.com', 'status' => 1], []),
                'INSERT INTO `user` (`name`, `email`, `status`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`), `status` = VALUES(`status`)',
                ['John', 'john@test.com', 1],
            ],
            'UPSERT specific columns' => [
                new Upsert('user', ['name' => 'John', 'email' => 'john@test.com', 'status' => 1], ['email', 'status']),
                'INSERT INTO `user` (`name`, `email`, `status`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `status` = VALUES(`status`)',
                ['John', 'john@test.com', 1],
            ],
        ];
    }

    /**
     * @param array<int, mixed> $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testUpsert(Upsert $query, string $expected, array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, $query->getSQL());
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
