<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Collection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;
use stdClass;

/**
 * A model whose methods cover every shape __get() has to tell apart: a real
 * relation, a Relation-returning method that needs an argument, a static one,
 * a protected one, and ordinary methods that must never be invoked by a
 * property read.
 *
 * @property mixed $id
 * @property mixed $name
 * @property mixed $note
 * @property mixed $posts
 */
class AccessModel extends Model
{
    protected string $table = 'access_model';
    protected array $fillable = ['name', 'note'];

    /** @var bool Set if a non-relation method is invoked by a property read. */
    public bool $sideEffect = false;

    public function posts(): Relation
    {
        return $this->hasMany(AccessModel::class, ['owner_id' => 'id']);
    }

    /** A Relation-returning method that cannot be called without arguments. */
    public function taggedPosts(string $tag): Relation
    {
        return $this->hasMany(AccessModel::class, ['owner_id' => 'id']);
    }

    /** A Relation-returning method that is not callable on an instance. */
    public static function staticPosts(): Relation
    {
        return (new AccessModel())->hasMany(AccessModel::class, ['owner_id' => 'id']);
    }

    /** A Relation-returning method that is not part of the public surface. */
    protected function hiddenPosts(): Relation
    {
        return $this->hasMany(AccessModel::class, ['owner_id' => 'id']);
    }

    /** An ordinary method a property read must not invoke. */
    public function wipe(): bool
    {
        $this->sideEffect = true;
        return true;
    }

    /**
     * An ordinary method with no declared return type.
     *
     * A missing return type is not a Relation, so reading this name must not
     * call it either.
     *
     * @return bool
     */
    public function untyped()
    {
        $this->sideEffect = true;
        return true;
    }
}

/**
 * A model with a composite primary key.
 *
 * @property mixed $order_id
 * @property mixed $product_id
 * @property mixed $qty
 */
class CompositeKeyModel extends Model
{
    protected string|array $primaryKey = ['order_id', 'product_id'];
    protected string $table = 'composite_model';
    protected array $fillable = ['order_id', 'product_id', 'qty'];
}

/**
 * Reading, testing and discarding model properties.
 *
 * Every case here is reachable through ordinary property syntax, which is why
 * the consequences of getting them wrong are silent: a typo, a template
 * printing an unknown key, or a `??` on a relation.
 */
