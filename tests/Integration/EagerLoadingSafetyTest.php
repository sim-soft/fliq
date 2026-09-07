<?php

namespace Integration;

use Models\Post;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\EagerLoader;

/**
 * What with() must refuse to do, and what it must survive.
 *
 * `with()` takes a relation name and eventually calls it as a method. The guard
 * was method_exists(), which is precisely the guard ResolvesRelations was
 * written to replace in __get() after reading `$user->delete` as a property
 * turned out to run the delete. The same hole was still open here, one call
 * site away from the fix, so `with('delete')` deleted every row the query had
 * just selected — during a read, with no error, and with the models handed back
 * as though nothing had happened.
 *
 * The keying tests cover the other way in: loadRelation() read $models[0], and
 * an indexBy()-keyed set has no key 0.
 */
class EagerLoadingSafetyTest extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (static::$dbAvailable) {
            (new Raw("DELETE FROM user WHERE username LIKE 'eager\\_probe\\_%'"))->execute();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$dbAvailable) {
            (new Raw("DELETE FROM user WHERE username LIKE 'eager\\_probe\\_%'"))->execute();
        }

        parent::tearDownAfterClass();
    }

    /**
     * Insert a row this test is allowed to lose, and answer with its username.
     *
     * @param string $suffix Distinguishes one test's row from another's.
     * @return string
     */
    private function expendableRow(string $suffix): string
    {
        $username = 'eager_probe_' . $suffix;

        (new Raw('DELETE FROM user WHERE username = ?', [$username]))->execute();
        (new Raw(
            'INSERT INTO user (username, email, password, role, score, department_id, status_code)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$username, $username . '@x.test', 'x', 'member', 1, 1, 1]
        ))->execute();

        return $username;
    }

    /**
     * How many rows carry this username.
     *
     * @param string $username The username to count.
     * @return int
     */
    private function rowsNamed(string $username): int
    {
        return (int)(new Raw('SELECT COUNT(*) AS c FROM user WHERE username = ?', [$username]))
            ->fetchAll()[0]['c'];
    }

    /**
     * Method names that exist on a Model but are not relations.
     *
     * `delete` is the one that caused damage. The others are ordinary methods a
     * caller could plausibly pass by mistake — a typo, or a name taken from
     * configuration — and each would have been called by the old guard.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function nonRelationMethods(): array
    {
        return [
            'delete' => ['delete'],
            'save' => ['save'],
            'toArray' => ['toArray'],
            'getErrors' => ['getErrors'],
            'refresh' => ['refresh'],
        ];
    }

    /**
     * @param non-empty-string $method A real method that is not a relation.
     * @return void
     */
    #[Test]
    #[DataProvider('nonRelationMethods')]
    public function withRefusesToCallAMethodThatIsNotARelation(string $method): void
    {
        $username = $this->expendableRow($method);
        $before = $this->rowsNamed($username);
        $this->assertSame(1, $before);

        $models = iterator_to_array(User::find()->where('username', $username)->with($method)->get());

        $this->assertCount(1, $models);
        $this->assertSame($before, $this->rowsNamed($username), "with('$method') changed the row");

        // And the name is not reported as a loaded relation, so reading it
        // still behaves as the absent attribute it is.
        $this->assertFalse(reset($models)->relationLoaded($method));
    }

    #[Test]
    public function withDeleteLeavesEveryRowItSelected(): void
    {
        // Stated on its own, against the whole table, because the damage was
        // proportional to the result set: every selected row was deleted.
        $username = $this->expendableRow('mass');
        $before = (int)(new Raw('SELECT COUNT(*) AS c FROM user'))->fetchAll()[0]['c'];

        iterator_to_array(User::find()->with('delete')->get());

        $this->assertSame($before, (int)(new Raw('SELECT COUNT(*) AS c FROM user'))->fetchAll()[0]['c']);
        $this->assertSame(1, $this->rowsNamed($username));
    }

    #[Test]
    public function withANameThatDoesNotExistIsIgnored(): void
    {
        $models = iterator_to_array(User::find()->limit(2)->with('nosuchrelation')->get());

        $this->assertCount(2, $models);
        $this->assertFalse(reset($models)->relationLoaded('nosuchrelation'));
    }

    #[Test]
    public function eagerLoadingWorksOnAnIndexByKeyedSet(): void
    {
        // with() and indexBy() are both documented and every combination of
        // them raised "Undefined array key 0" and then a TypeError, because the
        // loader read $models[0] of an array keyed by username.
        $truth = [];
        foreach ((new Raw('SELECT user_id, COUNT(*) AS c FROM post GROUP BY user_id'))->fetchAll() as $row) {
            $truth[(int)$row['user_id']] = (int)$row['c'];
        }

        $keyed = User::find()->with('posts')->indexBy('username')->orderBy('id')->limit(4)->get();

        $seen = 0;
        foreach ($keyed as $username => $user) {
            $this->assertIsString($username);
            $this->assertSame($username, $user->username);
            $this->assertTrue($user->relationLoaded('posts'));
            $this->assertCount($truth[(int)$user->id] ?? 0, (array)$user->posts);
            ++$seen;
        }

        $this->assertSame(4, $seen);
    }

    #[Test]
    public function loadRelationsAcceptsAnyArrayKeys(): void
    {
        // The same defect at the API the collection calls, so it is covered
        // whether or not indexBy() is the route that reaches it.
        $models = [];
        foreach (User::find()->orderBy('id')->limit(3)->get() as $user) {
            $models['user-' . $user->id] = $user;
        }

        EagerLoader::loadRelations($models, ['posts']);

        foreach ($models as $user) {
            $this->assertTrue($user->relationLoaded('posts'));
        }
    }

    #[Test]
    public function loadRelationsOnAnEmptySetIsANoOp(): void
    {
        $this->assertSame([], EagerLoader::loadRelations([], ['posts']));
    }

    #[Test]
    public function aConstrainedSelectThatOmitsTheForeignKeyStillGroups(): void
    {
        // The documented example includes the foreign key in its projection,
        // which is what kept it working. Omitting it fetched the right rows and
        // then discarded every one of them, reporting zero posts for users who
        // have them — a projection silently deciding how many records exist.
        $truth = [];
        foreach ((new Raw(
            'SELECT user_id, COUNT(*) AS c FROM post WHERE user_id IN (1, 2, 3) GROUP BY user_id'
        ))->fetchAll() as $row) {
            $truth[(int)$row['user_id']] = (int)$row['c'];
        }

        $users = User::find()->whereIn('id', [1, 2, 3])
            ->with(['posts' => fn($query) => $query->select('title')])
            ->orderBy('id')
            ->get();

        $seen = 0;
        foreach ($users as $user) {
            $this->assertCount($truth[(int)$user->id], (array)$user->posts);
            ++$seen;
        }

        $this->assertSame(3, $seen);
        $this->assertNotSame([], $truth);
    }

    #[Test]
    public function aConstrainedSelectStillReturnsOnlyTheRequestedColumns(): void
    {
        // Appending the grouping key must not widen what the caller asked for.
        $user = User::find()->where('id', 1)
            ->with(['posts' => fn($query) => $query->select('title')])
            ->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertNotSame([], $user->posts);

        foreach ((array)$user->posts as $post) {
            $this->assertSame(['title', 'user_id'], array_keys($post->toArray()));
        }
    }

    #[Test]
    public function anUnconstrainedRelationStillSelectsEverything(): void
    {
        // Naming any column turns the implicit SELECT * off, so the grouping
        // key must not be added to a query that asked for nothing.
        $columns = array_column((new Raw('SHOW COLUMNS FROM post'))->fetchAll(), 'Field');

        $user = User::find()->where('id', 1)->with('posts')->first();
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotSame([], $user->posts);

        foreach ((array)$user->posts as $post) {
            $this->assertSame([], array_diff($columns, array_keys($post->toArray())));
        }
    }

    #[Test]
    public function hasOneStillAnswersWithOneModel(): void
    {
        $user = User::find()->where('id', 1)->with('profile')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertInstanceOf(UserProfile::class, $user->profile);
    }

    #[Test]
    public function hasOneAnswersWithNullWhenThereIsNoRelatedRow(): void
    {
        $orphan = (new Raw(
            'SELECT u.id FROM user u LEFT JOIN user_profile p ON p.user_id = u.id'
            . ' WHERE p.user_id IS NULL LIMIT 1'
        ))->fetchAll();

        if ($orphan === []) {
            $this->markTestSkipped('Every user in the fixture has a profile.');
        }

        $user = User::find()->where('id', $orphan[0]['id'])->with('profile')->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertNull($user->profile);
    }

    #[Test]
    public function aPostStillReadsItsAuthorThroughAnInverseRelation(): void
    {
        $truth = (new Raw('SELECT user_id FROM post WHERE id = 1'))->fetchAll()[0]['user_id'];

        $post = Post::find()->where('id', 1)->with('user')->first();

        $this->assertInstanceOf(Post::class, $post);
        $this->assertInstanceOf(User::class, $post->user);
        $this->assertSame((int)$truth, (int)$post->user->id);
    }
}
