<?php

namespace Integration;

use Models\Post;
use Models\PostTag;
use Models\Setting;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Collection;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Model;

/**
 * A model with attribute aliases, to exercise the alias remap in fill().
 */
class AliasedUser extends User
{
    protected array $aliasAttributes = [
        'user_name' => 'username',
        'mail' => 'email',
    ];
}

/**
 * The parts of Model that read or write whole records.
 *
 * Every expectation here is checked against what the server actually holds
 * rather than against the SQL that was built, because the interesting failures
 * in this area — a condition that matched every row, a copy that kept the key
 * it was supposed to drop — produce perfectly plausible SQL.
 */
class ModelSurfaceTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            Connection::get('mysql')->execute(
                new Raw("DELETE FROM `user` WHERE `username` LIKE '[surface]%'")
            );
        }

        parent::tearDown();
    }

    /**
     * The single number a counting query answers with.
     *
     * @param string $sql The query to run.
     * @param array<int, mixed> $binds Bind values.
     * @return int
     */
    private function scalar(string $sql, array $binds = []): int
    {
        $rows = Connection::get('mysql')->query(new Raw($sql, $binds));

        return (int)array_values($rows[0])[0];
    }

    // ------------------------------------------------------------------
    // findAll()
    // ------------------------------------------------------------------

    #[Test]
    public function findAllTurnsAnArrayIntoAnInClause(): void
    {
        $this->assertCount(
            $this->scalar("SELECT COUNT(*) FROM `user` WHERE `role` IN ('admin','editor')"),
            User::findAll(['role' => ['admin', 'editor']])
        );
    }

    #[Test]
    public function findAllTurnsNullIntoIsNull(): void
    {
        // `= NULL` matches nothing in SQL, so a null that reached the equality
        // branch would answer with an empty set rather than the nine rows that
        // have never been soft-deleted.
        $expected = $this->scalar('SELECT COUNT(*) FROM `user` WHERE `deleted_at` IS NULL');

        $this->assertGreaterThan(0, $expected);
        $this->assertCount($expected, User::findAll(['deleted_at' => null]));
    }

    #[Test]
    public function findAllTurnsAScalarIntoEquality(): void
    {
        $this->assertCount(
            $this->scalar("SELECT COUNT(*) FROM `user` WHERE `role` = 'member'"),
            User::findAll(['role' => 'member'])
        );
    }

    #[Test]
    public function findAllAndsItsConditionsTogether(): void
    {
        $expected = $this->scalar("SELECT COUNT(*) FROM `user` WHERE `role` = 'member' AND `status_code` = 1");

        $this->assertCount($expected, User::findAll(['role' => 'member', 'status_code' => 1]));
        $this->assertLessThan(
            $this->scalar("SELECT COUNT(*) FROM `user` WHERE `role` = 'member'") + 1,
            $expected
        );
    }

    #[Test]
    public function findAllMixesTheThreeShapesInOneCall(): void
    {
        $expected = $this->scalar(
            "SELECT COUNT(*) FROM `user` WHERE `role` IN ('admin','member') AND `status_code` = 1 AND `deleted_at` IS NULL"
        );

        $this->assertCount($expected, User::findAll([
            'role' => ['admin', 'member'],
            'status_code' => 1,
            'deleted_at' => null,
        ]));
    }

    #[Test]
    public function findAllWithoutConditionsReadsTheWholeTable(): void
    {
        $this->assertCount($this->scalar('SELECT COUNT(*) FROM `user`'), User::findAll());
    }

    #[Test]
    public function findAllReturnsACollection(): void
    {
        $this->assertInstanceOf(Collection::class, User::findAll(['role' => 'member']));
    }

    // ------------------------------------------------------------------
    // replicate()
    // ------------------------------------------------------------------

    #[Test]
    public function replicateDropsEveryColumnOfACompositeKey(): void
    {
        $original = PostTag::find()->first();
        $this->assertInstanceOf(PostTag::class, $original);

        $copy = $original->replicate();

        // Keeping either half would make the copy collide with the row it was
        // copied from, or write a pairing nobody asked for.
        $this->assertArrayNotHasKey('post_id', $copy->getAttributes());
        $this->assertArrayNotHasKey('tag_id', $copy->getAttributes());
        $this->assertTrue($copy->isNew());
    }

    #[Test]
    public function replicateDropsASingleKeyAndKeepsTheRest(): void
    {
        $original = User::findByPk(1);
        $this->assertInstanceOf(User::class, $original);

        $copy = $original->replicate();

        $this->assertArrayNotHasKey('id', $copy->getAttributes());
        $this->assertArrayHasKey('username', $copy->getAttributes());
        $this->assertTrue($copy->isNew());
    }

    #[Test]
    public function replicateHonoursTheExceptList(): void
    {
        $original = User::findByPk(1);
        $this->assertInstanceOf(User::class, $original);

        $copy = $original->replicate(['email', 'username']);

        $this->assertArrayNotHasKey('email', $copy->getAttributes());
        $this->assertArrayNotHasKey('username', $copy->getAttributes());
        $this->assertArrayHasKey('score', $copy->getAttributes());
    }

    #[Test]
    public function aReplicaCanBeSavedAsANewRow(): void
    {
        $original = User::findByPk(1);
        $this->assertInstanceOf(User::class, $original);

        $copy = $original->replicate(['email', 'username']);
        $copy->username = '[surface]replica';
        $copy->email = 'surface-replica@test.com';

        $this->assertTrue($copy->save());
        $this->assertNotSame((int)$original->id, (int)$copy->id);
        $this->assertSame((int)$original->score, (int)$copy->score);
    }

    // ------------------------------------------------------------------
    // refresh()
    // ------------------------------------------------------------------

    #[Test]
    public function refreshReadsBackAChangeMadeOutOfBand(): void
    {
        $user = User::findByPk(3);
        $this->assertInstanceOf(User::class, $user);

        $original = (int)$user->score;
        Connection::get('mysql')->execute(new Raw('UPDATE `user` SET `score` = ? WHERE `id` = 3', [$original + 7]));

        $this->assertSame($original, (int)$user->score);
        $this->assertTrue($user->refresh());
        $this->assertSame($original + 7, (int)$user->score);

        Connection::get('mysql')->execute(new Raw('UPDATE `user` SET `score` = ? WHERE `id` = 3', [$original]));
    }

    #[Test]
    public function refreshClearsPendingChanges(): void
    {
        $user = User::findByPk(3);
        $this->assertInstanceOf(User::class, $user);

        $user->score = 12345;
        $this->assertNotEmpty($user->getDirtyAttributes());

        // The refreshed values came from the database, so there is nothing
        // left to write; leaving them dirty would send them straight back.
        $this->assertTrue($user->refresh());
        $this->assertSame([], $user->getDirtyAttributes());
    }

    #[Test]
    public function refreshRefusesOnAModelThatWasNeverSaved(): void
    {
        $this->assertFalse((new User(['username' => '[surface]unsaved']))->refresh());
    }

    #[Test]
    public function refreshReportsFalseWhenTheRowIsGone(): void
    {
        $user = new User();
        $user->fill([
            'username' => '[surface]vanishing',
            'email' => 'surface-vanishing@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 1,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        Connection::get('mysql')->execute(new Raw('DELETE FROM `user` WHERE `id` = ?', [$user->id]));

        $this->assertFalse($user->refresh());
    }

    // ------------------------------------------------------------------
    // transaction()
    // ------------------------------------------------------------------

    #[Test]
    public function transactionRollsBackWhenTheCallbackReturnsFalse(): void
    {
        $before = $this->scalar('SELECT COUNT(*) FROM `user`');

        $result = User::transaction(function (): bool {
            Connection::get('mysql')->execute(new Raw(
                "INSERT INTO `user` (`username`,`email`,`password`,`role`,`score`,`department_id`,`status_code`)"
                . " VALUES ('[surface]tx','surface-tx@test.com','x','member',1,1,1)"
            ));

            return false;
        });

        $this->assertFalse($result);
        $this->assertSame($before, $this->scalar('SELECT COUNT(*) FROM `user`'));
    }

    #[Test]
    public function transactionCommitsWhenTheCallbackReturnsTrue(): void
    {
        $before = $this->scalar('SELECT COUNT(*) FROM `user`');

        $this->assertTrue(User::transaction(function (): bool {
            Connection::get('mysql')->execute(new Raw(
                "INSERT INTO `user` (`username`,`email`,`password`,`role`,`score`,`department_id`,`status_code`)"
                . " VALUES ('[surface]tx2','surface-tx2@test.com','x','member',1,1,1)"
            ));

            return true;
        }));

        $this->assertSame($before + 1, $this->scalar('SELECT COUNT(*) FROM `user`'));
    }

    #[Test]
    public function transactionWrapsAThrownExceptionAndRollsBack(): void
    {
        $before = $this->scalar('SELECT COUNT(*) FROM `user`');
        $caught = null;

        try {
            User::transaction(function (): bool {
                Connection::get('mysql')->execute(new Raw(
                    "INSERT INTO `user` (`username`,`email`,`password`,`role`,`score`,`department_id`,`status_code`)"
                    . " VALUES ('[surface]tx3','surface-tx3@test.com','x','member',1,1,1)"
                ));

                throw new RuntimeException('rolled back on purpose');
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertSame('rolled back on purpose', $caught->getMessage());

        // The original is kept as the previous exception; losing it would
        // leave only a message where the stack trace was.
        $this->assertInstanceOf(RuntimeException::class, $caught->getPrevious());
        $this->assertSame($before, $this->scalar('SELECT COUNT(*) FROM `user`'));
    }

    // ------------------------------------------------------------------
    // toArray() / toJson()
    // ------------------------------------------------------------------

    #[Test]
    public function toArrayAppliesDeclaredCasts(): void
    {
        $setting = Setting::find()->notNull('metadata')->first();
        $this->assertInstanceOf(Setting::class, $setting);

        $array = $setting->toArray();

        // The column holds a JSON string; the model declares a json cast, so
        // the serialized form must be the decoded document.
        $this->assertIsString($setting->getAttributes()['metadata']);
        $this->assertIsArray($array['metadata']);
    }

    #[Test]
    public function toArrayAppliesCastsToAFieldSubsetToo(): void
    {
        $setting = Setting::find()->notNull('metadata')->first();
        $this->assertInstanceOf(Setting::class, $setting);

        $this->assertIsArray($setting->toArray(['metadata'])['metadata']);
    }

    #[Test]
    public function toArraySerializesALazyLoadedToManyRelation(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $posts = $user->posts;
        $this->assertInstanceOf(Collection::class, $posts);

        $array = $user->toArray();
        $this->assertIsArray($array['posts']);
        $this->assertCount(count($posts->all()), $array['posts']);
        $this->assertIsArray($array['posts'][0]);
    }

    #[Test]
    public function toArraySerializesAnEagerLoadedToManyRelationTheSameWay(): void
    {
        $lazy = User::findByPk(1);
        $eager = User::find()->with('posts')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $lazy);
        $this->assertInstanceOf(User::class, $eager);

        // Eager arrives as an array and lazy as a Collection; the caller must
        // not be able to tell which loader ran.
        $binding = $lazy->posts;
        $this->assertNotNull($binding);
        $this->assertSame($eager->toArray()['posts'], $lazy->toArray()['posts']);
    }

    #[Test]
    public function toArraySerializesAToOneRelation(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $profile = $user->profile;
        $this->assertNotNull($profile);

        $this->assertIsArray($user->toArray()['profile']);
    }

    #[Test]
    public function aFieldListSelectsWhichRelationsAreSerialized(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $binding = $user->posts;
        $this->assertNotNull($binding);

        $this->assertArrayNotHasKey('posts', $user->toArray(['id']));
        $this->assertArrayHasKey('posts', $user->toArray(['id', 'posts']));
    }

    #[Test]
    public function toJsonCarriesTheRelationsToArrayBuilt(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $binding = $user->posts;
        $this->assertNotNull($binding);

        $decoded = json_decode($user->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['posts']);
    }

    // ------------------------------------------------------------------
    // filterMassAssignable() aliases
    // ------------------------------------------------------------------

    #[Test]
    public function fillRemapsAliasedAttributeNames(): void
    {
        $user = new AliasedUser();
        $user->fill(['user_name' => '[surface]alias', 'mail' => 'surface-alias@test.com', 'score' => 5]);

        $attributes = $user->getAttributes();

        $this->assertSame('[surface]alias', $attributes['username']);
        $this->assertSame('surface-alias@test.com', $attributes['email']);
        $this->assertArrayNotHasKey('user_name', $attributes);
        $this->assertArrayNotHasKey('mail', $attributes);
    }

    #[Test]
    public function anAliasedFillIsWrittenToTheRealColumns(): void
    {
        $user = new AliasedUser();
        $user->fill([
            'user_name' => '[surface]alias2',
            'mail' => 'surface-alias2@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 5,
            'department_id' => 1,
            'status_code' => 1,
        ]);

        $this->assertTrue($user->save());
        $this->assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM `user` WHERE `username` = '[surface]alias2'"
        ));
    }

    #[Test]
    public function anUnaliasedNamePassesThroughUntouched(): void
    {
        $user = new AliasedUser();
        $user->fill(['username' => '[surface]direct', 'score' => 3]);

        $this->assertSame('[surface]direct', $user->getAttributes()['username']);
    }

    // ------------------------------------------------------------------
    // deleteAll() / deleteAllUnchecked()
    // ------------------------------------------------------------------

    #[Test]
    public function deleteAllRemovesOnlyTheMatchingRows(): void
    {
        $this->withScratchTable(function (Model $model): void {
            $model->deleteAll(new Raw('`n` > ?', [2]));

            $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM `surface_scratch`'));
        });
    }

    #[Test]
    public function deleteAllUncheckedEmptiesTheTable(): void
    {
        $this->withScratchTable(function (Model $model): void {
            // The one call that is allowed to do this — it says so in the name.
            $this->assertTrue($model->deleteAllUnchecked());
            $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM `surface_scratch`'));
        });
    }

    #[Test]
    public function deleteAllUncheckedOnAnEmptyTableIsHarmless(): void
    {
        $this->withScratchTable(function (Model $model): void {
            $model->deleteAllUnchecked();

            $this->assertTrue($model->deleteAllUnchecked());
            $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM `surface_scratch`'));
        });
    }

    /**
     * Run a callback against a four-row scratch table, then drop it.
     *
     * These two methods delete whole tables, so they are never pointed at a
     * fixture table — a passing assertion there would still have destroyed
     * the data every other test in the run depends on.
     *
     * @param callable(Model): void $callback Receives a model bound to the scratch table.
     * @return void
     */
    private function withScratchTable(callable $callback): void
    {
        $driver = Connection::get('mysql');
        $driver->execute(new Raw('DROP TABLE IF EXISTS `surface_scratch`'));
        $driver->execute(new Raw('CREATE TABLE `surface_scratch` (`id` INT PRIMARY KEY AUTO_INCREMENT, `n` INT)'));
        $driver->execute(new Raw('INSERT INTO `surface_scratch` (`n`) VALUES (1), (2), (3), (4)'));

        try {
            $callback(new ScratchModel());
        } finally {
            $driver->execute(new Raw('DROP TABLE IF EXISTS `surface_scratch`'));
        }
    }

    #[Test]
    public function theFixtureIsUntouched(): void
    {
        // Several tests here delete rows; a stray full-table delete would
        // otherwise be found by whichever test class happened to run next.
        $this->assertSame(10, $this->scalar('SELECT COUNT(*) FROM `user` WHERE `username` NOT LIKE \'[surface]%\''));
        $this->assertSame(15, $this->scalar('SELECT COUNT(*) FROM `post`'));
        $this->assertSame(20, $this->scalar('SELECT COUNT(*) FROM `comment`'));
        $this->assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'surface_scratch'"
        ));
    }
}

/**
 * A model over the scratch table the delete tests create.
 */
class ScratchModel extends Model
{
    protected string $table = 'surface_scratch';

    protected string $connection = 'mysql';

    protected array $fillable = ['n'];
}
