<?php

namespace Query;

use PHPUnit\Framework\TestCase;
use Simsoft\DB\MySQL\Builder\{Raw};
use Simsoft\DB\MySQL\Builder\ActiveQuery;

class QueryTest extends TestCase
{
    public function dataProvider(): array
    {
        return [
            'SELECT ALL' => [(new ActiveQuery())->from('user'), 'SELECT `user`.* FROM `user`', null],
            'SELECT 2 ATTRIBUTES' => [
                (new ActiveQuery())->select('first_name', 'last_name', 'email')->from('user')
                , 'SELECT `user`.`first_name`, `user`.`last_name`, `user`.`email` FROM `user`'
                , null
            ],
            'SELECT ATTRIBUTES INCLUDES Raw ATTRIBUTES' => [
                (new ActiveQuery())
                    ->select('first_name', 'last_name', 'email', new Raw('{phone}, {address}'))
                    ->from('user')
                , 'SELECT `user`.`first_name`, `user`.`last_name`, `user`.`email`, `user`.`phone`, `user`.`address` FROM `user`'
                , null
            ],
            'SELECT DISTINCT' => [
                (new ActiveQuery())->selectDistinct('first_name', 'last_name', 'email')->from('user')
                , 'SELECT DISTINCT `user`.`first_name`, `user`.`last_name`, `user`.`email` FROM `user`'
                , null
            ],

            'SELECT DISTINCT WITH TABLE ALIAS' => [
                (new ActiveQuery())->selectDistinct('first_name', 'last_name', 'email')->from('user t')
                , 'SELECT DISTINCT `t`.`first_name`, `t`.`last_name`, `t`.`email` FROM `user` `t`'
                , null
            ],

            'SIMPLE WHERE' => [
                (new ActiveQuery())
                    ->select('first_name', 'last_name', 'email')
                    ->from('user')
                    ->where('status', 1)
                    ->where('age', '>', 25)
                , 'SELECT `user`.`first_name`, `user`.`last_name`, `user`.`email` FROM `user` '
                . 'WHERE `user`.`status` = ? AND `user`.`age` > ?'
                , [1, 25]
            ],

            'SIMPLE WHERE WITH TABLE ALIAS' => [
                (new ActiveQuery())
                    ->select('first_name', 'last_name', 'email')
                    ->from('user u')
                    ->where('status', 1)
                    ->where('age', '>', 25)
                , 'SELECT `u`.`first_name`, `u`.`last_name`, `u`.`email` FROM `user` `u` WHERE `u`.`status` = ? '
                . 'AND `u`.`age` > ?'
                , [1, 25]
            ],

            'SIMPLE WHERE WITH RAW' => [
                (new ActiveQuery())
                    ->select('first_name', 'last_name', 'email')
                    ->from('user u')
                    ->where('status', 1)
                    ->where(new Raw('{salary} >= ?', [3000]))
                    ->where('age', '>', 25)
                , 'SELECT `u`.`first_name`, `u`.`last_name`, `u`.`email` FROM `user` `u` WHERE `u`.`status` = ? '
                . 'AND `u`.`salary` >= ? AND `u`.`age` > ?'
                , [1, 3000, 25]
            ],

            'GROUP QUERY' => [
                (new ActiveQuery())
                    ->from('user t')
                    ->where('status', 1)
                    ->not('gender', 'male')
                    ->where([
                        ['height', '>=', 150],
                        ['weight', '<', 70],
                    ])
                    ->where(new Raw('{salary} >= ?', [3000]))
                    ->where(function ($q) {
                        $q->where('age', '>', 18)
                            ->orWhere('age', '<=', 25);
                    })
                , 'SELECT `t`.* FROM `user` `t` WHERE `t`.`status` = ? AND `t`.`gender` != ? AND `t`.`height` >= ? '
                . 'AND `t`.`weight` < ? AND `t`.`salary` >= ? AND ( `t`.`age` > ? OR `t`.`age` <= ? )'
                , [1, 'male', 150, 70, 3000, 18, 25]
            ],

            'NULL METHODS' => [
                (new ActiveQuery())->from('user t')
                    ->isNull('last_name')
                    ->orNotNull('email')
                , 'SELECT `t`.* FROM `user` `t` WHERE `t`.`last_name` IS NULL OR `t`.`email` IS NOT NULL'
                , null
            ],

            'LIKE METHOD' => [
                (new ActiveQuery())->from('user t')
                    ->like('name', '%john%')
                    ->where(function ($q) {
                        $q
                            ->notLike('name', '%Jane%')
                            ->orNotLike('name', '%Simon%');
                    })
                , 'SELECT `t`.* FROM `user` `t` WHERE `t`.`name` LIKE ? AND ( `t`.`name` NOT LIKE ? OR `t`.`name` NOT LIKE ? )'
                , ['%john%', '%Jane%', '%Simon%']
            ],

            'BETWEEN METHOD' => [
                (new ActiveQuery())->from('user t')
                    ->between('height', 150, 200)
                    ->orNotBetween('birth_day', '1990-01-01', '1990-01-31')
                , 'SELECT `t`.* FROM `user` `t` WHERE `t`.`height` BETWEEN ? AND ? OR `t`.`birth_day` NOT BETWEEN ? AND ?'
                , [150, 200, '1990-01-01', '1990-01-31']
            ],

            'IN METHOD' => [
                (new ActiveQuery())->from('user')
                    ->in('role', [1, 2, 3])
                    ->notIn('status', [1, 2, 3, 4])
                , 'SELECT `user`.* FROM `user` WHERE `user`.`role` IN (?,?,?) AND `user`.`status` NOT IN (?,?,?,?)'
                , [1, 2, 3, 1, 2, 3, 4]
            ],

            // union
            /*'Union' => [(new ActiveQuery())
                ->union((new ActiveQuery())
                    ->from('user t')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                )
                ->union((new ActiveQuery())
                    ->from('user t')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                )
                ->like('first_name', '%abc%')
                ->orderBy('first_name')
                , '(SELECT `t`.`first_name`, `t`.`last_name`, `t`.`age` FROM `user` `t` WHERE `t`.`id` > ?) UNION (SELECT `t`.`first_name`, `t`.`last_name`, `t`.`age` FROM `user` `t` WHERE `t`.`id` > ?) WHERE `first_name` LIKE ? ORDER BY `first_name` ASC'
                , [10, 10, '%abc%']
            ],

            'Union all' => [(new ActiveQuery())
                ->unionAll((new ActiveQuery())
                    ->from('user u')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                )
                ->unionAll((new ActiveQuery())
                    ->from('user2 u2')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                )
                ->like('first_name', '%abc%')
                ->orderBy('first_name')
                , '(SELECT `u`.`first_name`, `u`.`last_name`, `u`.`age` FROM `user` `u` WHERE `u`.`id` > ?) UNION ALL (SELECT `u2`.`first_name`, `u2`.`last_name`, `u2`.`age` FROM `user2` `u2` WHERE `u2`.`id` > ?) WHERE `first_name` LIKE ? ORDER BY `first_name` ASC'
                , [10, 10, '%abc%']
            ],

            'Union distinct' => [(new ActiveQuery())
                ->unionDistinct((new ActiveQuery())
                    ->from('user u1')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                )
                ->unionDistinct((new ActiveQuery())
                    ->from('user2 u2')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                )
                ->like('first_name', '%abc%')
                ->orderBy('first_name')
                ,'(SELECT `u1`.`first_name`, `u1`.`last_name`, `u1`.`age` FROM `user` `u1` WHERE `u1`.`id` > ?) UNION DISTINCT (SELECT `u2`.`first_name`, `u2`.`last_name`, `u2`.`age` FROM `user2` `u2` WHERE `u2`.`id` > ?) WHERE `first_name` LIKE ? ORDER BY `first_name` ASC'
                , [10, 10, '%abc%']
            ],*/

        ];
    }

    /**
     * @dataProvider dataProvider
     */
    public function testRaw(ActiveQuery $query, string $expected, ?array $expectedBinds)
    {
        $this->assertEqualsIgnoringCase($expected, (string)$query);
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
