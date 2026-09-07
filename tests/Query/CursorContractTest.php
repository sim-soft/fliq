<?php

namespace Query;

use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * @property int|null $id
 * @property string|null $name
 * @property array<CursorPost>|null $posts
 */
class CursorUser extends Model
{
    protected string $table = 'users';
    protected string $connection = 'cursor_contract';
    protected array $fillable = ['name'];

    public function posts(): Relation
    {
        return $this->hasMany(CursorPost::class, ['user_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property int|null $user_id
 */
class CursorPost extends Model
{
    protected string $table = 'posts';
    protected string $connection = 'cursor_contract';
    protected array $fillable = ['user_id'];
}

/**
 * What cursor() must answer regardless of which driver is underneath it.
 *
 * cursor() had its own fetch loop, written beside the one in getArray() rather
 * than on top of it, and it quietly disagreed with it. indexBy() was honoured
 * on a mysqli connection — which has no getPdo(), so cursor() falls back to
 * all() — and dropped on every PDO connection. with() was the same: honoured on
 * the fallback path, silently ignored on the streaming one. Neither said
 * anything. These tests run on SQLite, which takes the streaming path, and
 * assert the answers the buffered path already gave.
 */
class CursorContractTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('cursor_contract', ['driver' => 'sqlite', 'database' => ':memory:']);

        $driver = Connection::get('cursor_contract');
        $driver->execute(new Raw('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
        $driver->execute(new Raw('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER)'));

        foreach ([[1, 'Alice'], [2, 'Bob'], [3, 'Carol']] as [$id, $name]) {
            $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [$id, $name]));
        }

        // Alice has two posts, Bob one, Carol none.
        foreach ([[1, 1], [2, 1], [3, 2]] as [$id, $userId]) {
            $driver->execute(new Raw('INSERT INTO posts (id, user_id) VALUES (?, ?)', [$id, $userId]));
        }
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * The keys a generator yields, in order.
     *
     * @param Generator $rows The cursor.
     * @return array<int, mixed>
     */
    private function keysOf(Generator $rows): array
    {
        $keys = [];

        foreach ($rows as $key => $ignored) {
            $keys[] = $key;
        }

        return $keys;
    }

    #[Test]
    public function aCursorYieldsEveryRowInOrder(): void
    {
        $ids = [];

        foreach (CursorUser::find()->orderBy('id')->cursor() as $user) {
            $ids[] = (int)$user->id;
        }

        $this->assertSame([1, 2, 3], $ids);
    }

    #[Test]
    public function aCursorYieldsModelsWhenThereIsAModel(): void
    {
        foreach (CursorUser::find()->orderBy('id')->cursor() as $user) {
            $this->assertInstanceOf(CursorUser::class, $user);
            $this->assertSame('Alice', $user->name);
            // A row that came from the database is not a pending change.
            $this->assertFalse($user->isDirty());
            return;
        }

        $this->fail('the cursor yielded nothing');
    }

    #[Test]
    public function aCursorYieldsArraysWhenThereIsNoModel(): void
    {
        $query = (new ActiveQuery())->from('users')->withConnection('cursor_contract')->orderBy('id');

        foreach ($query->cursor() as $row) {
            $this->assertIsArray($row);
            $this->assertSame('Alice', $row['name']);
            return;
        }

        $this->fail('the cursor yielded nothing');
    }

    #[Test]
    public function aCursorOverNoRowsYieldsNothingRatherThanFailing(): void
    {
        $this->assertSame([], iterator_to_array(CursorUser::find()->where('id', 9999)->cursor()));
    }

    #[Test]
    public function indexByKeysACursorTheSameWayItKeysAllOfThem(): void
    {
        // The defect exactly: these two disagreed, and only on PDO drivers.
        $viaAll = $this->keysOf((function (): Generator {
            yield from CursorUser::find()->indexBy('name')->orderBy('id')->all();
        })());

        $viaCursor = $this->keysOf(CursorUser::find()->indexBy('name')->orderBy('id')->cursor());

        $this->assertSame(['Alice', 'Bob', 'Carol'], $viaCursor);
        $this->assertSame($viaAll, $viaCursor);
    }

    #[Test]
    public function indexByTakesAClosureOnACursorToo(): void
    {
        $keys = $this->keysOf(
            CursorUser::find()
                ->indexBy(static fn(array $row): string => 'u' . $row['id'])
                ->orderBy('id')
                ->cursor()
        );

        $this->assertSame(['u1', 'u2', 'u3'], $keys);
    }

    #[Test]
    public function indexByKeysARawCursorAsWell(): void
    {
        $query = (new ActiveQuery())->from('users')
            ->withConnection('cursor_contract')
            ->indexBy('name')
            ->orderBy('id');

        $this->assertSame(['Alice', 'Bob', 'Carol'], $this->keysOf($query->cursor()));
    }

    #[Test]
    public function eagerLoadingIsRefusedRatherThanIgnored(): void
    {
        // Returning models with the relation quietly unloaded is the one answer
        // that cannot be told apart from a parent that has no related rows.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/cannot eager load posts/');

        iterator_to_array(CursorUser::find()->with('posts')->cursor());
    }

    #[Test]
    public function theRefusalNamesWhatToDoInstead(): void
    {
        try {
            iterator_to_array(CursorUser::find()->with('posts')->cursor());
            $this->fail('with() + cursor() should not have been accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('each()', $e->getMessage());
        }
    }

    #[Test]
    public function theRefusalHappensBeforeAnyRowIsFetched(): void
    {
        // A generator body does not run until it is first advanced, so the
        // guard has to be reached by the time the caller asks for a row —
        // not left until after a statement is on the wire.
        $cursor = CursorUser::find()->with('posts')->cursor();

        $this->expectException(QueryException::class);

        $cursor->current();
    }

    #[Test]
    public function aCursorWithoutWithStillLoadsRelationsLazily(): void
    {
        // Refusing with() must not take away the ordinary relation read, which
        // is what the error message tells the caller to fall back to.
        $counts = [];

        foreach (CursorUser::find()->orderBy('id')->cursor() as $user) {
            $counts[(int)$user->id] = count($user->posts()->fetch());
        }

        $this->assertSame([1 => 2, 2 => 1, 3 => 0], $counts);
    }

    #[Test]
    public function breakingOutOfACursorLeavesTheConnectionUsable(): void
    {
        foreach (CursorUser::find()->orderBy('id')->cursor() as $user) {
            break;
        }

        $this->assertSame(3, CursorUser::find()->count());
    }

    #[Test]
    public function throwingInsideACursorLeavesTheConnectionUsable(): void
    {
        try {
            foreach (CursorUser::find()->orderBy('id')->cursor() as $user) {
                throw new RuntimeException('the caller blew up');
            }
        } catch (RuntimeException $e) {
            $this->assertSame('the caller blew up', $e->getMessage());
        }

        $this->assertSame(3, CursorUser::find()->count());
    }

    #[Test]
    public function abandoningACursorLeavesTheConnectionUsable(): void
    {
        $cursor = CursorUser::find()->orderBy('id')->cursor();
        $cursor->current();
        unset($cursor);
        gc_collect_cycles();

        $this->assertSame(3, CursorUser::find()->count());
    }

    #[Test]
    public function aCursorCanBeTakenTwiceFromTheSameQuery(): void
    {
        $query = CursorUser::find()->orderBy('id');

        $this->assertCount(3, iterator_to_array($query->cursor()));
        $this->assertCount(3, iterator_to_array($query->cursor()));
    }

    #[Test]
    public function aCursorAppliesConditionsAndLimits(): void
    {
        $ids = [];

        foreach (CursorUser::find()->where('id', '>', 1)->orderBy('id')->limit(1)->cursor() as $user) {
            $ids[] = (int)$user->id;
        }

        $this->assertSame([2], $ids);
    }

    #[Test]
    public function aCursorSelectsOnlyTheRequestedColumns(): void
    {
        foreach (CursorUser::find()->select('id')->orderBy('id')->cursor() as $user) {
            $this->assertSame(['id'], array_keys($user->toArray()));
            return;
        }

        $this->fail('the cursor yielded nothing');
    }
}
