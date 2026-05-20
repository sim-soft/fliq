<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * Unit tests for whereLike, whereNotLike, orWhereColumn, and cursorPaginate SQL generation.
 */
class WhereLikeTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            'whereLike case-insensitive (default)' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereLike('name', '%john%'),
                'SELECT `user`.* FROM `user` WHERE LOWER(`user`.`name`) LIKE LOWER(?)',
                ['%john%'],
            ],
            'whereLike case-sensitive' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereLike('name', '%John%', caseSensitive: true),
                'SELECT `user`.* FROM `user` WHERE `user`.`name` LIKE ?',
                ['%John%'],
            ],
            'whereNotLike case-insensitive (default)' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereNotLike('name', '%spam%'),
                'SELECT `user`.* FROM `user` WHERE LOWER(`user`.`name`) NOT LIKE LOWER(?)',
                ['%spam%'],
            ],
            'whereNotLike case-sensitive' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereNotLike('name', '%Spam%', caseSensitive: true),
                'SELECT `user`.* FROM `user` WHERE `user`.`name` NOT LIKE ?',
                ['%Spam%'],
            ],
            'orWhereLike' => [
                (new ActiveQuery())
                    ->from('user')
                    ->where('status', 1)
                    ->orWhereLike('name', '%john%'),
                'SELECT `user`.* FROM `user` WHERE `user`.`status` = ? OR LOWER(`user`.`name`) LIKE LOWER(?)',
                [1, '%john%'],
            ],
            'orWhereNotLike' => [
                (new ActiveQuery())
                    ->from('user')
                    ->where('status', 1)
                    ->orWhereNotLike('email', '%spam%'),
                'SELECT `user`.* FROM `user` WHERE `user`.`status` = ? OR LOWER(`user`.`email`) NOT LIKE LOWER(?)',
                [1, '%spam%'],
            ],
            'orWhereColumn' => [
                (new ActiveQuery())
                    ->from('user')
                    ->where('status', 1)
                    ->orWhereColumn('updated_at', '>', 'created_at'),
                'SELECT `user`.* FROM `user` WHERE `user`.`status` = ? OR `user`.`updated_at` > `user`.`created_at`',
                [1],
            ],
            'whereLike combined with other conditions' => [
                (new ActiveQuery())
                    ->from('user')
                    ->where('status', 1)
                    ->whereLike('name', '%john%')
                    ->whereNotLike('email', '%spam%'),
                'SELECT `user`.* FROM `user` WHERE `user`.`status` = ? AND LOWER(`user`.`name`) LIKE LOWER(?) AND LOWER(`user`.`email`) NOT LIKE LOWER(?)',
                [1, '%john%', '%spam%'],
            ],
        ];
    }

    /**
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    #[Test]
    public function querySqlGeneration(ActiveQuery $query, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, (string)$query);
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