class ModelPropertyAccessTest extends TestCase
{
    /**
     * Method names that are not relations and must not be invoked by reading.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonRelationMethods(): array
    {
        return [
            'ordinary method' => ['wipe'],
            'method without a return type' => ['untyped'],
            'relation method needing an argument' => ['taggedPosts'],
            'static relation method' => ['staticPosts'],
            'protected relation method' => ['hiddenPosts'],
            'inherited save' => ['save'],
            'inherited delete' => ['delete'],
            'inherited refresh' => ['refresh'],
            'inherited toArray' => ['toArray'],
            'inherited getTable' => ['getTable'],
        ];
    }

    #[Test]
    #[DataProvider('nonRelationMethods')]
    public function readingANonRelationMethodNameDoesNotInvokeIt(string $method): void
    {
        $model = new AccessModel(['name' => 'a']);

        $this->assertNull($model->{$method});
        $this->assertFalse($model->sideEffect, "Reading \$model->$method invoked the method.");
    }

    #[Test]
    #[DataProvider('nonRelationMethods')]
    public function testingANonRelationMethodNameDoesNotInvokeIt(string $method): void
    {
        $model = new AccessModel(['name' => 'a']);

        $this->assertFalse(isset($model->{$method}));
        $this->assertFalse($model->sideEffect);
    }

    #[Test]
    public function readingAnUnknownPropertyAnswersNull(): void
    {
        $model = new AccessModel(['name' => 'a']);

        // Through ArrayAccess, which reaches the same __get()/__isset() pair
        // without the test having to declare a property that by definition
        // does not exist.
        $this->assertNull($model['no_such_column']);
        $this->assertFalse(isset($model['no_such_column']));
    }

    #[Test]
    public function readingAnAssignedAttributeStillWorks(): void
    {
        $model = new AccessModel(['name' => 'alice']);

        $this->assertSame('alice', $model->name);
        $this->assertTrue(isset($model->name));
    }

    #[Test]
    public function issetAgreesWithReadingForAPreloadedRelation(): void
    {
        $model = new AccessModel(['name' => 'a']);
        $related = new AccessModel(['name' => 'b']);
        $model->setRelation('posts', $related);

        $this->assertSame($related, $model->posts);
        $this->assertTrue(isset($model->posts), 'isset() disagreed with reading a loaded relation.');
        $this->assertSame($related, $model->posts ?? 'fallback');
    }

    #[Test]
    public function issetAgreesWithReadingForARelationLoadedAsEmpty(): void
    {
        $model = new AccessModel(['name' => 'a']);
        $model->setRelation('posts', []);

        // An empty to-many result is a loaded relation holding an empty list,
        // which is a value: isset() is false only for null, exactly as it is
        // for an ordinary property assigned [].
        $this->assertSame([], $model->posts);
        $this->assertTrue(isset($model->posts));
        $this->assertTrue($model->relationLoaded('posts'));
    }

    #[Test]
    public function issetIsFalseForARelationLoadedAsNull(): void
    {
        $model = new AccessModel(['name' => 'a']);
        $model->setRelation('posts', null);

        $this->assertNull($model->posts);
        $this->assertFalse(isset($model->posts));
        $this->assertTrue($model->relationLoaded('posts'));
    }

    #[Test]
    public function arrayAccessMirrorsPropertyAccessForRelations(): void
    {
        $model = new AccessModel(['name' => 'a']);
        $related = new AccessModel(['name' => 'b']);
        $model->setRelation('posts', $related);

        $this->assertTrue(isset($model['posts']));
        $this->assertSame($related, $model['posts']);
    }

    #[Test]
    public function unsetClearsThePendingChangeToTheAttribute(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->name = 'bob';

        $this->assertTrue($model->isDirty('name'));

        unset($model->name);

        $this->assertFalse($model->isDirty('name'), 'unset() left a stale dirty entry.');
        $this->assertFalse($model->isDirty());
        $this->assertSame([], $model->getDirtyAttributes());
        $this->assertArrayNotHasKey('name', $model->getAttributes());
    }

    #[Test]
    public function unsetClearsALoadedRelation(): void
    {
        $model = new AccessModel(['name' => 'a']);
        $model->setRelation('posts', new AccessModel(['name' => 'b']));

        unset($model->posts);

        $this->assertFalse($model->relationLoaded('posts'), 'unset() left the relation loaded.');
        $this->assertArrayNotHasKey('posts', $model->toArray());
    }

    #[Test]
    public function unsetLeavesOtherPendingChangesAlone(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice', 'note' => 'x']);
        $model->name = 'bob';
        $model->note = 'y';

        unset($model->name);

        $this->assertFalse($model->isDirty('name'));
        $this->assertTrue($model->isDirty('note'));
        $this->assertSame(['note'], $model->getDirtyAttributes());
    }

    #[Test]
    public function reassigningAfterUnsetMarksTheAttributeDirtyAgain(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        unset($model->name);
        $model->name = 'carol';

        $this->assertTrue($model->isDirty('name'));
        $this->assertSame('carol', $model->name);
    }

    #[Test]
    public function offsetUnsetClearsThePendingChangeToo(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model['name'] = 'bob';

        $this->assertTrue($model->isDirty('name'));

        unset($model['name']);

        $this->assertFalse($model->isDirty('name'));
        $this->assertSame([], $model->getDirtyAttributes());
    }

    #[Test]
    public function unsettingAnAbsentAttributeIsHarmless(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);

        unset($model->no_such_column);

        $this->assertSame(['id' => 1, 'name' => 'alice'], $model->getAttributes());
        $this->assertFalse($model->isDirty());
    }

    #[Test]
    public function nonStringOffsetsAreIgnored(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);

        $model[0] = 'ignored';

        $this->assertNull($model[0]);
        $this->assertFalse(isset($model[0]));
        $this->assertSame(['id' => 1, 'name' => 'alice'], $model->getAttributes());

        unset($model[0]);

        $this->assertSame(['id' => 1, 'name' => 'alice'], $model->getAttributes());
    }

    #[Test]
    public function getKeyAnswersNullForANewRecord(): void
    {
        $this->assertNull((new AccessModel(['name' => 'a']))->getKey());
    }

    #[Test]
    public function getKeyAnswersTheScalarKeyOfAnExistingRecord(): void
    {
        $model = AccessModel::hydrate(['id' => 7, 'name' => 'alice']);

        $this->assertSame(7, $model->getKey());
    }

    #[Test]
    public function getKeyAnswersAnObjectForACompositeKey(): void
    {
        $model = CompositeKeyModel::hydrate(['order_id' => 3, 'product_id' => 9, 'qty' => 2]);
        $key = $model->getKey();

        $this->assertInstanceOf(stdClass::class, $key);
        $this->assertSame(['order_id' => 3, 'product_id' => 9], get_object_vars($key));
    }

    #[Test]
    public function toArraySerializesACollectionRelationAsAList(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->setRelation('posts', $this->collectionOf(
            AccessModel::hydrate(['id' => 2, 'name' => 'first']),
            AccessModel::hydrate(['id' => 3, 'name' => 'second']),
        ));

        $array = $model->toArray();

        $this->assertIsArray($array['posts'], 'A Collection relation was not serialized.');
        $this->assertCount(2, $array['posts']);
        $this->assertSame(['id' => 2, 'name' => 'first'], $array['posts'][0]);
        $this->assertSame(['id' => 3, 'name' => 'second'], $array['posts'][1]);
    }

    #[Test]
    public function toJsonEncodesACollectionRelationAsAList(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->setRelation('posts', $this->collectionOf(
            AccessModel::hydrate(['id' => 2, 'name' => 'first']),
        ));

        // The empty object {} is what a bare Collection encodes to: it exposes
        // no public properties for json_encode to find.
        $json = $model->toJson();
        $this->assertStringNotContainsString('"posts":{}', $json);
        $this->assertStringContainsString('"posts":[{"id":2,"name":"first"}]', $json);
    }

    #[Test]
    public function toArraySerializesAnEmptyCollectionRelationAsAnEmptyList(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->setRelation('posts', $this->collectionOf());

        $this->assertSame([], $model->toArray()['posts']);
        $this->assertStringContainsString('"posts":[]', $model->toJson());
    }

    #[Test]
    public function toArrayStillSerializesAnArrayRelation(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->setRelation('posts', [AccessModel::hydrate(['id' => 2, 'name' => 'first'])]);

        $array = $model->toArray();

        $this->assertIsArray($array['posts']);
        $this->assertSame(['id' => 2, 'name' => 'first'], $array['posts'][0]);
    }

    #[Test]
    public function eagerAndLazyRelationShapesSerializeIdentically(): void
    {
        $rows = [
            AccessModel::hydrate(['id' => 2, 'name' => 'first']),
            AccessModel::hydrate(['id' => 3, 'name' => 'second']),
        ];

        $eager = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $eager->setRelation('posts', $rows);

        $lazy = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $lazy->setRelation('posts', $this->collectionOf(...$rows));

        $this->assertSame($eager->toArray(), $lazy->toArray());
        $this->assertSame($eager->toJson(), $lazy->toJson());
    }

    #[Test]
    public function updateRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->name = 'bob';
        unset($model->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('the key attribute "id" is null');

        $model->update();
    }

    #[Test]
    public function deleteRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->id = null;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot build a primary key condition');

        $model->delete();
    }

    #[Test]
    public function updateCounterRefusesToRunWithoutAPrimaryKeyValue(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        $model->id = null;

        $this->expectException(QueryException::class);

        $model->updateCounter('score');
    }

    #[Test]
    public function theMissingKeyMessageNamesTheModelAndTheAttribute(): void
    {
        $model = AccessModel::hydrate(['id' => 1, 'name' => 'alice']);
        unset($model->id);

        try {
            $model->delete();
            $this->fail('A null primary key was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(AccessModel::class, $e->getMessage());
            $this->assertStringContainsString('"id"', $e->getMessage());
            $this->assertStringContainsString('refresh()', $e->getMessage());
        }
    }

    #[Test]
    public function aCompositeKeyRefusesWhenEitherPartIsMissing(): void
    {
        $model = CompositeKeyModel::hydrate(['order_id' => 3, 'product_id' => 9, 'qty' => 2]);
        unset($model->product_id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('"product_id"');

        $model->delete();
    }

    #[Test]
    public function aNewRecordIsUnaffectedByTheMissingKeyGuard(): void
    {
        $model = new AccessModel(['name' => 'alice']);

        // No key is expected on a record that does not exist yet, so nothing
        // here may throw.
        $this->assertFalse($model->update());
        $this->assertFalse($model->delete());
        $this->assertFalse($model->updateCounter('score'));
        $this->assertNull($model->getKey());
    }

    /**
     * Build a Collection over a fixed set of models, without a database.
     *
     * @param Model ...$models The models the collection yields.
     * @return Collection
     */
    private function collectionOf(Model ...$models): Collection
    {
        return new class (array_values($models)) extends Collection {
            /**
             * @param array<int, Model> $models The models to yield.
             */
            public function __construct(private array $models)
            {
            }

            public function getIterator(): \Traversable
            {
                return new \ArrayIterator($this->models);
            }

            public function count(): int
            {
                return count($this->models);
            }
        };
    }
}
