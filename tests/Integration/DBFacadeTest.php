<?php

namespace Integration;

use Models\Tag;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Simsoft\DB\Exceptions\QueryException;

/**
 * Integration tests for the DB static facade.
 *
 * DBTest covers SQL generation in sqlOnly mode; this class covers the
 * execution path — the branch every one of those methods takes in production.
 *
 * DB::$sqlOnly is global process state and DBTest sets it from a data
 * provider, which PHPUnit runs before any test in the suite. setUp() therefore
 * clears it rather than trusting the default, or a random test order could
 * leave these tests building SQL and asserting nothing.
 */
class DBFacadeTest extends DatabaseTestCase
{
    /** @var int The tag rows shipped in resources/sample_db.sql */
    private const FIXTURE_TAG_COUNT = 8;

    protected function setUp(): void
    {
        parent::setUp();

        DB::disableSqlOnly();
        $this->deleteProbeTags();
    }

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            return;
        }

        DB::disableSqlOnly();
        $this->deleteProbeTags();
    }

    /**
     * Remove every tag this class creates, identified by slug prefix.
     *
     * @return void
     */
    private function deleteProbeTags(): void
    {
        DB::raw("DELETE FROM `tag` WHERE `slug` LIKE 'dbf-%'");
    }

    /**
     * Count tag rows with the given slug.
     *
     * @param string $slug The slug to look for.
     * @return int
     */
    private function countBySlug(string $slug): int
    {
        return count(DB::query('SELECT `id` FROM `tag` WHERE `slug` = ?', [$slug]));
    }

    /**
     * Read one tag's name by slug.
     *
     * @param string $slug The slug to look for.
     * @return string|null
     */
    private function nameBySlug(string $slug): ?string
    {
        $rows = DB::query('SELECT `name` FROM `tag` WHERE `slug` = ?', [$slug]);

        return $rows === [] ? null : (string) $rows[0]['name'];
    }

    // ------------------------------------------------------------------
    // table()
    // ------------------------------------------------------------------

    #[Test]
    public function tableReturnsAQueryForATableName(): void
    {
        $query = DB::table('tag');

        $this->assertInstanceOf(ActiveQuery::class, $query);
        $this->assertCount(self::FIXTURE_TAG_COUNT, $query->get());
    }

    #[Test]
    public function tableAcceptsAModelInstance(): void
    {
        $query = DB::table(new Tag());

        $this->assertInstanceOf(ActiveQuery::class, $query);
        $this->assertCount(self::FIXTURE_TAG_COUNT, $query->get());
    }

    #[Test]
    public function tableUsesTheGivenConnection(): void
    {
        $query = DB::table('tag', 'test');

        $this->assertCount(self::FIXTURE_TAG_COUNT, $query->get());
    }

    #[Test]
    public function tableWithAModelUsesTheGivenConnection(): void
    {
        // The connection argument used to be dropped for the Model overload
        // while every write method honoured it, so DB::insert($model, [...],
        // 'other') wrote to one database and DB::table($model, 'other') read
        // from another.
        $query = DB::table(new Tag(), 'test');

        $this->assertSame('test', $query->getConnectionName());
    }

    #[Test]
    public function tableWithAModelKeepsTheModelConnectionWhenNoneIsGiven(): void
    {
        $query = DB::table(new Tag());

        $this->assertSame((new Tag())->getConnectionName(), $query->getConnectionName());
    }

    // ------------------------------------------------------------------
    // insert()
    // ------------------------------------------------------------------

    #[Test]
    public function insertWritesTheRow(): void
    {
        $this->assertTrue(DB::insert('tag', ['name' => 'Facade', 'slug' => 'dbf-insert']));
        $this->assertSame(1, $this->countBySlug('dbf-insert'));
    }

    #[Test]
    public function insertAcceptsAModelInstanceAsTheTable(): void
    {
        $this->assertTrue(DB::insert(new Tag(), ['name' => 'Facade', 'slug' => 'dbf-model']));
        $this->assertSame(1, $this->countBySlug('dbf-model'));
    }

    #[Test]
    public function insertThrowsOnADuplicateUniqueKey(): void
    {
        DB::insert('tag', ['name' => 'Facade', 'slug' => 'dbf-dup']);

        $this->expectException(QueryException::class);

        DB::insert('tag', ['name' => 'Other', 'slug' => 'dbf-dup']);
    }

    #[Test]
    public function insertOrIgnoreSwallowsTheDuplicate(): void
    {
        DB::insert('tag', ['name' => 'First', 'slug' => 'dbf-ignore']);

        // Same unique slug — INSERT IGNORE must not throw and must not add a row.
        $this->assertTrue(DB::insertOrIgnore('tag', ['name' => 'Second', 'slug' => 'dbf-ignore']));
        $this->assertSame(1, $this->countBySlug('dbf-ignore'));
        $this->assertSame('First', $this->nameBySlug('dbf-ignore'));
    }

    // ------------------------------------------------------------------
    // update()
    // ------------------------------------------------------------------

    #[Test]
    public function updateChangesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Before', 'slug' => 'dbf-update']);

        $this->assertTrue(DB::update(
            'tag',
            ['name' => 'After'],
            (new ActiveQuery())->where('slug', 'dbf-update')
        ));

        $this->assertSame('After', $this->nameBySlug('dbf-update'));
    }

    #[Test]
    public function updateLeavesNonMatchingRowsAlone(): void
    {
        DB::insert('tag', ['name' => 'Keep', 'slug' => 'dbf-keep']);
        DB::insert('tag', ['name' => 'Before', 'slug' => 'dbf-change']);

        DB::update('tag', ['name' => 'After'], (new ActiveQuery())->where('slug', 'dbf-change'));

        $this->assertSame('Keep', $this->nameBySlug('dbf-keep'));
        $this->assertSame('After', $this->nameBySlug('dbf-change'));
        // And the fixture rows are untouched.
        $this->assertSame('PHP', $this->nameBySlug('php'));
    }

    #[Test]
    public function updateIgnoreAppliesTheUpdate(): void
    {
        DB::insert('tag', ['name' => 'Before', 'slug' => 'dbf-updign']);

        $this->assertTrue(DB::updateIgnore(
            'tag',
            ['name' => 'After'],
            (new ActiveQuery())->where('slug', 'dbf-updign')
        ));

        $this->assertSame('After', $this->nameBySlug('dbf-updign'));
    }

    #[Test]
    public function updateLowPriorityIgnoreAppliesTheUpdate(): void
    {
        DB::insert('tag', ['name' => 'Before', 'slug' => 'dbf-updlow']);

        $this->assertTrue(DB::updateLowPriorityIgnore(
            'tag',
            ['name' => 'After'],
            (new ActiveQuery())->where('slug', 'dbf-updlow')
        ));

        $this->assertSame('After', $this->nameBySlug('dbf-updlow'));
    }

    // ------------------------------------------------------------------
    // delete()
    // ------------------------------------------------------------------

    #[Test]
    public function deleteRemovesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-delete']);

        $this->assertTrue(DB::delete('tag', (new ActiveQuery())->where('slug', 'dbf-delete')));
        $this->assertSame(0, $this->countBySlug('dbf-delete'));
    }

    #[Test]
    public function deleteOnlyRemovesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-del-a']);
        DB::insert('tag', ['name' => 'Stay', 'slug' => 'dbf-del-b']);

        DB::delete('tag', (new ActiveQuery())->where('slug', 'dbf-del-a'));

        $this->assertSame(0, $this->countBySlug('dbf-del-a'));
        $this->assertSame(1, $this->countBySlug('dbf-del-b'));
        // The fixture tags must survive a targeted delete.
        $this->assertCount(self::FIXTURE_TAG_COUNT, DB::query("SELECT `id` FROM `tag` WHERE `slug` NOT LIKE 'dbf-%'"));
    }

    #[Test]
    public function deleteIgnoreRemovesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-delign']);

        $this->assertTrue(DB::deleteIgnore('tag', (new ActiveQuery())->where('slug', 'dbf-delign')));
        $this->assertSame(0, $this->countBySlug('dbf-delign'));
    }

    #[Test]
    public function deleteQuickRemovesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-delquick']);

        $this->assertTrue(DB::deleteQuick('tag', (new ActiveQuery())->where('slug', 'dbf-delquick')));
        $this->assertSame(0, $this->countBySlug('dbf-delquick'));
    }

    #[Test]
    public function deleteQuickIgnoreRemovesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-delqi']);

        $this->assertTrue(DB::deleteQuickIgnore('tag', (new ActiveQuery())->where('slug', 'dbf-delqi')));
        $this->assertSame(0, $this->countBySlug('dbf-delqi'));
    }

    #[Test]
    public function deleteLowPriorityQuickIgnoreRemovesMatchingRows(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-dellqi']);

        $this->assertTrue(DB::deleteLowPriorityQuickIgnore(
            'tag',
            (new ActiveQuery())->where('slug', 'dbf-dellqi')
        ));
        $this->assertSame(0, $this->countBySlug('dbf-dellqi'));
    }

    #[Test]
    public function deleteAcceptsAModelInstanceAsTheTable(): void
    {
        DB::insert('tag', ['name' => 'Gone', 'slug' => 'dbf-delmodel']);

        $this->assertTrue(DB::delete(new Tag(), (new ActiveQuery())->where('slug', 'dbf-delmodel')));
        $this->assertSame(0, $this->countBySlug('dbf-delmodel'));
    }

    // ------------------------------------------------------------------
    // upsert()
    // ------------------------------------------------------------------

    #[Test]
    public function upsertInsertsWhenTheRowIsNew(): void
    {
        $this->assertTrue(DB::upsert('tag', ['name' => 'Fresh', 'slug' => 'dbf-upsert'], ['name']));

        $this->assertSame(1, $this->countBySlug('dbf-upsert'));
        $this->assertSame('Fresh', $this->nameBySlug('dbf-upsert'));
    }

    #[Test]
    public function upsertUpdatesWhenTheRowExists(): void
    {
        DB::insert('tag', ['name' => 'Original', 'slug' => 'dbf-upsert2']);

        $this->assertTrue(DB::upsert('tag', ['name' => 'Replaced', 'slug' => 'dbf-upsert2'], ['name']));

        // Updated in place rather than duplicated.
        $this->assertSame(1, $this->countBySlug('dbf-upsert2'));
        $this->assertSame('Replaced', $this->nameBySlug('dbf-upsert2'));
    }

    // ------------------------------------------------------------------
    // raw() and query()
    // ------------------------------------------------------------------

    #[Test]
    public function rawExecutesAStatement(): void
    {
        DB::insert('tag', ['name' => 'Before', 'slug' => 'dbf-raw']);

        $this->assertTrue(DB::raw('UPDATE `tag` SET `name` = ? WHERE `slug` = ?', ['After', 'dbf-raw']));
        $this->assertSame('After', $this->nameBySlug('dbf-raw'));
    }

    #[Test]
    public function rawBindsItsValues(): void
    {
        // The bound value is data, never SQL: a quote in it must not terminate
        // a literal, and the row must come back with the quote intact.
        $name = "O'Brien \"quoted\"";
        DB::raw('INSERT INTO `tag` (`name`, `slug`) VALUES (?, ?)', [$name, 'dbf-bind']);

        $this->assertSame($name, $this->nameBySlug('dbf-bind'));
    }

    #[Test]
    public function rawThrowsOnInvalidSql(): void
    {
        $this->expectException(QueryException::class);

        DB::raw('UPDATE `no_such_table_dbf` SET `x` = 1');
    }

    #[Test]
    public function queryReturnsRows(): void
    {
        $rows = DB::query('SELECT `id`, `name` FROM `tag` WHERE `slug` = ?', ['php']);

        $this->assertCount(1, $rows);
        $this->assertSame('PHP', $rows[0]['name']);
        $this->assertArrayHasKey('id', $rows[0]);
    }

    #[Test]
    public function queryReturnsAnEmptyArrayWhenNothingMatches(): void
    {
        $this->assertSame([], DB::query('SELECT `id` FROM `tag` WHERE `slug` = ?', ['dbf-nothing']));
    }

    #[Test]
    public function queryWithoutBindsWorks(): void
    {
        $this->assertCount(self::FIXTURE_TAG_COUNT, DB::query('SELECT `id` FROM `tag`'));
    }

    #[Test]
    public function queryUsesTheGivenConnection(): void
    {
        $this->assertCount(self::FIXTURE_TAG_COUNT, DB::query('SELECT `id` FROM `tag`', [], 'test'));
    }

    #[Test]
    public function queryThrowsOnInvalidSql(): void
    {
        $this->expectException(QueryException::class);

        DB::query('SELECT * FROM `no_such_table_dbf`');
    }

    // ------------------------------------------------------------------
    // transaction()
    // ------------------------------------------------------------------

    #[Test]
    public function transactionCommitsWhenTheCallbackReturnsTrue(): void
    {
        $result = DB::transaction('mysql', function (): bool {
            DB::insert('tag', ['name' => 'Committed', 'slug' => 'dbf-tx-ok']);
            return true;
        });

        $this->assertTrue($result);
        $this->assertSame(1, $this->countBySlug('dbf-tx-ok'));
    }

    #[Test]
    public function transactionRollsBackWhenTheCallbackReturnsFalse(): void
    {
        $result = DB::transaction('mysql', function (): bool {
            DB::insert('tag', ['name' => 'Discarded', 'slug' => 'dbf-tx-no']);
            return false;
        });

        $this->assertFalse($result);
        $this->assertSame(0, $this->countBySlug('dbf-tx-no'));
    }

    #[Test]
    public function transactionRollsBackWhenTheCallbackThrows(): void
    {
        try {
            DB::transaction('mysql', function (): bool {
                DB::insert('tag', ['name' => 'Discarded', 'slug' => 'dbf-tx-throw']);
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, $this->countBySlug('dbf-tx-throw'));
    }

    #[Test]
    public function transactionWrapsTheCallbackExceptionAndKeepsIt(): void
    {
        // The original exception is wrapped in a QueryException; it must be
        // retained as the previous exception rather than discarded, or the
        // cause of a failed transaction is unrecoverable.
        try {
            DB::transaction('mysql', function (): bool {
                throw new RuntimeException('the real cause');
            });
            $this->fail('Expected a QueryException.');
        } catch (QueryException $exception) {
            $this->assertSame('the real cause', $exception->getMessage());

            $previous = $exception->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $previous);
            $this->assertSame('the real cause', $previous->getMessage());
        }
    }

    #[Test]
    public function transactionThrowsForAnUnknownConnection(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction('no_such_connection_dbf', fn(): bool => true);
    }

    // ------------------------------------------------------------------
    // sqlOnly mode
    // ------------------------------------------------------------------

    #[Test]
    public function sqlOnlyReturnsTheBuilderWithoutExecuting(): void
    {
        DB::sqlOnly();

        $builder = DB::insert('tag', ['name' => 'Never', 'slug' => 'dbf-sqlonly']);

        $this->assertInstanceOf(Insert::class, $builder);
        // Nothing reached the database.
        $this->assertSame(0, $this->countBySlug('dbf-sqlonly'));
    }

    #[Test]
    public function disableSqlOnlyRestoresExecution(): void
    {
        DB::sqlOnly();
        DB::disableSqlOnly();

        $this->assertTrue(DB::insert('tag', ['name' => 'Real', 'slug' => 'dbf-resumed']));
        $this->assertSame(1, $this->countBySlug('dbf-resumed'));
    }

    #[Test]
    public function sqlOnlyAppliesToEveryWriteMethod(): void
    {
        DB::sqlOnly();

        $condition = (new ActiveQuery())->where('slug', 'dbf-none');

        // Every method routed through executeOrReturn() must respect the flag;
        // one that slipped through would write to a live database in a context
        // the caller believes is inert.
        $this->assertNotSame(true, DB::insert('tag', ['name' => 'a', 'slug' => 'dbf-none']));
        $this->assertNotSame(true, DB::insertOrIgnore('tag', ['name' => 'a', 'slug' => 'dbf-none']));
        $this->assertNotSame(true, DB::update('tag', ['name' => 'a'], $condition));
        $this->assertNotSame(true, DB::updateIgnore('tag', ['name' => 'a'], $condition));
        $this->assertNotSame(true, DB::updateLowPriorityIgnore('tag', ['name' => 'a'], $condition));
        $this->assertNotSame(true, DB::delete('tag', $condition));
        $this->assertNotSame(true, DB::deleteIgnore('tag', $condition));
        $this->assertNotSame(true, DB::deleteQuick('tag', $condition));
        $this->assertNotSame(true, DB::deleteQuickIgnore('tag', $condition));
        $this->assertNotSame(true, DB::deleteLowPriorityQuickIgnore('tag', $condition));
        $this->assertNotSame(true, DB::upsert('tag', ['name' => 'a', 'slug' => 'dbf-none'], ['name']));

        $this->assertSame(0, $this->countBySlug('dbf-none'));
    }

    #[Test]
    public function sqlOnlyDoesNotAffectRawOrQuery(): void
    {
        // raw() and query() bypass executeOrReturn() entirely — they always
        // run. Worth pinning: it is the one asymmetry in the facade.
        DB::sqlOnly();

        $this->assertTrue(DB::raw('INSERT INTO `tag` (`name`, `slug`) VALUES (?, ?)', ['R', 'dbf-rawmode']));
        $this->assertSame(1, $this->countBySlug('dbf-rawmode'));
    }

    #[Test]
    public function defaultConnectionIsUsedWhenNoneIsGiven(): void
    {
        $this->assertSame('mysql', Connection::getDefaultName());

        DB::insert('tag', ['name' => 'Default', 'slug' => 'dbf-default']);

        $this->assertSame(1, $this->countBySlug('dbf-default'));
    }
}
