<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\{Raw};
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

class AggregateQueryTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
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
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testRaw(ActiveQuery $q, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, (string)$q);
        $this->assertEquals($expectedBinds, $q->getBinds());
    }

    private function setupDatabase(): void
    {
        Connection::reset();
        Connection::add('agg_test', ['driver' => 'sqlite', 'database' => ':memory:']);

        $driver = Connection::get('agg_test');
        $driver->execute(new Raw('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL, stock INTEGER)'));
        $driver->execute(new Raw('INSERT INTO products VALUES (1, "Apple", 1.50, 100)'));
        $driver->execute(new Raw('INSERT INTO products VALUES (2, "Banana", 0.75, 200)'));
        $driver->execute(new Raw('INSERT INTO products VALUES (3, "Cherry", 3.00, 50)'));
        $driver->execute(new Raw('INSERT INTO products VALUES (4, "Date", 5.00, 25)'));
        $driver->execute(new Raw('INSERT INTO products VALUES (5, "Elderberry", 8.50, 10)'));
    }

    #[Test]
    public function maxReturnsHighestValue(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->withConnection('agg_test');
        $this->assertEquals(5, $query->max('id'));
        $this->assertEquals(8.50, $query->max('price'));
        $this->assertEquals(200, $query->max('stock'));

        Connection::reset();
    }

    #[Test]
    public function minReturnsLowestValue(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->withConnection('agg_test');
        $this->assertEquals(1, $query->min('id'));
        $this->assertEquals(0.75, $query->min('price'));
        $this->assertEquals(10, $query->min('stock'));

        Connection::reset();
    }

    #[Test]
    public function sumReturnsTotalValue(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->withConnection('agg_test');
        $this->assertEquals(385, $query->sum('stock'));

        Connection::reset();
    }

    #[Test]
    public function avgReturnsAverageValue(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->withConnection('agg_test');
        $this->assertEquals(77, $query->avg('stock'));

        Connection::reset();
    }

    #[Test]
    public function countReturnsRowCount(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->withConnection('agg_test');
        $this->assertEquals(5, $query->count());

        Connection::reset();
    }

    #[Test]
    public function maxWithCondition(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->where('stock', '>', 30)->withConnection('agg_test');
        $this->assertEquals(3.00, $query->max('price'));

        Connection::reset();
    }

    #[Test]
    public function minWithCondition(): void
    {
        $this->setupDatabase();

        $query = (new ActiveQuery())->from('products')->where('price', '>', 2)->withConnection('agg_test');
        $this->assertEquals(3.00, $query->min('price'));

        Connection::reset();
    }
}
