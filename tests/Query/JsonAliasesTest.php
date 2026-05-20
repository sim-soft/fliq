<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * Tests for JSON method short aliases and OR variants.
 */
class JsonAliasesTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    public static function dataProvider(): array
    {
        return [
            'jsonValue (alias of whereJsonValue)' => [
                (new ActiveQuery())->from('user')->jsonValue('meta->status', 'active'),
                "SELECT `user`.* FROM `user` WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.status')) = ?",
                ['active'],
            ],
            'orJsonValue' => [
                (new ActiveQuery())->from('user')
                    ->where('id', 1)
                    ->orJsonValue('meta->status', 'active'),
                "SELECT `user`.* FROM `user` WHERE `user`.`id` = ? OR JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.status')) = ?",
                [1, 'active'],
            ],
            'orWhereJsonValue' => [
                (new ActiveQuery())->from('user')
                    ->where('id', 1)
                    ->orWhereJsonValue('meta->status', 'active'),
                "SELECT `user`.* FROM `user` WHERE `user`.`id` = ? OR JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.status')) = ?",
                [1, 'active'],
            ],
            'jsonLength' => [
                (new ActiveQuery())->from('user')->jsonLength('meta->tags', '>', 3),
                "SELECT `user`.* FROM `user` WHERE JSON_LENGTH(`user`.`meta`, '$.tags') > ?",
                [3],
            ],
            'orJsonLength' => [
                (new ActiveQuery())->from('user')
                    ->where('id', 1)
                    ->orJsonLength('meta->tags', '>', 5),
                "SELECT `user`.* FROM `user` WHERE `user`.`id` = ? OR JSON_LENGTH(`user`.`meta`, '$.tags') > ?",
                [1, 5],
            ],
            'jsonContains on joined table' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('setting s', ['user_id' => 'id'])
                    ->jsonContains('setting.metadata->tags', 'core'),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `setting` AS `s` ON `s`.`user_id` = `u`.`id` "
                . "WHERE JSON_CONTAINS(`setting`.`metadata`, ?, '$.tags')",
                ['"core"'],
            ],
            'jsonHas on joined table' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('setting s', ['user_id' => 'id'])
                    ->jsonHas('setting.metadata->priority'),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `setting` AS `s` ON `s`.`user_id` = `u`.`id` "
                . "WHERE JSON_CONTAINS_PATH(`setting`.`metadata`, 'one', '$.priority')",
                null,
            ],
            'orJsonContains' => [
                (new ActiveQuery())->from('user')
                    ->jsonContains('tags', 'php')
                    ->orJsonContains('tags', 'python'),
                "SELECT `user`.* FROM `user` WHERE JSON_CONTAINS(`user`.`tags`, ?, '$') "
                . "OR JSON_CONTAINS(`user`.`tags`, ?, '$')",
                ['"php"', '"python"'],
            ],
            'orJsonNotContains' => [
                (new ActiveQuery())->from('user')
                    ->jsonNotContains('tags', 'spam')
                    ->orJsonNotContains('tags', 'banned'),
                "SELECT `user`.* FROM `user` WHERE NOT JSON_CONTAINS(`user`.`tags`, ?, '$') "
                . "OR NOT JSON_CONTAINS(`user`.`tags`, ?, '$')",
                ['"spam"', '"banned"'],
            ],
            'orJsonHas' => [
                (new ActiveQuery())->from('user')
                    ->jsonHas('meta->phone')
                    ->orJsonHas('meta->mobile'),
                "SELECT `user`.* FROM `user` WHERE JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.phone') "
                . "OR JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.mobile')",
                null,
            ],
            'orJsonMissing' => [
                (new ActiveQuery())->from('user')
                    ->jsonMissing('meta->phone')
                    ->orJsonMissing('meta->fax'),
                "SELECT `user`.* FROM `user` WHERE NOT JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.phone') "
                . "OR NOT JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.fax')",
                null,
            ],
            'JSON on aliased main table' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->jsonContains('meta->tags', 'php'),
                "SELECT `u`.* FROM `user` `u` WHERE JSON_CONTAINS(`u`.`meta`, ?, '$.tags')",
                ['"php"'],
            ],
            'JSON via table alias (s.column->path)' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('setting s', ['user_id' => 'id'])
                    ->jsonContains('s.metadata->tags', 'core'),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `setting` AS `s` ON `s`.`user_id` = `u`.`id` "
                . "WHERE JSON_CONTAINS(`s`.`metadata`, ?, '$.tags')",
                ['"core"'],
            ],
            'whereJson via alias' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('profile p', ['user_id' => 'id'])
                    ->whereJson('p.metadata->verified', '=', true),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `profile` AS `p` ON `p`.`user_id` = `u`.`id` "
                . "WHERE JSON_UNQUOTE(JSON_EXTRACT(`p`.`metadata`, '$.verified')) = ?",
                [true],
            ],
            'jsonHas via alias' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('setting s', ['user_id' => 'id'])
                    ->jsonHas('s.metadata->priority'),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `setting` AS `s` ON `s`.`user_id` = `u`.`id` "
                . "WHERE JSON_CONTAINS_PATH(`s`.`metadata`, 'one', '$.priority')",
                null,
            ],
            'JSON on multiple aliased tables' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('profile p', ['user_id' => 'id'])
                    ->join('setting s', ['user_id' => 'id'])
                    ->jsonContains('meta->tags', 'admin')
                    ->jsonContains('p.preferences->theme', 'dark')
                    ->jsonHas('s.metadata->priority'),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `profile` AS `p` ON `p`.`user_id` = `u`.`id` "
                . "INNER JOIN `setting` AS `s` ON `s`.`user_id` = `u`.`id` "
                . "WHERE JSON_CONTAINS(`u`.`meta`, ?, '$.tags') "
                . "AND JSON_CONTAINS(`p`.`preferences`, ?, '$.theme') "
                . "AND JSON_CONTAINS_PATH(`s`.`metadata`, 'one', '$.priority')",
                ['"admin"', '"dark"'],
            ],
            'jsonLength via alias' => [
                (new ActiveQuery())
                    ->from('user u')
                    ->join('setting s', ['user_id' => 'id'])
                    ->jsonLength('s.metadata->tags', '>', 2),
                "SELECT `u`.* FROM `user` `u` INNER JOIN `setting` AS `s` ON `s`.`user_id` = `u`.`id` "
                . "WHERE JSON_LENGTH(`s`.`metadata`, '$.tags') > ?",
                [2],
            ],
        ];
    }

    /**
     * @param array<int, mixed>|null $expectedBinds
     */
    #[DataProvider('dataProvider')]
    public function testJsonAlias(ActiveQuery $query, string $expected, ?array $expectedBinds): void
    {
        $this->assertEqualsIgnoringCase($expected, (string)$query);
        $this->assertEquals($expectedBinds, $query->getBinds());
    }
}
