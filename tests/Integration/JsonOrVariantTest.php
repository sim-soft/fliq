<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\DB;

/**
 * The OR variants of the JSON conditions, run against MySQL.
 *
 * Each is a one-line forwarder passing 'OR' through to the AND-joining method,
 * so the failure they can produce is not an error but a narrower or wider
 * result set than asked for. The unit tests assert the operator in the SQL;
 * these assert the rows the server actually returns, so an OR that reached the
 * statement but bound the wrong side would still be caught.
 *
 * The fixture is a scratch table of this class's own — the shared sample_db
 * tables have no JSON column, and a test must not add one.
 */
class JsonOrVariantTest extends DatabaseTestCase
{
    /** @var string Scratch table, created and dropped by this class alone. */
    private const TABLE = 'json_or_variant_fixture';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        DB::raw('DROP TABLE IF EXISTS `' . self::TABLE . '`', [], 'mysql');
        DB::raw('CREATE TABLE `' . self::TABLE . '` (id INT PRIMARY KEY, meta JSON)', [], 'mysql');

        foreach ([
            [1, '{"status":"active","tags":["a","b"],"level":3}'],
            [2, '{"status":"banned","tags":["b","c"],"level":7}'],
            [3, '{"status":"active","tags":["c"],"level":1}'],
            [4, '{"tags":[],"level":9}'],
        ] as [$id, $json]) {
            DB::raw('INSERT INTO `' . self::TABLE . '` (id, meta) VALUES (?, ?)', [$id, $json], 'mysql');
        }
    }

    public static function tearDownAfterClass(): void
    {
        DB::raw('DROP TABLE IF EXISTS `' . self::TABLE . '`', [], 'mysql');

        parent::tearDownAfterClass();
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from(self::TABLE)->on('mysql')->select('id');
    }

    /**
     * The ids a query returns, ascending.
     *
     * @return array<int, int>
     */
    private function ids(ActiveQuery $query): array
    {
        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            iterator_to_array($query->getArray())
        );
        sort($ids);

        return $ids;
    }

    #[Test]
    public function orWhereJsonValueWidensTheResult(): void
    {
        // id 2 alone, then OR in the two active rows.
        $this->assertSame(
            [1, 2, 3],
            $this->ids($this->query()->where('id', '=', 2)->orWhereJsonValue('meta->status', 'active'))
        );
    }

    #[Test]
    public function orJsonValueMatchesItsAlias(): void
    {
        $this->assertSame(
            $this->ids($this->query()->where('id', '=', 2)->orWhereJsonValue('meta->status', 'active')),
            $this->ids($this->query()->where('id', '=', 2)->orJsonValue('meta->status', 'active'))
        );
    }

    #[Test]
    public function jsonValueAliasesAgreeOnTheAndForm(): void
    {
        $this->assertSame([1, 3], $this->ids($this->query()->whereJsonValue('meta->status', 'active')));
        $this->assertSame([1, 3], $this->ids($this->query()->jsonValue('meta->status', 'active')));
    }

    #[Test]
    public function orJsonContainsWidensTheResult(): void
    {
        $this->assertSame([2, 3], $this->ids($this->query()->jsonContains('meta->tags', 'c')));
        $this->assertSame(
            [1, 2, 3],
            $this->ids($this->query()->where('id', '=', 1)->orJsonContains('meta->tags', 'c'))
        );
    }

    #[Test]
    public function orJsonNotContainsWidensTheResult(): void
    {
        $this->assertSame([1, 4], $this->ids($this->query()->jsonNotContains('meta->tags', 'c')));
        $this->assertSame(
            [1, 2, 4],
            $this->ids($this->query()->where('id', '=', 2)->orJsonNotContains('meta->tags', 'c'))
        );
    }

    #[Test]
    public function orWhereJsonDoesntContainMatchesItsAlias(): void
    {
        $this->assertSame(
            $this->ids($this->query()->where('id', '=', 2)->orJsonNotContains('meta->tags', 'c')),
            $this->ids($this->query()->where('id', '=', 2)->orWhereJsonDoesntContain('meta->tags', 'c'))
        );
    }

    #[Test]
    public function orJsonHasFindsRowsCarryingTheKey(): void
    {
        // Row 4 has no status key; the OR must add the three that do.
        $this->assertSame(
            [1, 2, 3, 4],
            $this->ids($this->query()->where('id', '=', 4)->orJsonHas('meta->status'))
        );
    }

    #[Test]
    public function orJsonMissingFindsRowsLackingTheKey(): void
    {
        $this->assertSame([4], $this->ids($this->query()->jsonMissing('meta->status')));
        $this->assertSame(
            [1, 4],
            $this->ids($this->query()->where('id', '=', 1)->orJsonMissing('meta->status'))
        );
    }

    #[Test]
    public function keyPresenceAliasesAgree(): void
    {
        $this->assertSame(
            $this->ids($this->query()->where('id', '=', 4)->orJsonHas('meta->status')),
            $this->ids($this->query()->where('id', '=', 4)->orWhereJsonContainsKey('meta->status'))
        );
        $this->assertSame(
            $this->ids($this->query()->where('id', '=', 1)->orJsonMissing('meta->status')),
            $this->ids($this->query()->where('id', '=', 1)->orWhereJsonDoesntContainKey('meta->status'))
        );
    }

    #[Test]
    public function orJsonLengthWidensTheResult(): void
    {
        // Only row 3 has a single tag.
        $this->assertSame([3], $this->ids($this->query()->whereJsonLength('meta->tags', '=', 1)));
        $this->assertSame(
            [1, 3],
            $this->ids($this->query()->where('id', '=', 1)->orWhereJsonLength('meta->tags', '=', 1))
        );
        $this->assertSame(
            [1, 3],
            $this->ids($this->query()->where('id', '=', 1)->orJsonLength('meta->tags', '=', 1))
        );
    }

    #[Test]
    public function theScratchTableIsTheOnlyThingThisClassAdded(): void
    {
        // Guards the fixture: the shared tables must be untouched by the above.
        $users = DB::query('SELECT COUNT(*) c FROM user', [], 'mysql');

        $this->assertSame(10, (int)$users[0]['c']);
    }
}
