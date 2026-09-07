<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;

/**
 * Which method names a `saveTogether()` payload key is allowed to invoke.
 *
 * `separateRelations()` has to decide whether a key names a relation or a
 * column, and for an array-valued key it decided by CALLING the method and
 * looking at what came back. The guard on that was `method_exists()`, so any
 * zero-argument method reachable by name ran: `saveTogether(['delete' => [...]])`
 * deleted the row and then carried on saving it.
 *
 * Payload keys come from request bodies, so the caller does not pick these
 * names. This is the same hole `ResolvesRelations` closed for `$model->foo`
 * reads, and these tests hold both entry points to the same rule.
 */
class RelationKeyGuardTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            Connection::get('mysql')->execute(
                new Raw("DELETE FROM `user` WHERE `username` LIKE '[relkey]%'")
            );
        }

        parent::tearDown();
    }

    /**
     * A saved user this test class owns outright.
     *
     * @return User
     */
    private function subject(): User
    {
        $user = new User();
        $user->fill([
            'username' => '[relkey]subject',
            'email' => 'relkey-subject@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 10,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        return $user;
    }

    /**
     * Whether a row with this id is still on the server.
     *
     * @param int|string|null $id The primary key to look for.
     * @return bool
     */
    private function rowExists(int|string|null $id): bool
    {
        return User::findByPk((int)$id) !== null;
    }

    #[Test]
    public function aPayloadKeyNamedDeleteDoesNotDeleteTheRow(): void
    {
        $user = $this->subject();
        $id = $user->id;

        // delete() is public, takes no arguments, and lives on every model.
        // The value being an array is what used to send isRelationKey() off to
        // call it — and it returned a bool, so the key was then treated as a
        // column and the save continued as if nothing had happened.
        $user->saveTogether(['score' => 42, 'delete' => ['anything']], validate: false);

        $this->assertTrue($this->rowExists($id), 'saveTogether() deleted the row it was asked to save.');
    }

    #[Test]
    public function theSurvivingRowStillReceivedItsRealAttributes(): void
    {
        $user = $this->subject();
        $id = $user->id;

        $user->saveTogether(['score' => 42, 'delete' => ['anything']], validate: false);

        $fresh = User::findByPk((int)$id);
        $this->assertNotNull($fresh);
        $this->assertSame(42, (int)$fresh->score);
    }

    /**
     * Zero-argument public methods a payload key must not be able to invoke.
     *
     * Each of these used to run merely because its name appeared as a key with
     * an array value. Most only read, but they are on the same footing as
     * delete() — nothing distinguished them.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonRelationMethodNames(): array
    {
        return [
            'delete' => ['delete'],
            'deleteAllUnchecked' => ['deleteAllUnchecked'],
            'save' => ['save'],
            'insert' => ['insert'],
            'update' => ['update'],
            'refresh' => ['refresh'],
            'validate' => ['validate'],
            'toArray' => ['toArray'],
            'replicate' => ['replicate'],
            'getAttributes' => ['getAttributes'],
            'getErrors' => ['getErrors'],
            'getKey' => ['getKey'],
        ];
    }

    #[Test]
    #[DataProvider('nonRelationMethodNames')]
    public function noOrdinaryMethodNameIsTreatedAsARelation(string $method): void
    {
        $isRelationKey = new ReflectionMethod(User::class, 'isRelationKey');
        $isRelationKey->setAccessible(true);

        $this->assertFalse($isRelationKey->invoke(new User(), $method, ['some' => 'array']));
    }

    /**
     * The relation methods that must keep working.
     *
     * @return array<string, array{0: string}>
     */
    public static function relationMethodNames(): array
    {
        return [
            'profile' => ['profile'],
            'posts' => ['posts'],
            'orders' => ['orders'],
            'comments' => ['comments'],
            'getProfile' => ['getProfile'],
            'getPosts' => ['getPosts'],
        ];
    }

    #[Test]
    #[DataProvider('relationMethodNames')]
    public function aRealRelationIsStillRecognised(string $method): void
    {
        $isRelationKey = new ReflectionMethod(User::class, 'isRelationKey');
        $isRelationKey->setAccessible(true);

        $this->assertTrue($isRelationKey->invoke(new User(), $method, [['title' => 'x']]));
    }

    #[Test]
    public function aNameThatIsNoMethodAtAllIsAColumn(): void
    {
        $isRelationKey = new ReflectionMethod(User::class, 'isRelationKey');
        $isRelationKey->setAccessible(true);

        $this->assertFalse($isRelationKey->invoke(new User(), 'username', ['a']));
        $this->assertFalse($isRelationKey->invoke(new User(), 'no_such_thing', ['a']));
    }

    #[Test]
    public function theSaveGuardAndTheLazyLoadGuardAgree(): void
    {
        // Two places decide "is this name a relation?" — this one and the one
        // reading $model->foo. They disagreed, and the looser of the two was
        // the one handling request payloads. Whatever else changes, they must
        // not drift apart again.
        $isRelationKey = new ReflectionMethod(User::class, 'isRelationKey');
        $isRelationKey->setAccessible(true);
        $isRelationMethod = new ReflectionMethod(User::class, 'isRelationMethod');
        $isRelationMethod->setAccessible(true);

        $user = new User();

        foreach (get_class_methods(User::class) as $method) {
            $reflected = new ReflectionMethod(User::class, $method);
            if ($reflected->isStatic() || $reflected->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $this->assertSame(
                (bool)$isRelationMethod->invoke($user, $method),
                (bool)$isRelationKey->invoke($user, $method, [['x' => 1]]),
                "The two relation guards disagree about $method()."
            );
        }
    }

    #[Test]
    public function aModelValuedKeyIsAlsoGuarded(): void
    {
        // The Model-instance arm returns true before the array check, so it
        // needed the same guard in front of it or the name still decided.
        $isRelationKey = new ReflectionMethod(User::class, 'isRelationKey');
        $isRelationKey->setAccessible(true);

        $this->assertFalse($isRelationKey->invoke(new User(), 'delete', new User()));
        $this->assertTrue($isRelationKey->invoke(new User(), 'profile', new User()));
    }
}
