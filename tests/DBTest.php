<?php

use PHPUnit\Framework\TestCase;
use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\DB;
use Simsoft\DB\MySQL\Interfaces\Executable;

class DBTest extends TestCase
{
    public function dataProvider(): array
    {
        DB::sqlOnly();

        return [
            'DB INSERT' => [
                DB::insert('user', ['name' => 'John', 'status' => 1]),
                'INSERT INTO `user` (`name`, `status`) VALUES (?,?)',
                ['John', 1]
            ],
            /*'DB INSERT MULTIPLE' => [
                DB::insert('user', [
                    ['name' => 'John', 'status' => 1],
                    ['name' => 'John2', 'email' => 'yes@yes.com', 'status' => 2],
                    ['name' => 'John3', 'status' => 3]
                ]),
                'INSERT INTO `user` (`name`, `status`, `email`) VALUES (?,?,?), (?,?,?), (?,?,?)'
                , ['John', 1, null, 'John2', 2, 'yes@yes.com', 'John3', 3, null]
            ],*/
            'DB INSERT IGNORE' => [
                DB::insertOrIgnore('user', ['name' => 'John', 'status' => 1])
                , 'INSERT IGNORE INTO `user` (`name`, `status`) VALUES (?,?)'
                , ['John', 1]
            ],

            'DB UPDATE' => [
                DB::update(
                    'user',
                    ['name' => 'John', 'status' => 1],
                    (new ActiveQuery())->where('status', 0)
                ),
                'UPDATE `user` SET `name` = ?, `status` = ? WHERE `status` = ?',
                ['John', 1, 0]
            ],
            'DB UPDATE IGNORE' => [
                DB::updateIgnore(
                    'user',
                    ['name' => 'John', 'status' => 1],
                    (new ActiveQuery())->where('status', 0)
                ),
                'UPDATE IGNORE `user` SET `name` = ?, `status` = ? WHERE `status` = ?',
                ['John', 1, 0]
            ],
            'DB UPDATE LOW_PRIORITY IGNORE' => [
                DB::updateLowPriorityIgnore(
                    'user',
                    ['name' => 'John', 'status' => 1],
                    (new ActiveQuery())->where('status', 0)
                ),
                'UPDATE LOW_PRIORITY IGNORE `user` SET `name` = ?, `status` = ? WHERE `status` = ?',
                ['John', 1, 0]
            ],
            'DB UPDATE WITH QUERY' => [
                DB::update(
                    'user',
                    ['name' => 'John', 'status' => 1],
                    (new ActiveQuery())
                        ->where('status', 0)
                        ->orWhere('status', 9)
                        ->where(function ($q) {
                            $q->where('gender', 'f')
                                ->orWhere('gender', 'm');
                        })
                        ->orderBy('status')
                        ->limit(50)
                )
                , 'UPDATE `user` SET `name` = ?, `status` = ? WHERE `status` = ? OR `status` = ? AND ( `gender` = ? OR `gender` = ? ) ORDER BY `status` ASC limit 50'
                , ['John', 1, 0, 9, 'f', 'm']
            ],

            'DB DELETE' => [
                DB::delete(
                    'user',
                    (new ActiveQuery())->where('status', 1)
                ), 'DELETE FROM `user` WHERE `status` = ?', [1]
            ],
            'DB DELETE IGNORE' => [
                DB::deleteIgnore(
                    'user',
                    (new ActiveQuery())->where('status', 1)
                )
                , 'DELETE IGNORE FROM `user` WHERE `status` = ?', [1]
            ],
            'DB QUICK DELETE' => [
                DB::deleteQuick(
                    'user',
                    (new ActiveQuery())->where('status', 1)
                ),
                'DELETE QUICK FROM `user` WHERE `status` = ?',
                [1]
            ],
            'DB QUICK IGNORE DELETE' => [
                DB::deleteQuickIgnore(
                    'user',
                    (new ActiveQuery())->where('status', 1)
                ),
                'DELETE QUICK IGNORE FROM `user` WHERE `status` = ?',
                [1]
            ],
            'DB LOWPRIORITY QUICK IGNORE DELETE' => [
                DB::deleteLowPriorityQuickIgnore(
                    'user',
                    (new ActiveQuery())->where('status', 1)),
                'DELETE LOW_PRIORITY QUICK IGNORE FROM `user` WHERE `status` = ?',
                [1]
            ],
            'DB DELETE WITH QUERY' => [
                DB::delete(
                    'user',
                    (new ActiveQuery())
                        ->where('status', 0)
                        ->orWhere('status', 9)
                        ->where(function ($q) {
                            $q->where('gender', 'f')
                                ->orWhere('gender', 'm');
                        })
                        ->orderBy('status')
                        ->limit(50)
                ),
                'DELETE FROM `user` WHERE `status` = ? OR `status` = ? AND ( `gender` = ? OR `gender` = ? ) ORDER BY `status` ASC LIMIT 50',
                [0, 9, 'f', 'm']
            ],
        ];
    }

    /**
     * @dataProvider dataProvider
     */
    public function testRaw(Executable $query, string $expected, array $expectedBinds)
    {
        $this->assertEqualsIgnoringCase($expected, $query->getSQL());
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
