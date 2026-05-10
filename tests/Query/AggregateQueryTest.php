<?php

namespace Query;

use PHPUnit\Framework\TestCase;
use Simsoft\DB\MySQL\Builder\{Raw};
use Simsoft\DB\MySQL\Builder\ActiveQuery;

class AggregateQueryTest extends TestCase
{
    public function dataProvider(): array
    {
        return [
            'Simple query' => [(new ActiveQuery())->from('user'), 'SELECT `user`.* FROM `user`', null],
            'SELECT method' => [
                (new ActiveQuery())->from('user')->select('first_name', 'last_name', 'email')
                , 'SELECT `user`.`first_name`, `user`.`last_name`, `user`.`email` FROM `user`'
                , null
            ],
            'SELECT Raw' => [
                (new ActiveQuery())->from('user')->select('first_name', 'last_name', 'email', new Raw('{phone}, {address}'))
                , 'SELECT `user`.`first_name`, `user`.`last_name`, `user`.`email`, `user`.`phone`, `user`.`address` FROM `user`'
                , null
            ],
            'SELECT distinct method' => [
                (new ActiveQuery())->selectDistinct('first_name', 'last_name', 'email')->from('user t')
                , 'SELECT DISTINCT `t`.`first_name`, `t`.`last_name`, `t`.`email` FROM `user` `t`'
                , null
            ],

            'WHERE method' => [
                (new ActiveQuery())->from('user u')
                    ->select('first_name', 'last_name', 'email')
                    ->where('status', 1)
                    ->where('age', '>', 25)
                , 'SELECT `u`.`first_name`, `u`.`last_name`, `u`.`email` FROM `user` `u` WHERE `u`.`status` = ? AND `u`.`age` > ?'
                , [1, 25]
            ],

            'WHERE group' => [
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
                , 'SELECT `t`.* FROM `user` `t` WHERE `t`.`status` = ? AND `t`.`gender` != ? AND `t`.`height` >= ? AND `t`.`weight` < ? AND `t`.`salary` >= ? AND ( `t`.`age` > ? OR `t`.`age` <= ? )'
                , [1, 'male', 150, 70, 3000, 18, 25]
            ],
        ];
    }

    /**
     * @dataProvider dataProvider
     */
    public function testRaw(ActiveQuery $q, string $expected, ?array $expectedBinds)
    {
        $this->assertEqualsIgnoringCase($expected, (string)$q);
        //$this->assertEqualsIgnoringCase($expected, $q);
        //var_dump($expectedBinds);
        //var_dump($q->getBindValues());
        $this->assertEquals($expectedBinds, $q->getBinds());
    }
}
