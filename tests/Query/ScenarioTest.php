<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Model;
use Simsoft\DB\Traits\Scenario;

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $password
 */
class ScenarioModel extends Model
{
    use Scenario;

    public const SCENARIO_REGISTER = 'register';
    public const SCENARIO_UPDATE = 'update';
    public const SCENARIO_ADMIN = 'admin';

    protected string $table = 'user';
    protected string $connection = 'mysql';
    protected array $fillable = ['name', 'password'];
}

class ScenarioTest extends TestCase
{
    #[Test]
    public function defaultScenarioIsNull(): void
    {
        $model = new ScenarioModel();
        $this->assertNull($model->getScenario());
        $this->assertFalse($model->hasScenario());
    }

    #[Test]
    public function withScenarioSetsValue(): void
    {
        $model = new ScenarioModel();
        $model->withScenario(ScenarioModel::SCENARIO_REGISTER);

        $this->assertSame('register', $model->getScenario());
        $this->assertTrue($model->hasScenario());
    }

    #[Test]
    public function withScenarioReturnsSelf(): void
    {
        $model = new ScenarioModel();
        $result = $model->withScenario('register');
        $this->assertSame($model, $result);
    }

    #[Test]
    public function withScenarioAcceptsNull(): void
    {
        $model = new ScenarioModel();
        $model->withScenario('register');
        $model->withScenario(null);

        $this->assertNull($model->getScenario());
        $this->assertFalse($model->hasScenario());
    }

    #[Test]
    public function withScenarioAcceptsInt(): void
    {
        $model = new ScenarioModel();
        $model->withScenario(42);

        $this->assertSame(42, $model->getScenario());
        $this->assertTrue($model->isScenario(42));
    }

    #[Test]
    public function isScenarioMatchesExact(): void
    {
        $model = new ScenarioModel();
        $model->withScenario(ScenarioModel::SCENARIO_REGISTER);

        $this->assertTrue($model->isScenario('register'));
        $this->assertFalse($model->isScenario('update'));
        $this->assertFalse($model->isScenario('REGISTER'));
    }

    #[Test]
    public function isScenarioUsesStrictComparison(): void
    {
        $model = new ScenarioModel();
        $model->withScenario(1);

        $this->assertTrue($model->isScenario(1));
        $this->assertFalse($model->isScenario('1'));
    }

    #[Test]
    public function isAnyScenarioMatchesOne(): void
    {
        $model = new ScenarioModel();
        $model->withScenario(ScenarioModel::SCENARIO_UPDATE);

        $this->assertTrue($model->isAnyScenario(
            ScenarioModel::SCENARIO_REGISTER,
            ScenarioModel::SCENARIO_UPDATE,
            ScenarioModel::SCENARIO_ADMIN
        ));
    }

    #[Test]
    public function isAnyScenarioReturnsFalseWhenNoMatch(): void
    {
        $model = new ScenarioModel();
        $model->withScenario(ScenarioModel::SCENARIO_UPDATE);

        $this->assertFalse($model->isAnyScenario(
            ScenarioModel::SCENARIO_REGISTER,
            ScenarioModel::SCENARIO_ADMIN
        ));
    }

    #[Test]
    public function isAnyScenarioReturnsFalseWhenNoScenarioSet(): void
    {
        $model = new ScenarioModel();
        $this->assertFalse($model->isAnyScenario('register', 'update'));
    }

    #[Test]
    public function scenarioIsChainable(): void
    {
        $model = (new ScenarioModel())
            ->withScenario(ScenarioModel::SCENARIO_REGISTER);

        $model->name = 'John';

        $this->assertSame('register', $model->getScenario());
        $this->assertSame('John', $model->name);
    }
}
