<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\DB;
use Simsoft\DB\Exceptions\QueryException;

/**
 * Model property access, checked against the rows the server holds.
 *
 * Everything here was reachable through ordinary property syntax and reported
 * success while doing the wrong thing, so each test asks the database what
 * happened rather than asking the model what it thinks happened:
 *
 *  - reading `$user->delete` as a property invoked delete() and removed the row
 *  - `unset($user->col)` left a stale dirty entry, so save() wrote nothing and
 *    still returned true
 *  - a null primary key produced `WHERE id = ?` bound to null, which matches no
 *    row, so update()/delete() reported success having touched nothing
 *  - a lazy-loaded to-many relation serialized as {} instead of the list
 */
class ModelPropertyAccessTest extends DatabaseTestCase
{
    /** @var array<int, int> Ids of scratch rows to remove after each test. */
    private array $scratch = [];

    protected function tearDown(): void
    {
        if ($this->scratch !== []) {
            DB::raw(
                'DELETE FROM `user` WHERE id IN (' . implode(',', array_fill(0, count($this->scratch), '?')) . ')',
                $this->scratch,
                'mysql'
            );
            $this->scratch = [];
        }

        parent::tearDown();
    }

    #[Test]
    public function readingAMethodNameAsAPropertyDoesNotDeleteTheRow(): void
    {
        $user = $this->scratchUser('probe_delete');
        $id = (int)$user->id;

        // A typo for $user->delete(), or a template printing an unknown key.
        // Read through ArrayAccess, which reaches the same __get() without the
        // test having to declare a property named after a method.
        $value = $user['delete'];

        $this->assertNull($value);
        $this->assertSame(1, $this->rowsWithId($id), 'Reading $user->delete removed the row.');
    }

    #[Test]
    public function readingOtherMethodNamesAsPropertiesHasNoEffect(): void
    {
        $user = $this->scratchUser('probe_methods');
        $id = (int)$user->id;
        $before = $this->userRow($id);

        foreach (['save', 'insert', 'refresh', 'validate', 'toArray', 'getTable'] as $name) {
            $this->assertNull($user[$name], "Reading \$user->$name did not answer null.");
        }

        $this->assertSame($before, $this->userRow($id), 'Reading method names changed the row.');
        $this->assertSame(10 + 1, $this->totalUsers());
    }

    #[Test]
    public function relationsStillLazyLoadWhenReadAsProperties(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $posts = $user->posts;

        $this->assertNotNull($posts);
        $this->assertTrue(is_countable($posts));
        $this->assertSame($this->postCountFor(1), count($posts));
        $this->assertTrue($user->relationLoaded('posts'));
    }

    #[Test]
    public function unsetDropsThePendingChangeInsteadOfSilentlySkippingIt(): void
    {
        $user = $this->scratchUser('probe_unset');
        $id = (int)$user->id;

        $user->score = 4242;
        unset($user->score);

        $this->assertFalse($user->isDirty('score'), 'unset() left a stale dirty entry.');
        $this->assertFalse($user->isDirty());
        $this->assertTrue($user->save());
        $this->assertSame(1, (int)$this->userRow($id)['score'], 'The score changed despite the unset.');
    }

    #[Test]
    public function unsetThenReassignStillWritesTheNewValue(): void
    {
        $user = $this->scratchUser('probe_reassign');
        $id = (int)$user->id;

        unset($user->score);
        $user->score = 77;

        $this->assertTrue($user->isDirty('score'));
        $this->assertTrue($user->save());
        $this->assertSame(77, (int)$this->userRow($id)['score']);
    }

    #[Test]
    public function saveRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $user = $this->scratchUser('probe_nokey_save');
        $id = (int)$user->id;

        $user->score = 999;
        unset($user->id);

        $this->expectException(QueryException::class);

