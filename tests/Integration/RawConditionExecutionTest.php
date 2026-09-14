<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Aggregations\Count;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

/**
 * A `Raw` condition, run against a server.
 *
 * The shape tests hold the SQL; these hold the outcome. A statement built from
 * a `Raw` condition used to be rejected by the server for a syntax error, so
 * nothing here could run at all — which is why the gap survived: the failure
 * was loud, but only reachable by someone who wrote the condition that way.
 */
class RawConditionExecutionTest extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!static::$dbAvailable) {
            return;
        }

        $driver = Connection::get('mysql');
        $driver->execute(new Raw('DROP TABLE IF EXISTS `rawcond`'));
        $driver->execute(new Raw(
            'CREATE TABLE `rawcond` (`id` INT PRIMARY KEY AUTO_INCREMENT, `n` INT, `label` VARCHAR(32))'
        ));
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$dbAvailable) {
            Connection::get('mysql')->execute(new Raw('DROP TABLE IF EXISTS `rawcond`'));
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $driver = Connection::get('mysql');
        $driver->execute(new Raw('DELETE FROM `rawcond`'));
        $driver->execute(new Raw(
            "INSERT INTO `rawcond` (`n`, `label`) VALUES (1, 'alpha'), (2, 'beta'), (3, 'gamma'), (4, 'delta')"
        ));
    }

    /**
     * Rows left in the scratch table, optionally narrowed.
     *
     * @param string $where An optional WHERE body.
     * @return int
     */
    private function rows(string $where = '1 = 1'): int
    {
        $result = Connection::get('mysql')->query(new Raw("SELECT COUNT(*) AS `c` FROM `rawcond` WHERE $where"));

        return (int)($result[0]['c'] ?? -1);
    }

    /**
     * A model over the scratch table.
     *
     * @return Model
     */
    private function scratch(): Model
    {
        return new RawCondModel();
    }

    #[Test]
    public function deleteAllRunsWithARawConditionThatOmitsTheKeyword(): void
    {
        $this->assertTrue($this->scratch()->deleteAll(new Raw('`n` > ?', [2])));

        $this->assertSame(2, $this->rows());
        $this->assertSame(2, $this->rows('`n` <= 2'));
    }

    #[Test]
    public function deleteAllRunsWithARawConditionThatIncludesTheKeyword(): void
    {
        // Both spellings have to reach the same statement, because both are
        // reasonable readings of "pass the condition".
        $this->assertTrue($this->scratch()->deleteAll(new Raw('WHERE `n` > ?', [2])));

        $this->assertSame(2, $this->rows());
    }

    #[Test]
    public function aRawConditionsBindsAreAppliedInOrder(): void
    {
        $this->scratch()->deleteAll(new Raw('`n` > ? AND `label` <> ?', [1, 'delta']));

        // Removes 2 and 3, keeps 1 (fails n > 1) and 4 (label is delta).
        $this->assertSame(2, $this->rows());
        $this->assertSame(1, $this->rows("`label` = 'alpha'"));
        $this->assertSame(1, $this->rows("`label` = 'delta'"));
    }

    #[Test]
    public function updateRunsWithARawConditionThatOmitsTheKeyword(): void
    {
        $query = new Update('rawcond', ['n' => 0], new Raw('`label` = ?', ['beta']));
        $query->withConnection('mysql');

        $this->assertTrue($query->execute());
        $this->assertSame(1, $this->rows('`n` = 0'));
        $this->assertSame(1, $this->rows("`n` = 0 AND `label` = 'beta'"));
    }

    #[Test]
    public function deleteBuilderRunsWithARawConditionDirectly(): void
    {
        $query = new Delete('rawcond', new Raw('`label` IN (?, ?)', ['alpha', 'beta']));
        $query->withConnection('mysql');

        $this->assertTrue($query->execute());
        $this->assertSame(2, $this->rows());
    }

    #[Test]
    public function anAggregateCountsWithARawCondition(): void
    {
        $count = new Count('rawcond', '*');
        $count->withConnection('mysql')->condition(new Raw('`n` > ?', [2]));

        $this->assertSame(2, (int)$count->queryScalar());
    }

    #[Test]
    public function anAggregateAgreesWithTheServerOnTheFixture(): void
    {
        $count = new Count('user', '*');
        $count->withConnection('mysql')->condition(new Raw('`score` > ?', [80]));

        $expected = (int)(Connection::get('mysql')
            ->query(new Raw('SELECT COUNT(*) AS `c` FROM `user` WHERE `score` > 80'))[0]['c'] ?? -1);

        $this->assertSame($expected, (int)$count->queryScalar());
        $this->assertSame($expected, User::find()->where('score', '>', 80)->count());
    }

    #[Test]
    public function aRawConditionUsingLeftIsTreatedAsACondition(): void
    {
        // LEFT is a join keyword and a string function; reading it as the
        // former would emit a statement with no WHERE at all — which, on a
        // DELETE, empties the table.
        $count = new Count('rawcond', '*');
        $count->withConnection('mysql')->condition(new Raw('LEFT(`label`, 1) = ?', ['a']));

        $this->assertSame(1, (int)$count->queryScalar());

        $this->scratch()->deleteAll(new Raw('LEFT(`label`, 1) = ?', ['a']));
        $this->assertSame(3, $this->rows());
    }

    #[Test]
    public function anAggregateStillAcceptsAConditionThatOpensWithAJoin(): void
    {
        $count = new Count('user', '*');
        $count->withConnection('mysql')->condition(new Raw(
            'INNER JOIN `department` ON `department`.`id` = `user`.`department_id` WHERE `user`.`score` > ?',
            [50]
        ));

        $expected = (int)(Connection::get('mysql')->query(new Raw(
            'SELECT COUNT(*) AS `c` FROM `user` INNER JOIN `department`'
            . ' ON `department`.`id` = `user`.`department_id` WHERE `user`.`score` > 50'
        ))[0]['c'] ?? -1);

        $this->assertSame($expected, (int)$count->queryScalar());
    }

    #[Test]
    public function aStringConditionThatAlreadySaysWhereRuns(): void
    {
        $this->assertTrue($this->scratch()->deleteAll('WHERE `n` > 2'));

        $this->assertSame(2, $this->rows());
    }

    #[Test]
    public function theFixtureIsUntouched(): void
    {
        $result = Connection::get('mysql')->query(new Raw('SELECT COUNT(*) AS `c` FROM `user`'));

        $this->assertSame(10, (int)($result[0]['c'] ?? -1));
    }
}

/**
 * A model over the scratch table this class creates.
 */
class RawCondModel extends Model
{
    protected string $table = 'rawcond';

    protected string $connection = 'mysql';

    protected array $fillable = ['n', 'label'];
}
