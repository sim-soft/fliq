<?php

namespace Integration;

use Models\Post;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Relation;

/**
 * A related model that refuses to save.
 *
 * @property int $id
 */
class RefusingPost extends Post
{
    public function validate(): bool
    {
        $this->addError('post refused to save');

        return false;
    }
}

/**
 * A hasOne target that refuses to save.
 */
class RefusingProfile extends UserProfile
{
    public function validate(): bool
    {
        return false;
    }
}

/**
 * A user whose relations point at models that refuse.
 */
class UserWithRefusingRelations extends User
{
    public function posts(): Relation
    {
        return $this->hasMany(RefusingPost::class, ['user_id' => 'id']);
    }

    public function profile(): Relation
    {
        return $this->hasOne(RefusingProfile::class, ['user_id' => 'id']);
    }
}

/**
 * What happens when a related record does not save.
 *
 * `Relation::save()` called `$model->save()` and threw the result away, then
 * returned the model. A refusal — from validation, or from a `beforeSave` hook
 * returning false — was therefore indistinguishable from a write: same return
 * type, no exception, and for an update `exists()` was still true.
 *
 * `saveTogether()` sits directly on top of that and returned **true** while
 * committing the parent and none of its children. Its own documentation
 * promises "if any part fails, everything rolls back", and the transaction
 * machinery was fine — it was simply never told anything had gone wrong.
 *
 * Worse at depth: the parent record silently failed, so its child was saved
 * against a foreign key of NULL.
 */
class RelationSaveFailureTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $driver = Connection::get('mysql');
            $driver->execute(new Raw("DELETE FROM `comment` WHERE `body` LIKE '[relfail]%'"));
            $driver->execute(new Raw("DELETE FROM `post` WHERE `slug` LIKE 'relfail-%'"));
            $driver->execute(new Raw("DELETE FROM `user_profile` WHERE `first_name` = '[relfail]'"));
            $driver->execute(new Raw("DELETE FROM `user` WHERE `username` LIKE '[relfail]%'"));
        }

        parent::tearDown();
    }

    /**
     * Attributes for a valid, saveable user.
     *
     * @param string $suffix Distinguishes one test's user from another's.
     * @return array<string, mixed>
     */
    private function userAttributes(string $suffix): array
    {
        return [
            'username' => "[relfail]$suffix",
            'email' => "relfail-$suffix@test.com",
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ];
    }

    /**
     * Rows currently in a table.
     *
     * @param string $table The table to count.
     * @return int
     */
    private function rowsIn(string $table): int
    {
        $rows = Connection::get('mysql')->query(new Raw("SELECT COUNT(*) AS `c` FROM `$table`"));

        return (int)($rows[0]['c'] ?? -1);
    }

    // ------------------------------------------------------------------
    // Relation::save()
    // ------------------------------------------------------------------

    #[Test]
    public function relationSaveReportsARefusal(): void
    {
        $user = new User();
        $user->fill($this->userAttributes('rs1'));
        $user->save();

        $this->expectException(QueryException::class);

        $user->hasMany(RefusingPost::class, ['user_id' => 'id'])->save([
            'title' => 'T', 'slug' => 'relfail-1', 'body' => 'B', 'category_id' => 1, 'status_code' => 1,
        ]);
    }

    #[Test]
    public function theRefusalCarriesTheModelsOwnErrors(): void
    {
        $user = new User();
        $user->fill($this->userAttributes('rs2'));
        $user->save();

        // The model already knows why it refused; repeating "save failed" and
        // dropping that leaves the caller to guess.
        $this->expectExceptionMessageMatches('/post refused to save/');

        $user->hasMany(RefusingPost::class, ['user_id' => 'id'])->save([
            'title' => 'T', 'slug' => 'relfail-2', 'body' => 'B', 'category_id' => 1, 'status_code' => 1,
        ]);
    }

    #[Test]
    public function nothingIsWrittenWhenTheRelatedModelRefuses(): void
    {
        $user = new User();
        $user->fill($this->userAttributes('rs3'));
        $user->save();

        $before = $this->rowsIn('post');

        try {
            $user->hasMany(RefusingPost::class, ['user_id' => 'id'])->save([
                'title' => 'T', 'slug' => 'relfail-3', 'body' => 'B', 'category_id' => 1, 'status_code' => 1,
            ]);
        } catch (QueryException) {
            // Expected; the point is what is on disk afterwards.
        }

        $this->assertSame($before, $this->rowsIn('post'));
    }

    #[Test]
    public function saveManyReportsARefusalToo(): void
    {
        $user = new User();
        $user->fill($this->userAttributes('rs4'));
        $user->save();

        $this->expectException(QueryException::class);

        $user->hasMany(RefusingPost::class, ['user_id' => 'id'])->saveMany([
            ['title' => 'T', 'slug' => 'relfail-4', 'body' => 'B', 'category_id' => 1, 'status_code' => 1],
        ]);
    }

    #[Test]
    public function aSuccessfulRelationSaveStillReturnsTheSavedModel(): void
    {
        $user = new User();
        $user->fill($this->userAttributes('rs5'));
        $user->save();

        $post = $user->getPosts()->save([
            'title' => 'Kept', 'slug' => 'relfail-ok', 'body' => 'B', 'category_id' => 1, 'status_code' => 1,
        ]);

        $this->assertInstanceOf(Post::class, $post);
        $this->assertTrue($post->exists());
        $this->assertSame((int)$user->id, (int)$post->user_id);
    }

    // ------------------------------------------------------------------
    // saveTogether()
    // ------------------------------------------------------------------

    #[Test]
    public function saveTogetherDoesNotCommitTheParentWhenAHasManyChildRefuses(): void
    {
        $before = $this->rowsIn('user');

        try {
            (new UserWithRefusingRelations())->saveTogether([
                ...$this->userAttributes('hm'),
                'posts' => [
                    ['title' => 'T', 'slug' => 'relfail-hm', 'body' => 'B', 'category_id' => 1, 'status_code' => 1],
                ],
            ], validate: false);
        } catch (QueryException) {
            // Expected.
        }

        // The parent used to be committed on its own — a user with none of the
        // posts the same call was supposed to create.
        $this->assertSame($before, $this->rowsIn('user'));
    }

    #[Test]
    public function saveTogetherDoesNotReportSuccessWhenAChildRefuses(): void
    {
        $this->expectException(QueryException::class);

        (new UserWithRefusingRelations())->saveTogether([
            ...$this->userAttributes('hm2'),
            'posts' => [
                ['title' => 'T', 'slug' => 'relfail-hm2', 'body' => 'B', 'category_id' => 1, 'status_code' => 1],
            ],
        ], validate: false);
    }

    #[Test]
    public function saveTogetherDoesNotCommitTheParentWhenAHasOneChildRefuses(): void
    {
        $usersBefore = $this->rowsIn('user');
        $profilesBefore = $this->rowsIn('user_profile');

        try {
            (new UserWithRefusingRelations())->saveTogether([
                ...$this->userAttributes('ho'),
                'profile' => ['first_name' => '[relfail]', 'last_name' => 'X'],
            ], validate: false);
        } catch (QueryException) {
            // Expected.
        }

        // hasOne and hasMany went through two near-identical private methods,
        // so a fix to one could easily have missed the other.
        $this->assertSame($usersBefore, $this->rowsIn('user'));
        $this->assertSame($profilesBefore, $this->rowsIn('user_profile'));
    }

    #[Test]
    public function aNestedChildIsNotSavedAgainstAParentThatWasNeverWritten(): void
    {
        $commentsBefore = $this->rowsIn('comment');

        try {
            (new UserWithRefusingRelations())->saveTogether([
                ...$this->userAttributes('nest'),
                'posts' => [[
                    'title' => 'T', 'slug' => 'relfail-nest', 'body' => 'B', 'category_id' => 1, 'status_code' => 1,
                    'comments' => [
                        ['body' => '[relfail] orphan', 'user_id' => 1, 'status_code' => 1],
                    ],
                ]],
            ], validate: false);
        } catch (QueryException) {
            // Expected.
        }

        // The post silently failed, so the comment was saved with post_id NULL
        // — which only surfaced because the column happens to be NOT NULL. A
        // nullable foreign key would have written the orphan and said nothing.
        $this->assertSame($commentsBefore, $this->rowsIn('comment'));
    }

    #[Test]
    public function theHappyPathIsUnchanged(): void
    {
        $user = new User();

        $result = $user->saveTogether([
            ...$this->userAttributes('happy'),
            'profile' => ['first_name' => '[relfail]', 'last_name' => 'Ok'],
            'posts' => [
                [
                    'title' => 'P1', 'slug' => 'relfail-happy-1', 'body' => 'B', 'category_id' => 1, 'status_code' => 1,
                    'comments' => [['body' => '[relfail] kept', 'user_id' => 1, 'status_code' => 1]],
                ],
                new Post(['title' => 'P2', 'slug' => 'relfail-happy-2', 'body' => 'B', 'category_id' => 1, 'status_code' => 1]),
            ],
        ]);

        $this->assertTrue($result);
        $this->assertTrue($user->exists());
        $this->assertCount(2, $user->getPosts()->fetch()->all());

        $posts = $user->getPosts()->fetch()->all();
        $this->assertCount(1, $posts[0]->getComments()->fetch()->all());
    }

    #[Test]
    public function aModelInstanceThatRefusesIsReportedAsWell(): void
    {
        // The Model-instance arm and the attributes-array arm are different
        // branches; both hand off to Relation::save(), and both must report.
        $this->expectException(QueryException::class);

        (new UserWithRefusingRelations())->saveTogether([
            ...$this->userAttributes('inst'),
            'posts' => [
                new RefusingPost(['title' => 'T', 'slug' => 'relfail-inst', 'body' => 'B', 'category_id' => 1, 'status_code' => 1]),
            ],
        ], validate: false);
    }
}
