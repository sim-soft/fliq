<?php

namespace Integration;

use Models\User;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * @property int|null $id
 * @property string|null $username
 * @property array<PdoPost>|null $posts
 */
class PdoUser extends Model
{
    protected string $table = 'user';
    protected string $connection = 'cursor_pdo';

    public function posts(): Relation
    {
        return $this->hasMany(PdoPost::class, ['user_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property int|null $user_id
 */
class PdoPost extends Model
{
    protected string $table = 'post';
    protected string $connection = 'cursor_pdo';
}

/**
 * @property int|null $id
 * @property string|null $payload
 */
class CursorBigRow extends Model
{
    protected string $table = 'cursor_probe_big';
    protected string $connection = 'cursor_pdo';
}

/**
 * cursor() against a real MySQL connection, where the buffering lives.
 *
 * The suite's other cursor tests run on the mysqli connection, which has no
 * getPdo() — so cursor() falls back to all() and none of the streaming code
 * had ever executed. Under pdo_mysql it had, and it did not stream:
 * PDO::MYSQL_ATTR_USE_BUFFERED_QUERY is an attribute of the connection, and it
 * was being passed to prepare() as a statement option, where PDO accepts it and
 * does nothing with it. Measured over 20k rows, cursor() cost more memory than
 * getArray(), which at least says it loads everything.
 *
 * Memory is the only way to tell the two apart from the outside, so these tests
 * measure it, and check the thing that follows from real streaming: MySQL
 * refuses a second query on a connection that is mid-result.
 */
class CursorStreamingTest extends DatabaseTestCase
{
    /** @var int Rows in the scratch table — enough that buffering is visible. */
    private const int BIG_ROWS = 20000;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!static::$dbAvailable) {
            return;
        }

        Connection::add('cursor_pdo', [
            'driver' => 'pdo_mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'sample_db',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ]);

        self::seedBigTable();
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$dbAvailable) {
            try {
                Connection::get('cursor_pdo', 'write')
                    ->execute(new Raw('DROP TABLE IF EXISTS cursor_probe_big'));
            } catch (\Throwable) {
                // The fixture is reloaded per class; a failed drop must not
                // mask the test results.
            }
        }

        parent::tearDownAfterClass();
    }

    /**
     * Create and fill the scratch table used by the memory assertions.
     *
     * @return void
     */
    private static function seedBigTable(): void
    {
        $driver = Connection::get('cursor_pdo', 'write');

        // getPdo() is not on Driver — only the PDO-backed drivers have it, which
        // is exactly the branch cursor() tests for.
        if (!method_exists($driver, 'getPdo')) {
            return;
        }

        $pdo = $driver->getPdo();

        if ($pdo === null) {
            return;
        }

        $pdo->exec('DROP TABLE IF EXISTS cursor_probe_big');
        $pdo->exec('CREATE TABLE cursor_probe_big (id INT PRIMARY KEY AUTO_INCREMENT, payload VARCHAR(255))');

        $insert = $pdo->prepare('INSERT INTO cursor_probe_big (payload) VALUES (?)');
        $payload = str_repeat('x', 200);

        $pdo->beginTransaction();
        for ($i = 0; $i < self::BIG_ROWS; $i++) {
            $insert->execute([$payload]);
        }
        $pdo->commit();
    }

    /**
     * The PDO handle behind the cursor connection.
     *
     * @return PDO
     */
    private function pdo(): PDO
    {
        $driver = Connection::get('cursor_pdo', 'read');
        $this->assertTrue(method_exists($driver, 'getPdo'), 'the cursor connection must be PDO-backed');

        $pdo = $driver->getPdo();
        $this->assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }

    #[Test]
    public function aCursorDoesNotGrowWithTheNumberOfRows(): void
    {
        // The claim cursor() is named for. Before the fix this was ~5 MB, and
        // getArray() over the same rows was ~4.5 MB.
        $before = memory_get_usage();
        $cursor = CursorBigRow::find()->cursor();
        $cursor->current();
        $held = memory_get_usage() - $before;

        unset($cursor);
        gc_collect_cycles();

        $before = memory_get_usage();
        $rows = iterator_to_array(CursorBigRow::find()->getArray());
        $buffered = memory_get_usage() - $before;

        $this->assertCount(self::BIG_ROWS, $rows);
        unset($rows);

        $this->assertLessThan(
            $buffered / 2,
            $held,
            sprintf(
                'cursor() held %.2f MB after one row, getArray() %.2f MB for all of them',
                $held / 1048576,
                $buffered / 1048576
            )
        );
    }

    #[Test]
    public function streamingTheWholeTableDoesNotRaisePeakMemory(): void
    {
        // Draining every row must cost no more than holding one.
        $before = memory_get_peak_usage(true);

        $seen = 0;
        foreach (CursorBigRow::find()->cursor() as $row) {
            ++$seen;
        }

        $this->assertSame(self::BIG_ROWS, $seen);
        $this->assertSame($before, memory_get_peak_usage(true));
    }

    #[Test]
    public function mysqlRefusesASecondQueryWhileACursorIsOpen(): void
    {
        // Not a defect — the proof that the rows really are still on the wire.
        // A buffered "cursor" lets this through, which is how the old one
        // passed every test it had.
        $cursor = CursorBigRow::find()->cursor();
        $cursor->current();

        try {
            $this->pdo()->query('SELECT 1');
            $this->fail('the cursor is buffering: a second query should not have been possible');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('unbuffered queries are active', $e->getMessage());
        } finally {
            unset($cursor);
            gc_collect_cycles();
        }
    }

    #[Test]
    public function theConnectionIsUsableAgainAfterTheCursorIsDrained(): void
    {
        $this->assertSame(self::BIG_ROWS, count(iterator_to_array(CursorBigRow::find()->select('id')->cursor())));

        $rows = (new Raw('SELECT COUNT(*) c FROM cursor_probe_big'))->fetchAll();
        $this->assertSame(self::BIG_ROWS, (int)$rows[0]['c']);
    }

    #[Test]
    public function theConnectionIsUsableAgainAfterAnEarlyBreak(): void
    {
        // The statement is closed in a finally, because a caller who breaks out
        // never reaches the end of the generator body. Without it the result
        // set stays open and every later query on this connection fails.
        foreach (CursorBigRow::find()->cursor() as $row) {
            break;
        }

        $this->assertSame(10, (int)PdoUser::find()->count());
    }

    #[Test]
    public function theConnectionIsUsableAgainAfterAnExceptionInTheLoop(): void
    {
        try {
            foreach (CursorBigRow::find()->cursor() as $row) {
                throw new \RuntimeException('the caller blew up');
            }
        } catch (\RuntimeException $e) {
            $this->assertSame('the caller blew up', $e->getMessage());
        }

        $this->assertSame(10, (int)PdoUser::find()->count());
    }

    #[Test]
    public function theConnectionIsUsableAgainAfterAnAbandonedCursor(): void
    {
        $cursor = CursorBigRow::find()->cursor();
        $cursor->current();
        unset($cursor);
        gc_collect_cycles();

        $this->assertSame(10, (int)PdoUser::find()->count());
    }

    #[Test]
    public function theBufferedAttributeIsRestoredAfterwards(): void
    {
        // cursor() turns buffering off on a connection the caller shares with
        // every other query in the request. Leaving it off would change all of
        // them.
        $pdo = $this->pdo();
        $before = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);

        iterator_to_array(PdoUser::find()->limit(3)->cursor());
        $this->assertSame($before, $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));

        foreach (PdoUser::find()->cursor() as $user) {
            break;
        }
        $this->assertSame($before, $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));

        try {
            foreach (PdoUser::find()->cursor() as $user) {
                throw new \RuntimeException('boom');
            }
        } catch (\RuntimeException) {
            // The attribute is restored in the same finally that closes the
            // statement, so an exception must not leave it off either.
        }
        $this->assertSame($before, $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    }

    #[Test]
    public function aCursorMatchesTheRowsTheDatabaseReturns(): void
    {
        $truth = array_map(
            static fn(array $row): int => (int)$row['id'],
            (new Raw('SELECT id FROM user ORDER BY id'))->fetchAll()
        );

        $streamed = [];
        foreach (PdoUser::find()->orderBy('id')->cursor() as $user) {
            $streamed[] = (int)$user->id;
        }

        $this->assertSame($truth, $streamed);
        $this->assertNotSame([], $truth);
    }

    #[Test]
    public function aCursorBindsItsConditions(): void
    {
        $truth = array_map(
            static fn(array $row): int => (int)$row['id'],
            (new Raw('SELECT id FROM user WHERE department_id = ? ORDER BY id', [1]))->fetchAll()
        );

        $streamed = [];
        foreach (PdoUser::find()->where('department_id', 1)->orderBy('id')->cursor() as $user) {
            $streamed[] = (int)$user->id;
        }

        $this->assertSame($truth, $streamed);
        $this->assertNotSame([], $truth);
    }

    #[Test]
    public function indexByKeysAPdoCursorAsWell(): void
    {
        // Dropped on PDO drivers and honoured on mysqli, which is the same
        // query answered two ways depending on the connection's driver.
        $keys = [];
        foreach (PdoUser::find()->indexBy('username')->orderBy('id')->limit(3)->cursor() as $key => $user) {
            $keys[] = $key;
        }

        $this->assertSame(['alice', 'bob', 'charlie'], $keys);
    }

    #[Test]
    public function bothDriversAgreeOnTheKeysACursorYields(): void
    {
        $viaPdo = [];
        foreach (PdoUser::find()->indexBy('username')->orderBy('id')->cursor() as $key => $user) {
            $viaPdo[] = $key;
        }

        $viaMysqli = [];
        foreach (User::find()->indexBy('username')->orderBy('id')->cursor() as $key => $user) {
            $viaMysqli[] = $key;
        }

        $this->assertSame($viaMysqli, $viaPdo);
        $this->assertCount(10, $viaPdo);
    }

    #[Test]
    public function bothDriversRefuseEagerLoadingOnACursor(): void
    {
        // Silently ignored under PDO, applied under mysqli. Whichever answer is
        // right, it cannot be two answers.
        foreach ([PdoUser::class, User::class] as $class) {
            try {
                iterator_to_array($class::find()->with('posts')->cursor());
                $this->fail("$class accepted with() on a cursor");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot eager load posts', $e->getMessage());
            }
        }
    }

    #[Test]
    public function aRelationReadInsideACursorFailsAsTheDocsSay(): void
    {
        // docs/05-ADVANCED-FEATURES.md shows this as the thing not to do, so it
        // has to actually be the thing not to do. Note fetch() returns a lazy
        // Collection — nothing is sent until it is iterated, which is why the
        // loop body reads the rows rather than just calling fetch().
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unbuffered queries are active/');

        foreach (PdoUser::find()->orderBy('id')->cursor() as $user) {
            foreach ($user->posts()->fetch() as $post) {
                $this->fail('a relation read inside a cursor should not have reached a row');
            }
        }
    }

    #[Test]
    public function relationsCanStillBeReadAfterACursorIsDrained(): void
    {
        // What the refusal message tells the caller to do. Reading them inside
        // the loop is what streaming costs, so this collects first.
        $ids = [];
        foreach (PdoUser::find()->orderBy('id')->limit(3)->cursor() as $user) {
            $ids[] = (int)$user->id;
        }

        $counts = [];
        foreach (PdoUser::find()->whereIn('id', $ids)->orderBy('id')->all() as $user) {
            $counts[(int)$user->id] = count($user->posts()->fetch());
        }

        $truth = [];
        foreach ((new Raw('SELECT user_id, COUNT(*) c FROM post WHERE user_id IN (1,2,3) GROUP BY user_id'))->fetchAll() as $row) {
            $truth[(int)$row['user_id']] = (int)$row['c'];
        }
        ksort($truth);

        $this->assertSame($truth, $counts);
    }
}
