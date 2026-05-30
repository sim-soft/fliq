<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;
use Simsoft\DB\Traits\Scenario;
use Simsoft\Validator;
use Simsoft\Validator\Rule;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 */
class ValidatedUser extends Model
{
    protected string $table = 'users';
    protected string $connection = 'val_test';
    protected array $fillable = ['name', 'email', 'password'];

    public function validate(): bool
    {
        $validator = Validator::make($this->getAttributes(), [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required'),
                new Length(min: 2, max: 100, minMessage: 'Name too short'),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required'),
                new Email(message: 'Invalid email'),
            ]),
            'password' => [
                new NotBlank(message: 'Password is required'),
                new Length(min: 8, minMessage: 'Password too short'),
            ],
        ]);

        if ($validator->fails()) {
            $this->addValidationErrors($validator->errors());
            return false;
        }

        return true;
    }
}

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $avatar
 */
class ScenarioValidatedUser extends Model
{
    use Scenario;

    public const SCENARIO_REGISTER = 'register';
    public const SCENARIO_UPDATE_PROFILE = 'update_profile';

    protected string $table = 'users';
    protected string $connection = 'val_test';
    protected array $fillable = ['name', 'email', 'password', 'avatar'];

    public function validate(): bool
    {
        $validator = Validator::make($this->getAttributes(), [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required', groups: [self::SCENARIO_REGISTER, self::SCENARIO_UPDATE_PROFILE]),
                new Length(min: 2, groups: [self::SCENARIO_REGISTER, self::SCENARIO_UPDATE_PROFILE]),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required', groups: [self::SCENARIO_REGISTER]),
                new Email(message: 'Invalid email', groups: [self::SCENARIO_REGISTER]),
            ]),
            'password' => [
                new NotBlank(message: 'Password is required', groups: [self::SCENARIO_REGISTER]),
                new Length(min: 8, groups: [self::SCENARIO_REGISTER]),
            ],
        ]);

        if (!$validator->validate((string)($this->getScenario() ?? self::SCENARIO_REGISTER))) {
            $this->addValidationErrors($validator->errors());
            return false;
        }

        return true;
    }
}

/**
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $bio
 */
class Profile extends Model
{
    protected string $table = 'profiles';
    protected string $connection = 'val_test';
    protected array $fillable = ['user_id', 'bio'];
}

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 */
class ValidatedUserWithProfile extends Model
{
    protected string $table = 'users';
    protected string $connection = 'val_test';
    protected array $fillable = ['name', 'email', 'password'];

    public function validate(): bool
    {
        $validator = Validator::make($this->getAttributes(), [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required'),
                new Length(min: 2, max: 100),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required'),
                new Email(message: 'Invalid email'),
            ]),
            'password' => [
                new NotBlank(message: 'Password is required'),
                new Length(min: 8),
            ],
        ]);

        if ($validator->fails()) {
            $this->addValidationErrors($validator->errors());
            return false;
        }

        return true;
    }

    public function profile(): Relation
    {
        return $this->hasOne(Profile::class, ['user_id' => 'id']);
    }
}

class ValidationTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('val_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $driver = Connection::get('val_test');
        $driver->execute(new Raw(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, password TEXT, avatar TEXT)'
        ));
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function validDataPassesValidation(): void
    {
        $user = new ValidatedUser([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ]);

        $this->assertTrue($user->save());
        $this->assertTrue($user->exists());
        $this->assertEmpty($user->getErrors());
    }

    #[Test]
    public function emptyDataFailsValidation(): void
    {
        $user = new ValidatedUser([
            'name' => '',
            'email' => '',
            'password' => '',
        ]);

        $this->assertFalse($user->save());
        $this->assertFalse($user->exists());
        $this->assertNotEmpty($user->getErrors());
    }

    #[Test]
    public function invalidEmailFailsValidation(): void
    {
        $user = new ValidatedUser([
            'name' => 'John',
            'email' => 'not-an-email',
            'password' => 'secret123',
        ]);

        $this->assertFalse($user->save());
        $this->assertContains('Invalid email', $user->getErrors());
    }

    #[Test]
    public function shortPasswordFailsValidation(): void
    {
        $user = new ValidatedUser([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => '123',
        ]);

        $this->assertFalse($user->save());
        $this->assertContains('Password too short', $user->getErrors());
    }

    #[Test]
    public function bailStopsAtFirstFailure(): void
    {
        $user = new ValidatedUser([
            'name' => '',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ]);

        $this->assertFalse($user->save());

        // bail: only "Name is required" should appear, not "Name too short"
        $this->assertContains('Name is required', $user->getErrors());
        $this->assertNotContains('Name too short', $user->getErrors());
    }

    #[Test]
    public function multipleErrorsCollectedWithoutBail(): void
    {
        $user = new ValidatedUser([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => '',
        ]);

        $this->assertFalse($user->save());

        // plain array: both errors reported
        $this->assertContains('Password is required', $user->getErrors());
        $this->assertContains('Password too short', $user->getErrors());
    }

    #[Test]
    public function skipValidationWithFlag(): void
    {
        $user = new ValidatedUser([
            'name' => '',
            'email' => 'invalid',
            'password' => '',
        ]);

        // save(validate: false) skips validation
        $this->assertTrue($user->save(validate: false));
        $this->assertTrue($user->exists());
    }

    #[Test]
    public function scenarioRegisterValidatesEmailAndPassword(): void
    {
        $user = (new ScenarioValidatedUser([
            'name' => 'John',
            'email' => 'not-valid',
            'password' => '123',
        ]))->withScenario(ScenarioValidatedUser::SCENARIO_REGISTER);

        $this->assertFalse($user->save());
        $this->assertNotEmpty($user->getErrors());
    }

    #[Test]
    public function scenarioUpdateProfileSkipsEmailAndPassword(): void
    {
        // Insert a user first
        $driver = Connection::get('val_test');
        $driver->execute(new Raw(
            'INSERT INTO users (id, name, email, password) VALUES (?, ?, ?, ?)',
            [1, 'John', 'john@example.com', 'secret123']
        ));

        $user = ScenarioValidatedUser::findByPk(1);
        $this->assertNotNull($user);
        $user->withScenario(ScenarioValidatedUser::SCENARIO_UPDATE_PROFILE);
        $user->name = 'John Updated';

        // email and password are not validated in update_profile scenario
        $this->assertTrue($user->save());
    }

    #[Test]
    public function scenarioUpdateProfileValidatesName(): void
    {
        $user = (new ScenarioValidatedUser([
            'name' => '',
        ]))->withScenario(ScenarioValidatedUser::SCENARIO_UPDATE_PROFILE);

        $this->assertFalse($user->save());
        $this->assertContains('Name is required', $user->getErrors());
    }

    #[Test]
    public function saveTogetherRunsValidationByDefault(): void
    {
        // Create profiles table for relation
        $driver = Connection::get('val_test');
        $driver->execute(new Raw(
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)'
        ));

        // saveTogether now validates by default
        $user = new ValidatedUserWithProfile();
        $result = $user->saveTogether([
            'name' => '',
            'email' => 'invalid',
            'password' => '1',
        ]);

        // Validation fails — nothing saved
        $this->assertFalse($result);
        $this->assertFalse($user->exists());
        $this->assertNotEmpty($user->getErrors());
    }

    #[Test]
    public function saveTogetherSkipsValidationWhenDisabled(): void
    {
        // Create profiles table for relation
        $driver = Connection::get('val_test');
        $driver->execute(new Raw(
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)'
        ));

        // saveTogether with validate: false skips validation
        $user = new ValidatedUserWithProfile();
        $result = $user->saveTogether([
            'name' => '',
            'email' => 'invalid',
            'password' => '1',
        ], validate: false);

        // Saves despite invalid data
        $this->assertTrue($result);
        $this->assertTrue($user->exists());
    }

    #[Test]
    public function validatePassesThenSaveTogetherWithRelation(): void
    {
        // Create profiles table for relation
        $driver = Connection::get('val_test');
        $driver->execute(new Raw(
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)'
        ));

        $user = new ValidatedUserWithProfile([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ]);

        // Validate first
        $this->assertTrue($user->validate());
        $this->assertEmpty($user->getErrors());

        // Then saveTogether with relation data
        $result = $user->saveTogether([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'profile' => ['bio' => 'Hello world'],
        ]);

        $this->assertTrue($result);
        $this->assertTrue($user->exists());

        // Verify profile was saved
        $profile = Profile::find()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('Hello world', $profile->bio);
    }

    #[Test]
    public function isDirtyChecksSpecificAttribute(): void
    {
        $driver = Connection::get('val_test');
        $driver->execute(new Raw(
            'INSERT INTO users (id, name, email, password) VALUES (?, ?, ?, ?)',
            [1, 'John', 'john@example.com', 'secret123']
        ));

        $user = ValidatedUser::findByPk(1);
        $this->assertNotNull($user);

        $this->assertFalse($user->isDirty());
        $this->assertFalse($user->isDirty('name'));

        $user->name = 'Jane';

        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
        $this->assertTrue($user->isDirty('name', 'email')); // true if any match
    }
}
