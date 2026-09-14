<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\DB;

/**
 * The rebuild fixes, against a real server.
 *
 * The unit tests check the statement that comes out. These check what the
 * database does with it: a builder inspected and then mutated used to execute
 * the statement as it stood before the mutation, and a counter combined with an
 * attribute sent its values in the wrong order and wrote each into the other's
 * column.
 */
class BuilderRebuildExecutionTest extends DatabaseTestCase
{
    #[Test]
    public function anAttributeAndACounterEachReachTheirOwnColumn(): void
    {
        // The counter's value was bound ahead of the attribute's, so MySQL was
        // handed 'ALPHA' for status_code and rejected the statement with
        // "Truncated incorrect INTEGER value" — nothing was written at all.
        $before = DB::query('SELECT status_code FROM user WHERE id = 1', [], 'mysql');
        $this->assertNotEmpty($before);
        $start = (int)$before[0]['status_code'];

        $update = new Update('user', ['username' => 'ALPHA'], 'id = 1');
        $update->withConnection('mysql')->setCounter('status_code', 7);

        $this->assertTrue($update->execute());

        $after = DB::query('SELECT username, status_code FROM user WHERE id = 1', [], 'mysql');
        $this->assertSame('ALPHA', $after[0]['username']);
        $this->assertSame($start + 7, (int)$after[0]['status_code']);
    }

    #[Test]
    public function aCounterAddedAfterAReadIsActuallyApplied(): void
    {
        // Reading first froze "SET 1 = 1", which is not a statement MySQL
        // accepts: the update failed with a syntax error rather than running
        // without the counter.
        $before = DB::query('SELECT status_code FROM user WHERE id = 2', [], 'mysql');
        $start = (int)$before[0]['status_code'];

        $update = new Update('user', [], 'id = 2');
        $update->withConnection('mysql');
        $update->getSQL();
        $update->setCounter('status_code', 5);

        $this->assertTrue($update->execute());

        $after = DB::query('SELECT status_code FROM user WHERE id = 2', [], 'mysql');
        $this->assertSame($start + 5, (int)$after[0]['status_code']);
    }

    #[Test]
    public function theDocumentedSqlOnlyInspectThenRunFlowRunsWhatItShowed(): void
    {
        // DB::sqlOnly() exists to hand the builder back so its SQL can be read,
        // and the docs show exactly that. Reading it used to freeze it.
        DB::sqlOnly();

        try {
            $builder = DB::update('user', ['status_code' => 4], 'id = 3');
        } finally {
            DB::disableSqlOnly();
        }

        $this->assertInstanceOf(Update::class, $builder);

        $inspected = $builder->getSQL();
        $builder->lowPriority();
        $executed = $builder->getSQL();

        $this->assertStringContainsString('LOW_PRIORITY', $executed);
        $this->assertStringNotContainsString('LOW_PRIORITY', $inspected);
        $this->assertTrue($builder->withConnection('mysql')->execute());

        $after = DB::query('SELECT status_code FROM user WHERE id = 3', [], 'mysql');
        $this->assertSame(4, (int)$after[0]['status_code']);
    }

    #[Test]
    public function aStatementDumpedMidBuildStillRunsCorrectly(): void
    {
        // dump() reads the SQL, which is the whole point of it — debugging a
        // half-built query must not change what that query then does.
        $update = new Update('user', ['status_code' => 6], 'id = 4');
        $update->withConnection('mysql');

        ob_start();
        $update->dump();
        ob_end_clean();

        $this->assertTrue($update->execute());

        $after = DB::query('SELECT status_code FROM user WHERE id = 4', [], 'mysql');
        $this->assertSame(6, (int)$after[0]['status_code']);
    }

    #[Test]
    public function aBuilderWhoseConditionWasReplacedRunsOnlyTheSecondOne(): void
    {
        // The first condition's binds used to be kept alongside the second's,
        // leaving more values than placeholders; the driver refused it.
        $update = new Update('user', ['status_code' => 8], 'id = 5');
        $update->withConnection('mysql');
        $update->condition('id = 6');

        $this->assertTrue($update->execute());

        $rows = DB::query('SELECT id, status_code FROM user WHERE id IN (5, 6) ORDER BY id', [], 'mysql');
        $this->assertSame(8, (int)$rows[1]['status_code'], 'the second condition should have matched');
        $this->assertNotSame(8, (int)$rows[0]['status_code'], 'the first condition should not have matched');
    }

    #[Test]
    public function updateCounterThroughTheModelApiWorks(): void
    {
        // Model::updateCounter() is the public route to setCounter(), and it
        // sets the connection after building — the order that used to misquote.
        $user = User::find()->where('id', 7)->first();
        $this->assertInstanceOf(User::class, $user);
        $start = (int)$user->status_code;

        $this->assertTrue($user->updateCounter('status_code', 3));

        $after = DB::query('SELECT status_code FROM user WHERE id = 7', [], 'mysql');
        $this->assertSame($start + 3, (int)$after[0]['status_code']);
    }

    #[Test]
    public function bindsReadBeforeTheSqlAreTheOnesThatExecute(): void
    {
        $update = new Update('user', ['status_code' => 9], 'id = 8');
        $update->withConnection('mysql');

        // Reading the binds first used to answer null on the write builders.
        $this->assertSame([9], $update->getBinds());
        $this->assertTrue($update->execute());

        $after = DB::query('SELECT status_code FROM user WHERE id = 8', [], 'mysql');
        $this->assertSame(9, (int)$after[0]['status_code']);
    }
}
