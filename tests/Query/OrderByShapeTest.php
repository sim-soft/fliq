<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * ORDER BY must mean what the caller wrote.
 *
 * orderBy() accepts an array, and an array can be written two ways: a list of
 * column names, or a map of name => direction. Only the map was handled. A list
 * had its positions read as column names and its names read as directions, so
 * orderBy(['score', 'id']) asked the server to sort by columns "0" and "1" and
 * orderByDesc() on any array sorted ascending. Both are covered here, along
 * with the empty attribute name that used to raise a PHP warning.
 *
 * These need no database: the generated SQL is the whole claim.
 */
class OrderByShapeTest extends TestCase
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

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user')->on('mysql')->select('id');
    }

    /** Everything after ORDER BY, or '' when there is no such clause. */
    private function orderClause(ActiveQuery $query): string
    {
        $sql = $query->getSQL();
        $at = strpos($sql, 'ORDER BY ');

        return $at === false ? '' : substr($sql, $at + 9);
    }

    #[Test]
    public function listArrayUsesValuesAsColumnNames(): void
    {
        // The positions 0 and 1 used to reach the SQL as the column names,
        // which MySQL rejects as "Unknown column 'user.0' in 'order clause'".
        $this->assertSame(
            '`user`.`score` ASC, `user`.`id` ASC',
            $this->orderClause($this->query()->orderBy(['score', 'id']))
        );
    }

    #[Test]
    public function listArrayHonoursTheDirectionArgument(): void
    {
        $this->assertSame(
            '`user`.`score` DESC, `user`.`id` DESC',
            $this->orderClause($this->query()->orderBy(['score', 'id'], 'DESC'))
        );
    }

    #[Test]
    public function orderByDescOnAListSortsDescending(): void
    {
        $this->assertSame(
            '`user`.`score` DESC, `user`.`id` DESC',
            $this->orderClause($this->query()->orderByDesc(['score', 'id']))
        );
    }

    #[Test]
    public function mapArrayKeepsItsOwnDirections(): void
    {
        $this->assertSame(
            '`user`.`first_name` ASC, `user`.`last_name` DESC',
            $this->orderClause($this->query()->orderBy([
                'first_name' => 'ASC',
                'last_name' => 'DESC',
            ]))
        );
    }

    #[Test]
    public function mapDirectionsWinOverTheArgument(): void
    {
        // An explicit per-column direction is more specific than the default.
        $this->assertSame(
            '`user`.`score` ASC',
            $this->orderClause($this->query()->orderByDesc(['score' => 'ASC']))
        );
    }

    #[Test]
    public function listAndMapEntriesMayBeMixed(): void
    {
        $this->assertSame(
            '`user`.`score` ASC, `user`.`id` DESC',
            $this->orderClause($this->query()->orderBy(['score', 'id' => 'DESC']))
        );
    }

    #[Test]
    public function mixedEntriesTakeTheArgumentForTheListPart(): void
    {
        $this->assertSame(
            '`user`.`score` DESC, `user`.`id` ASC',
            $this->orderClause($this->query()->orderBy(['score', 'id' => 'ASC'], 'DESC'))
        );
    }

    #[Test]
    public function singleColumnFormIsUnchanged(): void
    {
        $this->assertSame(
            '`user`.`score` DESC',
            $this->orderClause($this->query()->orderBy('score', 'DESC'))
        );
        $this->assertSame(
            '`user`.`score` DESC',
            $this->orderClause($this->query()->orderByDesc('score'))
        );
    }

    #[Test]
    public function listFormMatchesRepeatedSingleCalls(): void
    {
        $this->assertSame(
            $this->orderClause($this->query()->orderByDesc('score')->orderByDesc('id')),
            $this->orderClause($this->query()->orderByDesc(['score', 'id']))
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeDirections(): array
    {
        return [
            'injection attempt' => ['ASC, (SELECT 1)'],
            'unknown keyword' => ['SIDEWAYS'],
            'empty' => [''],
            'semicolon' => ['DESC; DROP TABLE user'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeDirections')]
    public function directionWhitelistAppliesToTheListForm(string $direction): void
    {
        // The direction is interpolated, not bound, so it must survive the
        // same whitelist whichever shape of orderBy() it arrives through.
        $this->assertSame(
            '`user`.`score` ASC, `user`.`id` ASC',
            $this->orderClause($this->query()->orderBy(['score', 'id'], $direction))
        );
    }

    #[Test]
    #[DataProvider('unsafeDirections')]
    public function directionWhitelistAppliesToTheMapForm(string $direction): void
    {
        $this->assertSame(
            '`user`.`score` ASC',
            $this->orderClause($this->query()->orderBy(['score' => $direction]))
        );
    }

    #[Test]
    public function randomOrderIsRecognisedInEveryShape(): void
    {
        // RAND() is the one value that is deliberately not a column name; the
        // array branch used to quote it into `user`.`RAND()`.
        $this->assertSame('RAND()', $this->orderClause($this->query()->orderBy('RAND()')));
        $this->assertSame('RAND()', $this->orderClause($this->query()->orderByDesc('RAND()')));
        $this->assertSame('RAND()', $this->orderClause($this->query()->orderBy(['RAND()'])));
        $this->assertSame('RAND()', $this->orderClause($this->query()->orderBy(['RAND()' => 'ASC'])));
    }

    #[Test]
    public function emptyArrayAddsNoClause(): void
    {
        $this->assertSame('', $this->orderClause($this->query()->orderBy([])));
    }

    #[Test]
    public function emptyAttributeNameIsRejected(): void
    {
        // This used to raise "Uninitialized string offset 0" and then emit a
        // bare `{}` placeholder that failed as a syntax error at the server.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attribute name must not be empty.');
        $this->query()->orderBy('');
    }

    #[Test]
    public function emptyAttributeNameIsRejectedInTheMapForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query()->orderBy(['' => 'ASC']);
    }

    #[Test]
    public function emptyAttributeNameIsRejectedInTheListForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query()->orderBy(['']);
    }

    /**
     * @return array<string, array{0: callable(ActiveQuery): ActiveQuery}>
     */
    public static function emptyNameEntryPoints(): array
    {
        return [
            'select' => [fn(ActiveQuery $q): ActiveQuery => $q->select('')],
            'where' => [fn(ActiveQuery $q): ActiveQuery => $q->where('', '=', 1)],
            'groupBy' => [fn(ActiveQuery $q): ActiveQuery => $q->groupBy('')],
            'orderBy' => [fn(ActiveQuery $q): ActiveQuery => $q->orderBy('')],
            'in' => [fn(ActiveQuery $q): ActiveQuery => $q->in('', [1])],
            'isNull' => [fn(ActiveQuery $q): ActiveQuery => $q->isNull('')],
            'between' => [fn(ActiveQuery $q): ActiveQuery => $q->between('', 1, 2)],
        ];
    }

    /**
     * @param callable(ActiveQuery): ActiveQuery $build
     */
    #[Test]
    #[DataProvider('emptyNameEntryPoints')]
    public function everyAttributeEntryPointRejectsAnEmptyName(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build($this->query());
    }
}
