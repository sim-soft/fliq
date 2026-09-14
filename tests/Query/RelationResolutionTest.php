<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * @property int|null $id
 * @property string|null $name
 * @property array<ResolvingPost>|null $posts
 */
class ResolvingUser extends Model
{
    /**
     * Names this model was made to invoke that are not relations.
     *
     * @var array<int, string>
     */
    public static array $invoked = [];

    protected string $table = 'users';
    protected string $connection = 'relation_resolution';
    protected array $fillable = ['name'];

    public function posts(): Relation
    {
        return $this->hasMany(ResolvingPost::class, ['user_id' => 'id']);
    }

    /** Exists, returns no relation, and records that it ran. */
    public function notARelation(): void
    {
        self::$invoked[] = 'notARelation';
    }

    /**
     * Declares Relation but cannot be called with no arguments.
     *
     * @param string $mode Unused.
     * @return Relation
     */
    public function needsArgument(string $mode): Relation
    {
        self::$invoked[] = 'needsArgument';

        return $this->hasMany(ResolvingPost::class, ['user_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property int|null $user_id
 */
class ResolvingPost extends Model
{
    protected string $table = 'posts';
    protected string $connection = 'relation_resolution';
    protected array $fillable = ['user_id'];
}

/**
 * @property int|null $a
 * @property int|null $b
 */
class ResolvingPair extends Model
{
    protected string $table = 'pairs';
    protected string $connection = 'relation_resolution';
    protected string|array $primaryKey = ['a', 'b'];
    protected array $fillable = ['a', 'b'];
}

/**
 * Relation names resolved by the query, and primary keys resolved by either end.
 *
 * has(), whereHas() and doesntHave() go through ActiveQuery::resolveRelation(),
 * which was the third place in the codebase answering "is this name a relation?"
 * and the second to answer it with method_exists() followed by calling the name.
 * A filter deciding whether a name is a relation must not run the method to find
 * out, and must say which name was wrong rather than letting an
 * ArgumentCountError out.
 *
 * findByPk() is the same shape one level down: the model-level method took a
 * scalar and the query-level one did not, so the documented way to combine a
 * key lookup with eager loading was a TypeError.
 */
class RelationResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        ResolvingUser::$invoked = [];

        Connection::reset();
        Connection::add('relation_resolution', ['driver' => 'sqlite', 'database' => ':memory:']);

        $driver = Connection::get('relation_resolution');
        $driver->execute(new Raw('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
        $driver->execute(new Raw('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER)'));
        $driver->execute(new Raw('CREATE TABLE pairs (a INTEGER, b INTEGER)'));

        foreach ([[1, 'Alice'], [2, 'Bob'], [3, 'Carol']] as [$id, $name]) {
            $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [$id, $name]));
        }

        // Alice and Bob have posts; Carol has none.
        foreach ([[1, 1], [2, 1], [3, 2]] as [$id, $userId]) {
            $driver->execute(new Raw('INSERT INTO posts (id, user_id) VALUES (?, ?)', [$id, $userId]));
        }

        $driver->execute(new Raw('INSERT INTO pairs (a, b) VALUES (?, ?)', [7, 9]));
    }

    protected function tearDown(): void
    {
        ResolvingUser::$invoked = [];
        Connection::reset();
    }

    /**
     * Names has() must refuse, and the reason each is not a relation.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function namesThatAreNotRelations(): array
    {
        return [
            'returns void' => ['notARelation'],
            'requires an argument' => ['needsArgument'],
            'inherited, not a relation' => ['delete'],
            'does not exist' => ['psots'],
        ];
    }

    /**
     * @param non-empty-string $name The rejected name.
     * @return void
     */
    #[Test]
    #[DataProvider('namesThatAreNotRelations')]
    public function hasRefusesANameThatIsNotARelationWithoutCallingIt(string $name): void
    {
        try {
            iterator_to_array(ResolvingUser::find()->has($name)->get());
            $this->fail("has('$name') should not have been accepted");
        } catch (InvalidArgumentException $e) {
            // The caller passed a name, so the message has to name it back;
            // an ArgumentCountError from inside the model does not. The two
            // reasons stay distinguishable: a name that is absent is a
            // different mistake from a name that is present but not a relation.
            $this->assertStringContainsString($name, $e->getMessage());
            $this->assertStringContainsString(
                $name === 'psots' ? 'has no method' : 'does not return a relation',
                $e->getMessage()
            );
        }

        $this->assertSame([], ResolvingUser::$invoked);
    }

    /**
     * @param non-empty-string $name The rejected name.
     * @return void
     */
    #[Test]
    #[DataProvider('namesThatAreNotRelations')]
    public function whereHasAndDoesntHaveRefuseTheSameNames(string $name): void
    {
        // All three filters share one resolver, and so must share one answer.
        foreach (['whereHas', 'doesntHave'] as $method) {
            try {
                $query = ResolvingUser::find();
                $args = $method === 'whereHas' ? [$name, fn($sub) => $sub] : [$name];
                iterator_to_array($query->{$method}(...$args)->get());
                $this->fail("$method('$name') should not have been accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }

        $this->assertSame([], ResolvingUser::$invoked);
    }

    #[Test]
    public function hasStillFiltersOnARealRelation(): void
    {
        $withPosts = iterator_to_array(ResolvingUser::find()->has('posts')->get());
        $withoutPosts = iterator_to_array(ResolvingUser::find()->doesntHave('posts')->get());

        $this->assertCount(2, $withPosts);
        $this->assertCount(1, $withoutPosts);
        $this->assertSame('Carol', reset($withoutPosts)->name);
    }

    #[Test]
    public function findByPkTakesAScalarOnTheQueryAsWellAsTheModel(): void
    {
        // The documented way to combine a key lookup with eager loading starts
        // from the query, and every route to eager loading has to.
        $viaQuery = ResolvingUser::find()->findByPk(1);
        $viaModel = ResolvingUser::findByPk(1);

        $this->assertInstanceOf(ResolvingUser::class, $viaQuery);
        $this->assertInstanceOf(ResolvingUser::class, $viaModel);
        $this->assertSame('Alice', $viaQuery->name);
        $this->assertSame($viaModel->name, $viaQuery->name);
    }

    #[Test]
    public function findByPkComposesWithEagerLoading(): void
    {
        $user = ResolvingUser::find()->with('posts')->findByPk(1);

        $this->assertInstanceOf(ResolvingUser::class, $user);
        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertCount(2, (array)$user->posts);
    }

    #[Test]
    public function findByPkStillTakesAnArrayForACompositeKey(): void
    {
        $pair = ResolvingPair::find()->findByPk(['a' => 7, 'b' => 9]);

        $this->assertInstanceOf(ResolvingPair::class, $pair);
        $this->assertSame(7, (int)$pair->a);
    }

    #[Test]
    public function aScalarAgainstACompositeKeySaysWhichColumnsItWanted(): void
    {
        // Returning null here was indistinguishable from "no such row", which is
        // the one thing a lookup must not be vague about.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/composite primary key \(a, b\)/');

        ResolvingPair::findByPk(1);
    }

    #[Test]
    public function findByPkAnswersNullForARowThatIsNotThere(): void
    {
        $this->assertNull(ResolvingUser::findByPk(999));
        $this->assertNull(ResolvingUser::find()->findByPk(999));
    }
}
