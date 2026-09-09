<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\EagerLoader;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * @property int|null $id
 * @property string|null $name
 * @property array<ContractPost>|null $posts
 * @property mixed $wipe
 */
class ContractUser extends Model
{
    /**
     * Names this model was asked to invoke that are not relations.
     *
     * @var array<int, string>
     */
    public static array $invoked = [];

    protected string $table = 'users';
    protected string $connection = 'eager_contract';
    protected array $fillable = ['name'];

    public function posts(): Relation
    {
        return $this->hasMany(ContractPost::class, ['user_id' => 'id']);
    }

    /**
     * A perfectly ordinary method that happens to share the shape of a typo.
     *
     * It stands in for delete(): the point is not what it does but that it is
     * reached at all. Anything with() invokes, it invokes during a read.
     */
    public function wipe(): void
    {
        self::$invoked[] = 'wipe';
    }

    /**
     * Declares Relation, but a caller cannot supply the argument.
     *
     * @param string $mode Unused; present to make the method uncallable bare.
     * @return Relation
     */
    public function needsArgument(string $mode): Relation
    {
        self::$invoked[] = 'needsArgument';

        return $this->hasMany(ContractPost::class, ['user_id' => 'id']);
    }

    /**
     * Declares Relation but is not an instance method.
     *
     * @return Relation
     */
    public static function staticRelation(): Relation
    {
        self::$invoked[] = 'staticRelation';

        return (new self())->hasMany(ContractPost::class, ['user_id' => 'id']);
    }

    /**
     * Declares nothing, so nothing can be assumed about calling it.
     *
     * @return mixed
     */
    public function untyped(): mixed
    {
        self::$invoked[] = 'untyped';

        return $this->hasMany(ContractPost::class, ['user_id' => 'id']);
    }

    /** Not reachable as a property read, and so not as a relation either. */
    protected function hiddenRelation(): Relation
    {
        self::$invoked[] = 'hiddenRelation';

        return $this->hasMany(ContractPost::class, ['user_id' => 'id']);
    }

    /**
     * Declares a type that permits null, so PHP guarantees nothing.
     *
     * The declaration is the whole basis for calling the method, and a nullable
     * one does not promise a Relation comes back.
     *
     * @return Relation|null
     */
    public function nullableRelation(): ?Relation
    {
        self::$invoked[] = 'nullableRelation';

        return null;
    }

