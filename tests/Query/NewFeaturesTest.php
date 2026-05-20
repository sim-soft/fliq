<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

class NewFeaturesTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            'whereColumn' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereColumn('updated_at', '>', 'created_at'),
                'SELECT `user`.* FROM `user` WHERE `user`.`updated_at` > `user`.`created_at`',
                null,
            ],
            'selectRaw' => [
                (new ActiveQuery())
                    ->from('user')
                    ->select('name')
                    ->selectRaw('COUNT(*) AS total'),
                'SELECT `user`.`name`, COUNT(*) AS total FROM `user`',
                null,
            ],
            'orderByRaw' => [
                (new ActiveQuery())
                    ->from('user')
                    ->orderByRaw('FIELD(status, 3, 1, 2)'),
                'SELECT `user`.* FROM `user` ORDER BY FIELD(status, 3, 1, 2)',
                null,
            ],
            'groupByRaw' => [
                (new ActiveQuery())
                    ->from('user')
                    ->selectRaw('YEAR(created_at) AS year, COUNT(*) AS total')
                    ->groupByRaw('YEAR(created_at)'),
                'SELECT YEAR(created_at) AS year, COUNT(*) AS total FROM `user` GROUP BY YEAR(created_at)',
                null,
            ],
            'groupBy with Raw' => [
                (new ActiveQuery())
                    ->from('user')
                    ->select('status')
                    ->groupBy('status', new Raw('YEAR(created_at)')),
                'SELECT `user`.`status` FROM `user` GROUP BY `user`.`status`, YEAR(created_at)',
                null,
            ],
            'havingRaw' => [
                (new ActiveQuery())
                    ->from('user')
                    ->select('department')
                    ->selectRaw('COUNT(*) AS cnt')
                    ->groupBy('department')
                    ->havingRaw('COUNT(*) > ?', [5]),
                'SELECT `user`.`department`, COUNT(*) AS cnt FROM `user` GROUP BY `user`.`department` HAVING COUNT(*) > ?',
                [5],
            ],
            'scope' => [
                (new ActiveQuery())
                    ->from('user')
                    ->scope(fn($q) => $q->where('status', 'active'))
                    ->scope(fn($q) => $q->where('age', '>', 18)),
                'SELECT `user`.* FROM `user` WHERE `user`.`status` = ? AND `user`.`age` > ?',
                ['active', 18],
            ],
            'when true' => [
                (new ActiveQuery())
                    ->from('user')
                    ->when(true, fn($q) => $q->where('status', 1)),
                'SELECT `user`.* FROM `user` WHERE `user`.`status` = ?',
                [1],
            ],
            'when false' => [
                (new ActiveQuery())
                    ->from('user')
                    ->when(false, fn($q) => $q->where('status', 1)),
                'SELECT `user`.* FROM `user`',
                null,
            ],
            'when with otherwise' => [
                (new ActiveQuery())
                    ->from('user')
                    ->when(false, fn($q) => $q->orderBy('name'), fn($q) => $q->orderBy('id')),
                'SELECT `user`.* FROM `user` ORDER BY `user`.`id` ASC',
                null,
            ],
            'unless true (skipped)' => [
                (new ActiveQuery())
                    ->from('user')
                    ->unless(true, fn($q) => $q->where('published', 1)),
                'SELECT `user`.* FROM `user`',
                null,
            ],
            'unless false (applied)' => [
                (new ActiveQuery())
                    ->from('user')
                    ->unless(false, fn($q) => $q->where('published', 1)),
                'SELECT `user`.* FROM `user` WHERE `user`.`published` = ?',
                [1],
            ],
            'dot notation in where' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('profile p', ['user_id' => 'id'])
                    ->where('profile.verified', true),
                'SELECT `u`.* FROM `user` `u` INNER JOIN `profile` AS `p` ON `p`.`user_id` = `u`.`id` WHERE `profile`.`verified` = ?',
                [true],
            ],
            'where null auto-detection' => [
                (new ActiveQuery())
                    ->from('user')
                    ->where('deleted_at', null),
                'SELECT `user`.* FROM `user` WHERE `user`.`deleted_at` IS NULL',
                null,
            ],
            'where != null auto-detection' => [
                (new ActiveQuery())
                    ->from('user')
                    ->where('email', '!=', null),
                'SELECT `user`.* FROM `user` WHERE `user`.`email` IS NOT NULL',
                null,
            ],
        ];
    }

    /**
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testFeature(ActiveQuery $query, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, (string)$query);
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
