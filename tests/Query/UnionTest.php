<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;

class UnionTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            'UNION' => [
                (new ActiveQuery())
                    ->from('user t')
                    ->select('first_name', 'last_name')
                    ->where('status', 'active')
                    ->union(
                        (new ActiveQuery())
                            ->from('user t')
                            ->select('first_name', 'last_name')
                            ->where('role', 'admin')
                    ),
                '(SELECT `t`.`first_name`, `t`.`last_name` FROM `user` `t` WHERE `t`.`status` = ?) UNION (SELECT `t`.`first_name`, `t`.`last_name` FROM `user` `t` WHERE `t`.`role` = ?)',
                ['active', 'admin'],
            ],
            'UNION ALL' => [
                (new ActiveQuery())
                    ->from('user')
                    ->select('name')
                    ->where('status', 1)
                    ->unionAll(
                        (new ActiveQuery())
                            ->from('user')
                            ->select('name')
                            ->where('status', 2)
                    ),
                '(SELECT `user`.`name` FROM `user` WHERE `user`.`status` = ?) UNION ALL (SELECT `user`.`name` FROM `user` WHERE `user`.`status` = ?)',
                [1, 2],
            ],
            'UNION DISTINCT' => [
                (new ActiveQuery())
                    ->from('user')
                    ->select('email')
                    ->where('age', '>', 18)
                    ->unionDistinct(
                        (new ActiveQuery())
                            ->from('user')
                            ->select('email')
                            ->where('age', '<', 65)
                    ),
                '(SELECT `user`.`email` FROM `user` WHERE `user`.`age` > ?) UNION DISTINCT (SELECT `user`.`email` FROM `user` WHERE `user`.`age` < ?)',
                [18, 65],
            ],
            'MULTIPLE UNIONS' => [
                (new ActiveQuery())
                    ->from('user')
                    ->select('name')
                    ->where('role', 'admin')
                    ->union(
                        (new ActiveQuery())
                            ->from('user')
                            ->select('name')
                            ->where('role', 'editor')
                    )
                    ->unionAll(
                        (new ActiveQuery())
                            ->from('user')
                            ->select('name')
                            ->where('role', 'author')
                    ),
                '(SELECT `user`.`name` FROM `user` WHERE `user`.`role` = ?) UNION (SELECT `user`.`name` FROM `user` WHERE `user`.`role` = ?) UNION ALL (SELECT `user`.`name` FROM `user` WHERE `user`.`role` = ?)',
                ['admin', 'editor', 'author'],
            ],
        ];
    }

    /**
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testUnion(ActiveQuery $query, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, (string)$query);
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
