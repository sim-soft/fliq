<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Conditions\BetweenDateCondition;
use Simsoft\DB\Collection;
use Simsoft\DB\DB;

/**
 * orWhere()'s widened signature, executed against a live database.
 *
 * The narrow signature did not merely refuse valid calls. orWhere($clause) was
 * accepted — Clause is stringable, so it slipped through the callable|Raw
 * union — and produced SQL carrying three placeholders against one bind, which
 * mysqli rejects at execute with a message about argument counts rather than
 * about the call that was wrong. These tests run the queries and compare the
 * rows against the answer the server gives for the equivalent plain SQL.
 *
 * Ground truth, from the `user` rows in resources/sample_db.sql:
 *   ids 1-10, status_code 1 for all except id 8 (0) and id 10 (999).
 *
 * Every test here is read-only.
 */
class OrWhereParityTest extends DatabaseTestCase
{
    /**
     * User ids the builder returned, sorted.
     *
     * @param Collection $rows The query result.
     * @return array<int, int>
     */
    private function ids(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $this->assertInstanceOf(User::class, $row);
            $ids[] = $row->id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * User ids matching a plain SQL predicate, sorted.
     *
     * @param string $where The WHERE clause, without the keyword.
     * @param array<int, mixed> $binds Values for the clause's placeholders.
     * @return array<int, int>
     */
    private function expected(string $where, array $binds = []): array
    {
        $ids = [];

        foreach (DB::query("SELECT id FROM user WHERE $where", $binds) as $row) {
            $ids[] = (int)$row['id'];
        }

        sort($ids);

        return $ids;
    }

    #[Test]
    public function orWhereWithATripletListReturnsTheRowsTheOrDescribes(): void
    {
        // Written as "id = 10, or (id >= 1 and id <= 3)".
        $this->assertSame(
            $this->expected('id = 10 OR (id >= 1 AND id <= 3)'),
            $this->ids(User::find()->where('id', '=', 10)->orWhere([['id', '>=', 1], ['id', '<=', 3]])->get())
        );
    }

    #[Test]
    public function orWhereWithAMapReturnsTheRowsTheOrDescribes(): void
    {
        $this->assertSame(
            $this->expected('id = 10 OR (id = 1 AND status_code = 1)'),
            $this->ids(User::find()->where('id', '=', 10)->orWhere(['id' => 1, 'status_code' => 1])->get())
        );
    }

    #[Test]
    public function orWhereWithAMapTreatsAnArrayValueAsIn(): void
    {
        $this->assertSame(
            $this->expected('id = 10 OR id IN (1, 2, 3)'),
            $this->ids(User::find()->where('id', '=', 10)->orWhere(['id' => [1, 2, 3]])->get())
        );
    }

    #[Test]
    public function theArrayFormIsNotSwallowedByTheNeighbouringAnd(): void
    {
        // "id = 10 OR (id >= 1 AND id <= 3)" then AND status_code = 999.
        // Ungrouped this would read "id = 10 OR id >= 1" first, which is every
        // row. SQL precedence groups the AND-joined fragment for us; this
        // confirms it against the server rather than against the SQL string.
        $expected = $this->expected('id = 10 OR ((id >= 1 AND id <= 3) AND status_code = 999)');

        $this->assertSame([10], $expected);

        $this->assertSame(
            $expected,
            $this->ids(
                User::find()
                    ->where('id', '=', 10)
                    ->orWhere([['id', '>=', 1], ['id', '<=', 3]])
                    ->where('status_code', '=', 999)
                    ->get()
            )
        );
    }

    #[Test]
    public function orWhereWithAClauseExecutes(): void
    {
        // Every user's `created` falls in 2024 in the fixture, so the clause
        // matches rows on its own and the OR is not answered by id = 8 alone.
        $clause = new BetweenDateCondition('created', ['1900-01-01', '1900-12-31'], true);

        $this->assertSame(
            $this->expected(
                'id = 8 OR (created >= ? AND created <= ?)',
                ['1900-01-01', '1900-12-31']
            ),
            $this->ids(User::find()->where('id', '=', 8)->orWhere($clause)->get())
        );
    }

    #[Test]
    public function orWhereWithAClauseMatchesWhereWithTheSameClause(): void
    {
        $viaOrWhere = User::find()
            ->where('id', '=', 8)
            ->orWhere(new BetweenDateCondition('created', ['2024-01-01', '2030-12-31'], true));
        $viaWhere = User::find()
            ->where('id', '=', 8)
            ->where(
                new BetweenDateCondition('created', ['2024-01-01', '2030-12-31'], true),
                logicalOperator: 'OR'
            );

        $rows = $this->ids($viaOrWhere->get());

        $this->assertNotSame([], $rows, 'The clause must match rows on its own.');
        $this->assertSame($this->ids($viaWhere->get()), $rows);
    }

    #[Test]
    public function everyPlaceholderHasABind(): void
    {
        // The clause used to be flattened into the attribute slot and read as a
        // null comparison, leaving one bind for three placeholders. mysqli then
        // failed at execute rather than at the call.
        $query = User::find()
            ->where('id', '=', 8)
            ->orWhere(new BetweenDateCondition('created', ['2024-01-01', '2030-12-31'], true));

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame(substr_count((string)$query, '?'), count($binds));
    }
}