        try {
            $user->save();
        } finally {
            $this->assertSame(1, (int)$this->userRow($id)['score'], 'The row was written after all.');
        }
    }

    #[Test]
    public function deleteRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $user = $this->scratchUser('probe_nokey_delete');
        $id = (int)$user->id;

        // Assigning null, rather than unsetting: a key can go missing either
        // way and both must be refused.
        $user['id'] = null;

        try {
            $user->delete();
            $this->fail('delete() reported success with a null primary key.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('the key attribute "id" is null', $e->getMessage());
        }

        $this->assertSame(1, $this->rowsWithId($id), 'The row was deleted after all.');
    }

    #[Test]
    public function updateAttributesRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $user = $this->scratchUser('probe_nokey_attrs');
        $id = (int)$user->id;

        unset($user->id);

        $this->expectException(QueryException::class);

        try {
            $user->updateAttributes(['score' => 555]);
        } finally {
            $this->assertSame(1, (int)$this->userRow($id)['score']);
        }
    }

    #[Test]
    public function updateCounterRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $user = $this->scratchUser('probe_nokey_counter');
        $id = (int)$user->id;

        unset($user->id);

        $this->expectException(QueryException::class);

        try {
            $user->updateCounter('score', 10);
        } finally {
            $this->assertSame(1, (int)$this->userRow($id)['score']);
        }
    }

    #[Test]
    public function deletingWithAKeyStillWorks(): void
    {
        $user = $this->scratchUser('probe_realdelete');
        $id = (int)$user->id;

        $this->assertTrue($user->delete());
        $this->assertSame(0, $this->rowsWithId($id));

        $this->scratch = [];
    }

    #[Test]
    public function aLazyLoadedToManyRelationSerializesAsAList(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->posts);

        $array = $user->toArray();

        $this->assertIsArray($array['posts'], 'A lazy-loaded relation was not serialized as a list.');
        $this->assertCount($this->postCountFor(1), $array['posts']);
        $this->assertStringNotContainsString('"posts":{}', $user->toJson());
    }

    #[Test]
    public function eagerAndLazyLoadedRelationsSerializeIdentically(): void
    {
        $eager = User::find()->where('id', 1)->with('posts')->first();
        $lazy = User::findByPk(1);
        $this->assertInstanceOf(User::class, $eager);
        $this->assertInstanceOf(User::class, $lazy);
        $this->assertNotNull($lazy->posts);

        $this->assertSame($eager->toArray(), $lazy->toArray());
        $this->assertSame($eager->toJson(), $lazy->toJson());
    }

    #[Test]
    public function aUserWithNoPostsSerializesAsAnEmptyList(): void
    {
        $rows = DB::query(
            'SELECT u.id FROM `user` u LEFT JOIN post p ON p.user_id = u.id WHERE p.id IS NULL LIMIT 1',
            [],
            'mysql'
        );

        if ($rows === []) {
            $this->markTestSkipped('Every user in the fixture has posts.');
        }

        $user = User::findByPk((int)$rows[0]['id']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->posts);

        $this->assertSame([], $user->toArray()['posts']);
        $this->assertStringContainsString('"posts":[]', $user->toJson());
    }

    #[Test]
    public function issetAgreesWithReadingALoadedRelation(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $profile = $user->profile;

        $this->assertNotNull($profile, 'User 1 has no profile in the fixture.');
        $this->assertTrue(isset($user->profile), 'isset() disagreed with reading the relation.');
        $this->assertSame($profile, $user->profile ?? 'fallback');
    }

    /**
     * Create a scratch user row, registered for removal in tearDown().
     *
     * @param string $name The username, also used for the email.
     * @return User The saved model.
     */
    private function scratchUser(string $name): User
    {
        $user = new User();
        $user->username = $name;
        $user->email = $name . '@probe.test';
        $user->password = 'x';
        $user->role = 'member';
        $user->score = 1;
        $user->department_id = 1;
        $user->status_code = 1;

        $this->assertTrue($user->save(), 'The scratch row could not be created.');

        $id = $user->id;
        $this->assertNotNull($id);
        $this->scratch[] = (int)$id;

        return $user;
    }

    /**
     * Read a user row straight from the server.
     *
     * @param int $id The user id.
     * @return array<string, mixed> The row.
     */
    private function userRow(int $id): array
    {
        $rows = DB::query('SELECT * FROM `user` WHERE id = ?', [$id], 'mysql');
        $this->assertNotSame([], $rows, "User $id is not in the table.");

        return $rows[0];
    }

    /**
     * Count the rows carrying an id.
     *
     * @param int $id The user id.
     * @return int
     */
    private function rowsWithId(int $id): int
    {
        return (int)DB::query('SELECT COUNT(*) AS c FROM `user` WHERE id = ?', [$id], 'mysql')[0]['c'];
    }

    /**
     * Count every row in the user table.
     *
     * @return int
     */
    private function totalUsers(): int
    {
        return (int)DB::query('SELECT COUNT(*) AS c FROM `user`', [], 'mysql')[0]['c'];
    }

    /**
     * Count the posts belonging to a user, per the server.
     *
     * @param int $userId The user id.
     * @return int
     */
    private function postCountFor(int $userId): int
    {
        return (int)DB::query('SELECT COUNT(*) AS c FROM post WHERE user_id = ?', [$userId], 'mysql')[0]['c'];
    }
}
