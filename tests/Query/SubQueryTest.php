<?php

namespace Query;

use PHPUnit\Framework\TestCase;
use Simsoft\DB\MySQL\Builder\{Raw};
use Simsoft\DB\MySQL\Builder\ActiveQuery;

class SubQueryTest extends TestCase
{
    public function dataProvider(): array
    {
        return [
            'QUERY FROM ActiveQuery TABLE' => [(new ActiveQuery())
                ->select('first_name', 'last_name', 'email')
                ->from(['u' => (new ActiveQuery())
                    ->from('user t')
                    ->select('first_name', 'last_name', 'age')
                    ->where('id', '>', 10)
                ])
                ->where('age', '>', 20)
                , 'SELECT `u`.`first_name`, `u`.`last_name`, `u`.`email` FROM (SELECT `t`.`first_name`, `t`.`last_name`, `t`.`age` FROM `user` `t` WHERE `t`.`id` > ?) `u` WHERE `u`.`age` > ?'
                , [10, 20]
            ],
            'QUERY FROM Raw QUERY TABLE' => [(new ActiveQuery())
                ->select('first_name', 'last_name', 'email')
                ->from(['u' => (new Raw('SELECT t.first_name, t.last_name, t.age FROM user t WHERE t.id > ?', [10]))])
                ->where('age', '>', 20)
                , 'SELECT `u`.`first_name`, `u`.`last_name`, `u`.`email` FROM (SELECT t.first_name, t.last_name, t.age FROM user t WHERE t.id > ?) `u` WHERE `u`.`age` > ?'
                , [10, 20]
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
