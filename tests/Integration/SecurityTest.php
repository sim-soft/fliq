<?php

namespace Integration;

use InvalidArgumentException;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Security tests — verifies SQL injection is prevented by parameter binding.
 */
class SecurityTest extends DatabaseTestCase
{
    #[Test]
    public function whereBindingPreventsInjection(): void
    {
        // Attempt SQL injection via where value
        $malicious = "' OR '1'='1";
        $users = User::find()->where('username', $malicious)->get()->all();

        // Should return zero results — injection is neutralized by binding
        $this->assertEmpty($users);
    }

    #[Test]
    public function likeBindingPreventsInjection(): void
    {
        $malicious = "%' OR '1'='1' --";
        $users = User::find()->like('username', $malicious)->get()->all();

        $this->assertEmpty($users);
    }

    #[Test]
    public function inBindingPreventsInjection(): void
    {
        $malicious = ["alice', 1) OR (1=1 --"];
        $users = User::find()->in('username', $malicious)->get()->all();

        $this->assertEmpty($users);
    }

    #[Test]
    public function betweenBindingPreventsInjection(): void
    {
        // Binding prevents SQL injection — the malicious string is treated as a literal value
        // MySQL may cast it to 0, but the query structure is never altered
        $malicious = "0 OR 1=1 --";
        $query = User::find()->between('score', $malicious, $malicious);
        $sql = $query->getSQL();

        // Verify the SQL uses ? placeholders, not the raw string
        $this->assertStringContainsString('BETWEEN ? AND ?', $sql);
        $this->assertStringNotContainsString('OR 1=1', $sql);
    }

    #[Test]
    public function rawQueryWithBindingIsSafe(): void
    {
        $malicious = "' OR '1'='1";
        $raw = new Raw('SELECT * FROM `user` WHERE `username` = ?', [$malicious]);
        $raw->withConnection('mysql');
        $results = $raw->fetchAll();

        $this->assertEmpty($results);
    }

    #[Test]
    public function jsonPathBindingPreventsInjection(): void
    {
        $malicious = "' OR '1'='1";
        $query = new ActiveQuery()
            ->from('setting')
            ->whereJson('metadata->priority', '=', $malicious)
            ->withConnection('mysql');

        $results = $query->query($query);
        $this->assertEmpty($results);
    }

    #[Test]
    public function orderByDirectionIsRestrictedToAscDesc(): void
    {
        // A malicious direction (e.g., from a ?sort= parameter) must not reach the SQL
        $sql = User::find()->orderBy(['id' => 'ASC, (SELECT 1)'])->getSQL();

        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringNotContainsString('SELECT 1', $sql);
        $this->assertStringEndsWith('ASC', $sql);

        // The scalar form is guarded identically
        $scalar = User::find()->orderBy('id', 'DESC; DROP TABLE user')->getSQL();
        $this->assertStringNotContainsString('DROP', $scalar);
        $this->assertStringEndsWith('ASC', $scalar);
    }

