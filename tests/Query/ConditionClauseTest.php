<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Aggregations\Count;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;

/**
 * Where the WHERE keyword comes from.
 *
 * A condition reaches a statement as one of three things, and only one of them
 * used to be given the keyword. A string got `WHERE` prefixed; a `Raw` and an
 * `ActiveQuery` were emitted verbatim. That is correct for an `ActiveQuery`,
 * whose sections arrive with their own keywords — and wrong for a `Raw`, which
 * is written the way every documented `Raw` fragment is written:
 *
 *     $model->deleteAll(new Raw('`n` > ?', [2]));   // DELETE FROM `t` `n` > ?
 *
 * The mirror-image case was a string that did include the keyword, which came
 * out as `WHERE WHERE n > 2`. Both are syntax errors rather than silent damage,
 * so the server caught them — but the condition was well-formed and the caller
 * had no way to tell which of the three shapes wanted the keyword.
 */
class ConditionClauseTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * The SQL a builder would send.
     *
     * @param Delete|Update|Count $builder The builder to render.
     * @return string
     */
    private function sqlOf(Delete|Update|Count $builder): string
    {
        $method = new ReflectionMethod($builder, 'buildSQL');
        $method->setAccessible(true);

        return (string)$method->invoke($builder);
    }

    /**
     * The same condition written each of the ways the API accepts.
     *
     * @return array<string, array{0: string|ActiveQuery|Raw}>
     */
    public static function equivalentConditions(): array
    {
        return [
            'string' => ['`id` = 1'],
            'string with WHERE' => ['WHERE `id` = 1'],
            'padded string' => ['   `id` = 1   '],
            'Raw' => [new Raw('`id` = 1')],
            'Raw with WHERE' => [new Raw('WHERE `id` = 1')],
            'padded Raw' => [new Raw('  `id` = 1  ')],
        ];
    }

    #[Test]
    #[DataProvider('equivalentConditions')]
    public function everyWayOfWritingTheSameConditionBuildsTheSameDelete(string|ActiveQuery|Raw $condition): void
    {
        $delete = new Delete('user', $condition);
        $delete->withConnection('mysql');

        $this->assertSame('DELETE FROM `user` WHERE `id` = 1', $this->sqlOf($delete));
    }

    #[Test]
    #[DataProvider('equivalentConditions')]
    public function everyWayOfWritingTheSameConditionBuildsTheSameUpdate(string|ActiveQuery|Raw $condition): void
    {
        $update = new Update('user', ['score' => 1], $condition);
        $update->withConnection('mysql');

        $this->assertSame('UPDATE `user` SET `score` = ? WHERE `id` = 1', $this->sqlOf($update));
    }

    #[Test]
    #[DataProvider('equivalentConditions')]
    public function everyWayOfWritingTheSameConditionBuildsTheSameCount(string|ActiveQuery|Raw $condition): void
    {
        $count = new Count('user', '*');
        $count->withConnection('mysql')->condition($condition);

        $this->assertSame('SELECT COUNT(*) FROM `user` WHERE `id` = 1', $count->getSQL());
    }

    #[Test]
    public function aRawConditionKeepsItsBindsWhenTheKeywordIsAdded(): void
    {
        // The keyword is prepended to the SQL; the values must still line up
        // with the placeholders it now sits in front of.
        $delete = new Delete('user', new Raw('`score` > ? AND `role` = ?', [80, 'admin']));
        $delete->withConnection('mysql');

        $this->assertSame('DELETE FROM `user` WHERE `score` > ? AND `role` = ?', $this->sqlOf($delete));

        $binds = $delete->getBinds();
        $this->assertNotNull($binds);
        $this->assertSame([80, 'admin'], array_values($binds));
    }

    /**
     * Fragments that already open a clause and must be left as written.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function fragmentsThatAlreadyOpenAClause(): array
    {
        return [
            'WHERE' => ['WHERE `id` = 1'],
            'lowercase where' => ['where `id` = 1'],
            'mixed case' => ['WhErE `id` = 1'],
            'INNER JOIN' => ['INNER JOIN `d` ON `d`.`id` = `u`.`d_id`'],
            'LEFT JOIN' => ['LEFT JOIN `d` ON `d`.`id` = `u`.`d_id`'],
            'LEFT OUTER JOIN' => ['LEFT OUTER JOIN `d` ON `d`.`id` = `u`.`d_id`'],
            'CROSS JOIN' => ['CROSS JOIN `d`'],
            'STRAIGHT_JOIN' => ['STRAIGHT_JOIN `d`'],
            'ORDER BY' => ['ORDER BY `id`'],
            'GROUP BY' => ['GROUP BY `role`'],
            'HAVING' => ['HAVING COUNT(*) > 1'],
            'LIMIT' => ['LIMIT 5'],
        ];
    }

    /**
     * A fragment that already opens a clause keeps exactly one keyword.
     *
     * @param non-empty-string $fragment The clause to pass through unchanged.
     * @return void
     */
    #[Test]
    #[DataProvider('fragmentsThatAlreadyOpenAClause')]
    public function aFragmentThatOpensAClauseIsNotGivenASecondKeyword(string $fragment): void
    {
        $delete = new Delete('user', new Raw($fragment));
        $delete->withConnection('mysql');

        $this->assertStringNotContainsString('WHERE WHERE', $this->sqlOf($delete));
        $this->assertStringEndsWith($fragment, $this->sqlOf($delete));
    }

    /**
     * Conditions that begin with a word that only looks like a clause keyword.
     *
     * @return array<string, array{0: string}>
     */
    public static function fragmentsThatMerelyLookLikeClauses(): array
    {
        return [
            // LEFT and RIGHT are string functions as well as join keywords, so
            // the keyword test cannot stop at the word — it has to see JOIN.
            'LEFT(' => ['LEFT(`username`, 1) = ?'],
            'RIGHT(' => ['RIGHT(`username`, 1) = ?'],
            'LEFT( with space' => ['LEFT (`username`, 1) = ?'],
            'a column named limits' => ['`limits` > ?'],
            'a column named ordered' => ['`ordered` = ?'],
            'a column named grouping' => ['`grouping` = ?'],
            'a column named joined' => ['`joined` IS NULL'],
        ];
    }

    #[Test]
    #[DataProvider('fragmentsThatMerelyLookLikeClauses')]
    public function aConditionIsStillGivenTheKeyword(string $fragment): void
    {
        $delete = new Delete('user', new Raw($fragment, [1]));
        $delete->withConnection('mysql');

        $this->assertSame("DELETE FROM `user` WHERE $fragment", $this->sqlOf($delete));
    }

    #[Test]
    public function anActiveQueryConditionIsUnchanged(): void
    {
        // Its sections already carry WHERE, ORDER BY and LIMIT; this is the one
        // shape that was right all along, and it has to stay right.
        $delete = new Delete(
            'user',
            (new ActiveQuery())->from('user')->on('mysql')->where('id', '=', 7)->orderBy('id')->limit(1)
        );
        $delete->withConnection('mysql');

        $this->assertSame('DELETE FROM `user` WHERE `id` = ? ORDER BY `id` ASC LIMIT 1', $this->sqlOf($delete));
    }

    /**
     * Conditions that contribute nothing.
     *
     * @return array<string, array{0: string|Raw}>
     */
    public static function emptyFragments(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
            'newline' => ["\n\t"],
            'empty Raw' => [new Raw('')],
            'whitespace Raw' => [new Raw('   ')],
        ];
    }

    #[Test]
    #[DataProvider('emptyFragments')]
    public function anEmptyConditionLeavesNoDanglingKeyword(string|Raw $condition): void
    {
        // `WHERE` on its own is a syntax error, and `'   '` used to produce
        // exactly that — the guard in deleteAll() relies on emptiness being
        // decided the same way here as it is there.
        $update = new Update('user', ['score' => 1], $condition);
        $update->withConnection('mysql');

        $this->assertSame('UPDATE `user` SET `score` = ?', $this->sqlOf($update));
    }

    #[Test]
    public function theEmptinessTestMatchesTheOneDeleteAllGuardsWith(): void
    {
        // Two places decide "does this condition narrow anything?" — the guard
        // in Model and the clause builder here. If they disagree, either the
        // guard refuses a usable condition or it lets through one that deletes
        // every row.
        $normalize = new ReflectionMethod(Delete::class, 'normalizeConditionClause');
        $normalize->setAccessible(true);

        $delete = new Delete('user');

        foreach (['', '   ', "\n", "\t "] as $empty) {
            $this->assertNull($normalize->invoke($delete, $empty));
        }

        foreach (['id = 1', ' id = 1 ', 'WHERE id = 1'] as $narrowing) {
            $this->assertNotNull($normalize->invoke($delete, $narrowing));
        }
    }
}
