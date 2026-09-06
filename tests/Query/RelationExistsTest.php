<?php

namespace Query;

use InvalidArgumentException;
use Models\Category;
use Models\Post;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * Unit tests for the relation-existence filters: has(), doesntHave(),
 * whereHas() and whereDoesntHave().
 */
class RelationExistsTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]);
        Connection::add('pg', [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'postgres',
            'password' => '',
        ]);
        Connection::setDefault('mysql');
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    // ------------------------------------------------------------------
    // A name that is not a relation must not pass silently
    // ------------------------------------------------------------------

    #[Test]
    public function anUnknownRelationNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("has no method 'psots'");

        User::find()->has('psots');
    }

    #[Test]
    public function aMethodThatIsNotARelationIsRejected(): void
    {
        // getTable() exists on every model but returns a string.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return a relation');

        User::find()->has('getTable');
    }

    #[Test]
    public function aQueryWithoutAModelCannotResolveARelation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no model to resolve it against');

        (new ActiveQuery())->from('user')->has('posts');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function relationFilterProvider(): array
    {
        return [
            'has' => ['has'],
            'doesntHave' => ['doesntHave'],
            'whereHas' => ['whereHas'],
            'whereDoesntHave' => ['whereDoesntHave'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('relationFilterProvider')]
    public function everyRelationFilterRejectsAnUnknownName(string $method): void
    {
        // A filter that quietly does nothing returns MORE rows than asked for,
        // which is the one failure mode a caller cannot see.
        $this->expectException(InvalidArgumentException::class);

        User::find()->{$method}('nosuchrelation');
    }

    // ------------------------------------------------------------------
    // The sub-query correlates to the parent's name in THIS query
    // ------------------------------------------------------------------

    #[Test]
    public function anUnaliasedParentIsReferencedByItsTableName(): void
    {
        $this->assertSame(
            'SELECT `user`.* FROM `user` WHERE EXISTS '
            . '(SELECT 1 FROM `post` WHERE `post`.`user_id` = `user`.`id`)',
            User::find()->has('posts')->getSQL()
        );
    }

    #[Test]
    public function anAliasedParentIsReferencedByItsAlias(): void
    {
        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` WHERE EXISTS '
            . '(SELECT 1 FROM `post` WHERE `post`.`user_id` = `u`.`id`)',
            User::find()->alias('u')->has('posts')->getSQL()
        );
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('relationFilterProvider')]
    public function noRelationFilterNamesTheTableWhenTheQueryIsAliased(string $method): void
    {
        $sql = User::find()->alias('u')->{$method}('posts')->getSQL();

        // `user`.`id` under FROM `user` `u` is not a column the server knows.
        $this->assertStringNotContainsString('`user`.`id`', $sql);
        $this->assertStringContainsString('`u`.`id`', $sql);
    }

    // ------------------------------------------------------------------
    // A self-referencing relation must not compare a row to itself
    // ------------------------------------------------------------------

    #[Test]
    public function aSelfReferencingRelationAliasesTheInnerTable(): void
    {
        $this->assertSame(
            'SELECT `category`.* FROM `category` WHERE EXISTS '
            . '(SELECT 1 FROM `category` `category_exists` '
            . 'WHERE `category_exists`.`parent_id` = `category`.`id`)',
            Category::find()->has('getChildren')->getSQL()
        );
    }

    #[Test]
    public function aSelfReferencingRelationKeepsTheTwoSidesApartUnderNot(): void
    {
        $sql = Category::find()->doesntHave('getChildren')->getSQL();

        // Without the alias this read `category`.`parent_id` = `category`.`id`,
        // which every engine resolves against the inner FROM alone.
        $this->assertStringContainsString(
            '`category_exists`.`parent_id` = `category`.`id`',
            $sql
        );
    }

    #[Test]
    public function aSelfReferencingCallbackFiltersTheInnerRowsNotTheOuterOnes(): void
    {
        $sql = Category::find()
            ->whereHas('getChildren', fn(ActiveQuery $q): ActiveQuery => $q->where('status_code', 1))
            ->getSQL();

        $this->assertStringContainsString('`category_exists`.`status_code` = ?', $sql);
    }

    #[Test]
    public function aParentAliasedToTheRelatedTableNameStillSeparatesTheTwo(): void
    {
        // The collision runs the other way round here: the OUTER query is
        // called `post` and the related table is also `post`.
        $this->assertSame(
            'SELECT `post`.* FROM `user` `post` WHERE EXISTS '
            . '(SELECT 1 FROM `post` `post_exists` WHERE `post_exists`.`user_id` = `post`.`id`)',
            User::find()->alias('post')->has('posts')->getSQL()
        );
    }

    #[Test]
    public function anUnrelatedTableIsNotAliasedNeedlessly(): void
    {
        // Callbacks that qualify by the related table name keep working.
        $this->assertStringContainsString(
            'FROM `post` WHERE',
            User::find()->has('posts')->getSQL()
        );
    }

    // ------------------------------------------------------------------
    // Many-to-many goes through the junction table
    // ------------------------------------------------------------------

    #[Test]
    public function aViaTableRelationTestsTheJunction(): void
    {
        // post.id is matched against post_tag.post_id. Reading the foreign key
        // off the related table gave `tag`.`tag_id`, a column that exists
        // nowhere.
        $this->assertSame(
            'SELECT `post`.* FROM `post` WHERE EXISTS '
            . '(SELECT 1 FROM `post_tag` WHERE `post_tag`.`post_id` = `post`.`id`)',
            Post::find()->has('getTags')->getSQL()
        );
    }

    #[Test]
    public function aViaTableRelationDoesNotNameTheRelatedTable(): void
    {
        $sql = Post::find()->has('getTags')->getSQL();

        $this->assertStringNotContainsString('`tag`', $sql);
    }

    #[Test]
    public function aViaTableCallbackConstrainsTheJunction(): void
    {
        $query = Post::find()
            ->whereHas('getTags', fn(ActiveQuery $q): ActiveQuery => $q->where('tag_id', '<', 3));
        $sql = $query->getSQL();

        $this->assertStringContainsString('`post_tag`.`tag_id` < ?', $sql);
        $this->assertSame([3], $query->getBinds());
    }

    // ------------------------------------------------------------------
    // The sub-query speaks its parent's dialect
    // ------------------------------------------------------------------

    #[Test]
    public function theSubQueryIsQuotedByTheParentsGrammar(): void
    {
        // Built on the default connection's grammar, this mixed `post` with
        // "user" in one statement and parsed on neither engine.
        $this->assertSame(
            'SELECT "user".* FROM "user" WHERE EXISTS '
            . '(SELECT 1 FROM "post" WHERE "post"."user_id" = "user"."id")',
            User::find()->withConnection('pg')->has('posts')->getSQL()
        );
    }

    #[Test]
    public function aSubQueryCallbackAlsoUsesTheParentsGrammar(): void
    {
        $sql = User::find()->withConnection('pg')
            ->whereHas('posts', fn(ActiveQuery $q): ActiveQuery => $q->where('view_count', '>', 10))
            ->getSQL();

        $this->assertStringNotContainsString('`', $sql);
        $this->assertStringContainsString('"post"."view_count" > ?', $sql);
    }

    // ------------------------------------------------------------------
    // Shape: NOT EXISTS, operators and bind ordering
    // ------------------------------------------------------------------

    #[Test]
    public function doesntHaveNegatesTheSameSubQuery(): void
    {
        $has = User::find()->has('posts')->getSQL();
        $doesnt = User::find()->doesntHave('posts')->getSQL();

        $this->assertSame(str_replace('WHERE EXISTS', 'WHERE NOT EXISTS', $has), $doesnt);
    }

    #[Test]
    public function theLogicalOperatorJoinsTheFilterToWhatCameBefore(): void
    {
        $sql = User::find()->where('status_code', 1)->has('posts', 'OR')->getSQL();

        $this->assertStringContainsString('`user`.`status_code` = ? OR EXISTS', $sql);
    }

    #[Test]
    public function theFirstConditionTakesNoLeadingOperator(): void
    {
        $sql = User::find()->has('posts', 'OR')->getSQL();

        $this->assertStringContainsString('WHERE EXISTS', $sql);
        $this->assertStringNotContainsString('WHERE OR', $sql);
    }

    #[Test]
    public function bindsFromTheSubQueryLandInOuterOrder(): void
    {
        $query = User::find()
            ->where('status_code', 1)
            ->whereHas('posts', fn(ActiveQuery $q): ActiveQuery => $q->where('view_count', '>', 100))
            ->whereHas('comments', fn(ActiveQuery $q): ActiveQuery => $q->where('id', '>', 7));

        $sql = $query->getSQL();

        $this->assertSame([1, 100, 7], $query->getBinds());
        $this->assertSame(3, substr_count($sql, '?'));
    }

    #[Test]
    public function aFilterWithoutACallbackBindsNothing(): void
    {
        $query = User::find()->has('posts');
        $query->getSQL();

        $this->assertNull($query->getBinds());
    }
}