    #[Test]
    public function orderByStillHonoursValidDirections(): void
    {
        $sql = User::find()->orderBy(['username' => 'asc', 'score' => 'desc'])->getSQL();

        $this->assertStringContainsString('ASC', $sql);
        $this->assertStringContainsString('DESC', $sql);

        // And the query still executes and sorts correctly
        $scores = array_map(
            static fn(User $user): int => (int)$user->score,
            User::find()->orderBy(['score' => 'DESC'])->get()->all()
        );
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    #[Test]
    public function updateAppliesMassAssignmentRules(): void
    {
        $user = User::find()->where('username', 'alice')->first();
        $this->assertNotNull($user);
        /** @var User $user */
        $originalId = $user->id;
        $originalScore = (int)$user->score;

        // 'id' is guarded; 'score' is fillable. Simulates $user->update($_POST).
        $user->update(['score' => 77, 'id' => 9999]);

        // The guarded primary key was stripped — the row is still addressable by its original id
        $reloaded = User::findByPk($originalId);
        $this->assertNotNull($reloaded);
        /** @var User $reloaded */
        $this->assertEquals($originalId, $reloaded->id);

        // The fillable attribute was written
        $this->assertEquals(77, (int)$reloaded->score);

        $this->assertNull(User::findByPk(9999));

        $reloaded->updateAttributes(['score' => $originalScore]);
    }

    #[Test]
    public function updateKeepsTrustedDirectlyAssignedAttributes(): void
    {
        $user = User::find()->where('username', 'bob')->first();
        $this->assertNotNull($user);
        /** @var User $user */
        $userId = $user->id;

        // 'deleted_at' is outside $fillable, so mass assignment must drop it...
        $user->update(['deleted_at' => '2020-01-01 00:00:00']);
        $reloaded = User::findByPk($userId);
        $this->assertNotNull($reloaded, 'mass-assigned deleted_at must be ignored');

        // ...but direct assignment is trusted and must still be written
        /** @var User $reloaded */
        $reloaded->deleted_at = '2020-01-01 00:00:00';
        $reloaded->update();

        $raw = User::find()->where('id', $userId)->getArray();
        $row = iterator_to_array($raw)[0] ?? null;
        $this->assertNotNull($row);
        $this->assertStringStartsWith('2020-01-01', (string)$row['deleted_at']);

        $reloaded->updateAttributes(['deleted_at' => null]);
    }

    #[Test]
    public function comparisonOperatorIsRestrictedToAWhitelist(): void
    {
        // The operator is interpolated into SQL and cannot be bound, so an
        // attacker-supplied operator (e.g., from a ?op= parameter) must be rejected.
        $this->expectException(InvalidArgumentException::class);
        User::find()->where('id', 'UNION SELECT', 1)->getSQL();
    }

    #[Test]
    public function operatorWhitelistCoversEveryConditionMethod(): void
    {
        $attacks = [
            'having' => static fn() => User::find()->groupBy('id')->having('id', 'UNION SELECT', 1),
            'whereColumn' => static fn() => User::find()->whereColumn('id', 'UNION SELECT', 'id'),
            'whereAny' => static fn() => User::find()->whereAny(['id', 'score'], 'UNION SELECT', 1),
            'whereJson' => static fn() => User::find()->whereJson('meta->a', 'UNION SELECT', 1),
            'whereDate' => static fn() => User::find()->whereDate('created_at', 'UNION SELECT', '2024-01-01'),
        ];

        foreach ($attacks as $method => $attack) {
            try {
                $attack();
                $this->fail("$method accepted an invalid operator");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function operatorWhitelistStillAllowsValidComparisons(): void
    {
        // Shorthand — the value sits in the operator position and must not be rejected
        $this->assertStringContainsString(
            '= ?',
            User::find()->where('username', 'alice')->getSQL()
        );

        // Symbol and word operators, in either case
        $this->assertStringContainsString('> ?', User::find()->where('score', '>', 5)->getSQL());
        $this->assertStringContainsString('LIKE ?', User::find()->where('username', 'like', 'a%')->getSQL());

        // Null comparison still collapses to IS NULL rather than hitting the whitelist
        $this->assertStringContainsString(
            'IS NULL',
            User::find()->where('deleted_at', null)->getSQL()
        );

        // And a whitelisted operator still returns correct rows
        $highScorers = User::find()->where('score', '>=', 0)->get()->all();
        $this->assertNotEmpty($highScorers);
    }

    #[Test]
    public function normalQueriesStillWorkAfterInjectionAttempts(): void
    {
        // Verify the DB is intact after injection attempts
        $count = User::find()->count();
        $this->assertEquals(10, $count);

        $alice = User::find()->where('username', 'alice')->first();
        $this->assertNotNull($alice);
        /** @var User $alice */
        $this->assertEquals('alice', $alice->username);
    }
}
