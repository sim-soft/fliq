<?php

namespace Integration;

use InvalidArgumentException;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\DB;

/**
 * The array forms of orderBy() must sort the way the server sorts.
 *
 * The unit tests pin the generated SQL; these run it. A list array used to
 * produce ORDER BY `user`.`0`, which is not a syntax error — it is a query the
 * server rejects at execution with "Unknown column". Nothing short of running
 * it would have caught that, and orderByDesc() on an array returned rows in
 * exactly the wrong order without erroring at all.
 */
class OrderByArrayTest extends DatabaseTestCase
{
    /**
     * The ids the server returns for a given ORDER BY, as ground truth.
     *
     * @param string $orderBy The raw ORDER BY tail.
     * @return array<int, int>
     */
    private function expectedIds(string $orderBy): array
    {
        return array_map(
            static fn(array $row): int => (int)$row['id'],
            DB::query("SELECT id FROM user ORDER BY $orderBy", [], 'mysql')
        );
    }

    /**
     * @param iterable<mixed> $users
     * @return array<int, int>
     */
    private function idsOf(iterable $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            $ids[] = (int)$user['id'];
        }

        return $ids;
    }

    #[Test]
    public function listArraySortsByTheNamedColumns(): void
    {
        $this->assertSame(
            $this->expectedIds('score ASC, id ASC'),
            $this->idsOf(User::find()->orderBy(['score', 'id'])->get())
        );
    }

    #[Test]
    public function listArrayWithDescendingArgument(): void
    {
        $this->assertSame(
            $this->expectedIds('score DESC, id DESC'),
            $this->idsOf(User::find()->orderBy(['score', 'id'], 'DESC')->get())
        );
    }

    #[Test]
    public function orderByDescOnAListSortsDescending(): void
    {
        $this->assertSame(
            $this->expectedIds('score DESC, id DESC'),
            $this->idsOf(User::find()->orderByDesc(['score', 'id'])->get())
        );
    }

    #[Test]
    public function orderByDescOnAListIsNotAscending(): void
    {
        // The defect returned precisely the reverse of the right answer, which
        // an order-insensitive assertion would have passed.
        $ascending = $this->expectedIds('score ASC, id ASC');
        $actual = $this->idsOf(User::find()->orderByDesc(['score', 'id'])->get());

        $this->assertNotSame($ascending, $actual);
        $this->assertSame(array_reverse($ascending), $actual);
    }

    #[Test]
    public function mapArrayKeepsPerColumnDirections(): void
    {
        $this->assertSame(
            $this->expectedIds('role DESC, score ASC'),
            $this->idsOf(User::find()->orderBy(['role' => 'DESC', 'score' => 'ASC'])->get())
        );
    }

    #[Test]
    public function mixedListAndMapEntries(): void
    {
        $this->assertSame(
            $this->expectedIds('role ASC, score DESC'),
            $this->idsOf(User::find()->orderBy(['role', 'score' => 'DESC'])->get())
        );
    }

    #[Test]
    public function listFormAgreesWithRepeatedSingleCalls(): void
    {
        $this->assertSame(
            $this->idsOf(User::find()->orderByDesc('score')->orderByDesc('id')->get()),
            $this->idsOf(User::find()->orderByDesc(['score', 'id'])->get())
        );
    }

    #[Test]
    public function highestScoringUserComesFirst(): void
    {
        /** @var \Models\User $user */
        $user = User::find()->orderByDesc(['score'])->limit(1)->first();

        $this->assertSame('alice', $user->username);
        $this->assertSame(95, (int)$user->score);
    }

    #[Test]
    public function randomOrderStillExecutes(): void
    {
        // RAND() through the array branch used to be quoted as a column name.
        $users = User::find()->orderBy(['RAND()'])->limit(3)->get()->all();

        $this->assertCount(3, $users);
    }

    #[Test]
    public function emptyAttributeNameIsRejectedBeforeReachingTheServer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        User::find()->orderBy('');
    }
}
