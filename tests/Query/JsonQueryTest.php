<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;

class JsonQueryTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            'whereJson simple path' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereJson('meta->age', '>', 25),
                "SELECT `user`.* FROM `user` WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.age')) > ?",
                [25],
            ],
            'whereJson nested path' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereJson('meta->address.city', '=', 'KL'),
                "SELECT `user`.* FROM `user` WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.address.city')) = ?",
                ['KL'],
            ],
            'whereJsonContains' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereJsonContains('meta->tags', 'php'),
                "SELECT `user`.* FROM `user` WHERE JSON_CONTAINS(`user`.`meta`, ?, '$.tags')",
                ['"php"'],
            ],
            'whereJsonLength' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereJsonLength('meta->tags', '>', 3),
                "SELECT `user`.* FROM `user` WHERE JSON_LENGTH(`user`.`meta`, '$.tags') > ?",
                [3],
            ],
            'whereJsonValue shorthand' => [
                (new ActiveQuery())
                    ->from('user')
                    ->whereJsonValue('settings->theme', 'dark'),
                "SELECT `user`.* FROM `user` WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`settings`, '$.theme')) = ?",
                ['dark'],
            ],
        ];
    }

    /**
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testJsonQuery(ActiveQuery $query, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, (string)$query);
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