    /**
     * The same declaration written the long way.
     *
     * `?Relation` and `Relation|null` are one type to PHP, and both reflect as
     * a named type — the union spelling is not a union.
     *
     * @return Relation|null
     */
    public function nullableUnionRelation(): Relation|null
    {
        self::$invoked[] = 'nullableUnionRelation';

        return $this->hasMany(ContractPost::class, ['user_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $title
 * @property array<ContractTag>|null $tags
 */
class ContractPost extends Model
{
    protected string $table = 'posts';
    protected string $connection = 'eager_contract';
    protected array $fillable = ['user_id', 'title'];

    public function tags(): Relation
    {
        return $this->hasMany(ContractTag::class, ['tag_id' => 'id'])
            ->viaTable('post_tag', ['post_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property string|null $label
 * @property array<ContractPost>|null $posts
 */
class ContractTag extends Model
{
    protected string $table = 'tags';
    protected string $connection = 'eager_contract';
    protected array $fillable = ['label'];

    public function posts(): Relation
    {
        return $this->hasMany(ContractPost::class, ['post_id' => 'id'])
            ->viaTable('post_tag', ['tag_id' => 'id']);
    }
}

/**
 * The contract eager loading has to keep with property reads.
 *
 * `with()` and `$model->name` resolve the same names against the same models,
 * and for a while they disagreed. ResolvesRelations was written because reading
 * `$user->delete` as a property ran the delete; EagerLoader kept its own older
 * answer — method_exists(), then call it — so `with('delete')` still did. Two
 * places answering one question is the shape the fix has to rule out, not just
 * the one wrong answer.
 *
 * Run against SQLite so the whole contract is checked without a server.
 */
class EagerLoaderContractTest extends TestCase
{
    protected function setUp(): void
    {
        ContractUser::$invoked = [];

        Connection::reset();
        Connection::add('eager_contract', ['driver' => 'sqlite', 'database' => ':memory:']);

        $driver = Connection::get('eager_contract');
        $driver->execute(new Raw('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
        $driver->execute(new Raw('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)'));
        $driver->execute(new Raw('CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT)'));
        $driver->execute(new Raw('CREATE TABLE post_tag (post_id INTEGER, tag_id INTEGER)'));

        foreach ([[1, 'Alice'], [2, 'Bob'], [3, 'Carol']] as [$id, $name]) {
            $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [$id, $name]));
        }

        foreach ([[1, 1, 'A'], [2, 1, 'B'], [3, 2, 'C']] as [$id, $userId, $title]) {
            $driver->execute(new Raw('INSERT INTO posts (id, user_id, title) VALUES (?, ?, ?)', [$id, $userId, $title]));
        }

        foreach ([[1, 'php'], [2, 'sql'], [3, 'orphan']] as [$id, $label]) {
            $driver->execute(new Raw('INSERT INTO tags (id, label) VALUES (?, ?)', [$id, $label]));
        }

        // Post 1 has both tags, post 2 has one, post 3 has none; tag 3 has no
        // posts. Every branch of the grouping is represented.
        foreach ([[1, 1], [1, 2], [2, 2]] as [$postId, $tagId]) {
            $driver->execute(new Raw('INSERT INTO post_tag (post_id, tag_id) VALUES (?, ?)', [$postId, $tagId]));
        }
    }

    protected function tearDown(): void
    {
        ContractUser::$invoked = [];
        Connection::reset();
    }

    /**
     * Names on ContractUser, and whether a read may resolve them.
     *
     * @return array<string, array{0: non-empty-string, 1: bool}>
     */
    public static function candidateNames(): array
    {
        return [
            'a relation' => ['posts', true],
            'returns void' => ['wipe', false],
            'requires an argument' => ['needsArgument', false],
            'is static' => ['staticRelation', false],
            'declares a type that is not Relation' => ['untyped', false],
            'is not public' => ['hiddenRelation', false],
            'does not exist' => ['nosuchthing', false],
            'inherited and not a relation' => ['delete', false],
            'declares a nullable Relation' => ['nullableRelation', false],
            'declares Relation|null' => ['nullableUnionRelation', false],
        ];
    }

    /**
     * @param non-empty-string $name The method name under test.
     * @param bool $eligible Whether a read may resolve it.
     * @return void
     */
    #[Test]
    #[DataProvider('candidateNames')]
    public function eagerLoadingResolvesExactlyWhatAPropertyReadResolves(string $name, bool $eligible): void
    {
        $model = new ContractUser();

        // The one answer, stated once.
        $this->assertSame($eligible, $model->isRelationMethod($name));

        // And the answer with() actually acts on.
        $items = iterator_to_array(ContractUser::find()->with($name)->get());
        $this->assertNotSame([], $items);
        $this->assertSame($eligible, reset($items)->relationLoaded($name));
    }

    /**
     * @param non-empty-string $name The method name under test.
     * @param bool $eligible Whether a read may resolve it.
     * @return void
     */
    #[Test]
    #[DataProvider('candidateNames')]
    public function eagerLoadingNeverInvokesAMethodItWillNotUse(string $name, bool $eligible): void
    {
        iterator_to_array(ContractUser::find()->with($name)->get());

        // Every ineligible name here records its own call, so a single empty
        // array is the whole claim: with() called nothing it should not have.
        $this->assertSame([], ContractUser::$invoked);
        $this->assertSame($eligible, (new ContractUser())->isRelationMethod($name));
    }

    #[Test]
    public function anIneligibleNameIsNotReadableAsAPropertyEither(): void
    {
        $user = ContractUser::find()->where('id', 1)->first();
        $this->assertInstanceOf(ContractUser::class, $user);

        $this->assertNull($user->wipe);
        $this->assertSame([], ContractUser::$invoked);
    }

    #[Test]
    public function aJunctionRelationLoadsTheRelatedRowsRatherThanNone(): void
    {
        $expected = [1 => [1, 2], 2 => [2], 3 => []];

        $seen = 0;
        foreach (ContractPost::find()->with('tags')->orderBy('id')->get() as $post) {
            $this->assertTrue($post->relationLoaded('tags'));

            $ids = [];
            foreach ((array)$post->tags as $tag) {
                $ids[] = (int)$tag->id;
            }
            sort($ids);

            $this->assertSame($expected[(int)$post->id], $ids, "post {$post->id} has the wrong tags");
            ++$seen;
        }

        $this->assertSame(3, $seen);
    }

    #[Test]
    public function aJunctionRelationAgreesWithTheLazyPath(): void
    {
        $eager = [];
        foreach (ContractPost::find()->with('tags')->orderBy('id')->get() as $post) {
            $eager[(int)$post->id] = array_map(static fn($tag): int => (int)$tag->id, (array)$post->tags);
        }

        $lazy = [];
        foreach (ContractPost::find()->orderBy('id')->get() as $post) {
            $lazy[(int)$post->id] = array_map(
                static fn($tag): int => (int)$tag->id,
                iterator_to_array($post->tags()->fetch())
            );
        }

        $this->assertSame($lazy, $eager);
    }

    #[Test]
    public function theReverseJunctionDirectionAlsoLoads(): void
    {
        $expected = [1 => [1], 2 => [1, 2], 3 => []];

        foreach (ContractTag::find()->with('posts')->orderBy('id')->get() as $tag) {
            $ids = [];
            foreach ((array)$tag->posts as $post) {
                $ids[] = (int)$post->id;
            }
            sort($ids);

            $this->assertSame($expected[(int)$tag->id], $ids, "tag {$tag->id} has the wrong posts");
        }
    }

    #[Test]
    public function theJunctionKeyDoesNotSurviveOnToArray(): void
    {
        $post = ContractPost::find()->where('id', 1)->with('tags')->first();
        $this->assertInstanceOf(ContractPost::class, $post);
        $this->assertNotSame([], $post->tags);

        foreach ((array)$post->tags as $tag) {
            $this->assertSame(['id', 'label'], array_keys($tag->toArray()));
            $this->assertFalse($tag->isDirty());
        }
    }

    #[Test]
    public function aJunctionBatchKeepsTheCallersProjection(): void
    {
        $post = ContractPost::find()->where('id', 1)
            ->with(['tags' => fn(ActiveQuery $query) => $query->select('!tags.label')])
            ->first();

        $this->assertInstanceOf(ContractPost::class, $post);

        $labels = [];
        foreach ((array)$post->tags as $tag) {
            $this->assertSame(['label'], array_keys($tag->toArray()));
            $labels[] = $tag->label;
        }

        sort($labels);
        $this->assertSame(['php', 'sql'], $labels);
    }

    #[Test]
    public function aDirectRelationSurvivesAProjectionThatOmitsTheForeignKey(): void
    {
        $expected = [1 => 2, 2 => 1, 3 => 0];

        foreach (
            ContractUser::find()
                ->with(['posts' => fn(ActiveQuery $query) => $query->select('title')])
                ->orderBy('id')
                ->get() as $user
        ) {
            $this->assertCount($expected[(int)$user->id], $user->posts, "user {$user->id} lost posts");
        }
    }

    #[Test]
    public function aProjectionIsNotWidenedBeyondTheGroupingKey(): void
    {
        $user = ContractUser::find()->where('id', 1)
            ->with(['posts' => fn(ActiveQuery $query) => $query->select('title')])
            ->first();

        $this->assertInstanceOf(ContractUser::class, $user);

        foreach ((array)$user->posts as $post) {
            $this->assertSame(['title', 'user_id'], array_keys($post->toArray()));
        }
    }

    #[Test]
    public function anUnconstrainedRelationIsStillSelectedWhole(): void
    {
        $user = ContractUser::find()->where('id', 1)->with('posts')->first();
        $this->assertInstanceOf(ContractUser::class, $user);

        foreach ((array)$user->posts as $post) {
            $this->assertSame(['id', 'user_id', 'title'], array_keys($post->toArray()));
        }
    }

    #[Test]
    public function eagerLoadingSurvivesAModelSetWithoutAZeroKey(): void
    {
        $models = [];
        foreach (ContractUser::find()->orderBy('id')->get() as $user) {
            $models['u' . $user->id] = $user;
        }

        EagerLoader::loadRelations($models, ['posts']);

        $this->assertCount(3, $models);
        foreach ($models as $user) {
            $this->assertTrue($user->relationLoaded('posts'));
        }
    }

    #[Test]
    public function indexByAndWithComposeRatherThanCollide(): void
    {
        $expected = ['Alice' => 2, 'Bob' => 1, 'Carol' => 0];

        $keyed = ContractUser::find()->with('posts')->indexBy('name')->orderBy('id')->get();

        $seen = [];
        foreach ($keyed as $name => $user) {
            $this->assertTrue($user->relationLoaded('posts'));
            $seen[$name] = count((array)$user->posts);
        }

        $this->assertSame($expected, $seen);
    }

    #[Test]
    public function aLimitInsideAConstraintCapsTheBatchNotEachParent(): void
    {
        // Documented in 04-RELATION.md because it cannot be fixed without
        // giving up the single batch query that with() exists to make. A
        // parent past the cap gets an empty list, which reads exactly like
        // having no related rows — so the docs say so, and this pins it.
        $counts = [];
        foreach (
            ContractUser::find()
                ->with(['posts' => fn(ActiveQuery $query) => $query->limit(2)])
                ->orderBy('id')
                ->get() as $user
        ) {
            $counts[(int)$user->id] = count((array)$user->posts);
        }

        $this->assertSame(2, array_sum($counts));
        $this->assertSame([1 => 2, 2 => 0, 3 => 0], $counts);
    }

    #[Test]
    public function anOrderInsideAConstraintOrdersWithinEachGroup(): void
    {
        $user = ContractUser::find()->where('id', 1)
            ->with(['posts' => fn(ActiveQuery $query) => $query->orderBy('id', 'DESC')])
            ->first();

        $this->assertInstanceOf(ContractUser::class, $user);
        $this->assertSame([2, 1], array_map(static fn($post): int => (int)$post->id, (array)$user->posts));
    }

    #[Test]
    public function loadingNothingIsNotAnError(): void
    {
        $this->assertSame([], EagerLoader::loadRelations([], ['posts']));

        $models = iterator_to_array(ContractUser::find()->get());
        $this->assertSame($models, EagerLoader::loadRelations($models, []));
    }

    /**
     * The names whose declaration permits null.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function nullableNames(): array
    {
        return [
            '?Relation' => ['nullableRelation'],
            'Relation|null' => ['nullableUnionRelation'],
        ];
    }

    /**
     * @param non-empty-string $name The nullable-returning method under test.
     * @return void
     */
    #[Test]
    #[DataProvider('nullableNames')]
    public function aNullableDeclarationIsNotEnoughToCallOn(string $name): void
    {
        // Testing the return type is worth doing because PHP then enforces it.
        // A nullable declaration is not enforced in the way the callers rely on
        // — it permits exactly the value none of them handle — so the check has
        // to read the nullability, not just the name.
        $model = new ContractUser();
        $this->assertFalse($model->isRelationMethod($name));

        // Ineligible means never invoked, the same as every other ineligible
        // shape: deciding must not run the thing being decided about.
        iterator_to_array(ContractUser::find()->with($name)->get());
        $this->assertSame([], ContractUser::$invoked);
    }

    /**
     * @param non-empty-string $name The nullable-returning method under test.
     * @return void
     */
    #[Test]
    #[DataProvider('nullableNames')]
    public function aNullableRelationReadsAsAnAbsentAttribute(string $name): void
    {
        // Not an error, and specifically not a fatal one. Reading an ineligible
        // name is how a typo behaves, and it answers null rather than dying on
        // "Call to a member function fetch() on null" — which is what happened
        // while a nullable declaration counted as eligible.
        $user = ContractUser::find()->where('id', 1)->first();
        $this->assertInstanceOf(ContractUser::class, $user);

        $this->assertNull($user->{$name});
        $this->assertFalse(isset($user->{$name}));
        $this->assertFalse($user->relationLoaded($name));
        $this->assertSame([], ContractUser::$invoked);
    }

    /**
     * @param non-empty-string $name The nullable-returning method under test.
     * @return void
     */
    #[Test]
    #[DataProvider('nullableNames')]
    public function aNullableRelationIsRefusedByHasRatherThanRaisingATypeError(string $name): void
    {
        // has() rejects an ineligible name with a message naming the class and
        // the method. Before the declaration was read properly this one got
        // past the filter and failed inside resolveRelation() with a TypeError
        // about a return value, which names neither.
        try {
            iterator_to_array(ContractUser::find()->has($name)->get());
            self::fail('A nullable declaration must not be accepted by has().');
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
        }

        $this->assertStringContainsString(ContractUser::class . "::$name()", $message);

        // And the message has to rule out the declaration actually written.
        // "declares Relation as its return type" is what an author of
        // `?Relation` believes they did, so the old wording read as a denial of
        // the code in front of them.
        $this->assertStringContainsString('nullable Relation', $message);
    }

    /**
     * @param non-empty-string $name The nullable-returning method under test.
     * @return void
     */
    #[Test]
    #[DataProvider('nullableNames')]
    public function everyCallerAgreesAboutANullableDeclaration(string $name): void
    {
        // The trait exists so that one question has one answer. These are the
        // four places that ask it; each used to answer differently for this
        // shape. What they agree on now is that the name is not a relation.
        $model = new ContractUser();
        $eligible = $model->isRelationMethod($name);

        $this->assertFalse($eligible);

        $user = ContractUser::find()->where('id', 1)->with($name)->first();
        $this->assertInstanceOf(ContractUser::class, $user);
        $this->assertSame($eligible, $user->relationLoaded($name));
        $this->assertSame($eligible, $user->{$name} !== null);

        try {
            iterator_to_array(ContractUser::find()->has($name)->get());
            self::fail('An ineligible name must not be accepted by has().');
        } catch (\InvalidArgumentException) {
            // The documented refusal, which is the agreement being asserted.
        }

        $this->assertSame([], ContractUser::$invoked);
    }
}
