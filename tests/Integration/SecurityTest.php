<?php

namespace Integration;

use BadMethodCallException;
use InvalidArgumentException;
use Models\Industry;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Exceptions\MassAssignmentException;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Grammar\PostgresGrammar;
use Simsoft\DB\Model;
use Simsoft\DB\Query;

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
    public function columnNameCannotEscapeIdentifierQuoting(): void
    {
        // A backtick in a column name previously closed the quoting early and
        // the remainder was parsed as SQL, returning every username.
        $payload = 'id` FROM `user` UNION SELECT username FROM `user` -- ';

        $sql = User::find()->select($payload)->getSQL();

        // The backticks are doubled, so the payload stays one literal identifier.
        $this->assertStringNotContainsString('UNION SELECT username FROM `user`', $sql);
        $this->assertStringContainsString('``', $sql);

        // And the query no longer executes.
        $this->expectException(QueryException::class);
        iterator_to_array(User::find()->select($payload)->get());
    }

    #[Test]
    public function identifierQuotingLeavesValidColumnFormsIntact(): void
    {
        // Escaping must not disturb the forms the builder legitimately supports.
        $this->assertStringContainsString('`user`.*', User::find()->select('*')->getSQL());
        $this->assertStringContainsString('`user`.*', User::find()->select('user.*')->getSQL());
        $this->assertStringContainsString('`user`.`id`', User::find()->select('id')->getSQL());
        $this->assertStringContainsString('`user`.`id`', User::find()->select('user.id')->getSQL());
        $this->assertStringContainsString('`user`.`id`', User::find()->select('!user.id')->getSQL());

        $this->assertCount(10, User::find()->get()->all());
    }

    #[Test]
    public function jsonPathCannotEscapeItsStringLiteral(): void
    {
        // JSON paths cannot be bound, so they are interpolated into a quoted
        // literal. An unescaped quote in the path used to close that literal.
        $payload = "meta->x' ) ) , (SELECT password FROM `user` LIMIT 1) AS leaked FROM `user` -- ";

        $sql = User::find()->select($payload)->getSQL();

        // The quote is doubled, so the payload stays inside the literal rather
        // than closing it. The sub-query text is still present — as inert text.
        $this->assertStringContainsString("'\$.x''", $sql);

        // The JSON_EXTRACT call is still closed by the builder, not by the
        // payload: the literal runs to the generated closing quote.
        $this->assertStringEndsWith("-- ')) FROM `user`", $sql);

        // Every quote in the statement is balanced.
        $this->assertSame(0, substr_count($sql, "'") % 2);
    }

    #[Test]
    public function jsonPathEscapingLeavesValidPathsIntact(): void
    {
        $this->assertStringContainsString(
            "'$.age'",
            User::find()->select('meta->age')->getSQL()
        );
        $this->assertStringContainsString(
            "'$.profile.city'",
            User::find()->select('meta->profile.city')->getSQL()
        );
    }

    #[Test]
    public function fulltextSearchConfigCannotEscapeItsStringLiteral(): void
    {
        // PostgreSQL's text search config is a literal, not a bindable value.
        $grammar = new PostgresGrammar();
        $payload = "english') , (SELECT password FROM users LIMIT 1) -- ";

        $sql = $grammar->fulltextSearch(['"body"'], 'plain', $payload);

        // The quote is doubled, so the payload cannot close the literal.
        $this->assertStringContainsString("'english''", $sql);
        $this->assertSame(0, substr_count($sql, "'") % 2);

        // A valid config is unchanged.
        $this->assertSame(
            'to_tsvector(\'english\', "body") @@ plainto_tsquery(\'english\', ?)',
            $grammar->fulltextSearch(['"body"'], 'plain', 'english')
        );
    }

    #[Test]
    public function arrayCastTypeIsRestrictedToASimpleIdentifier(): void
    {
        // The cast type is a bare keyword — it can be neither quoted nor bound.
        $grammar = new PostgresGrammar();

        $this->expectException(InvalidArgumentException::class);
        $grammar->arrayContains('"tags"', 'text[] , (SELECT password FROM users) -- ');
    }

    #[Test]
    public function arrayCastTypeStillAcceptsValidTypes(): void
    {
        $grammar = new PostgresGrammar();

        $this->assertStringContainsString('::text[]', $grammar->arrayContains('"tags"', 'text'));
        $this->assertStringContainsString('::integer[]', $grammar->arrayOverlaps('"tags"', 2, 'integer'));

        // Multi-word types remain valid.
        $this->assertStringContainsString(
            '::double precision[]',
            $grammar->arrayContains('"tags"', 'double precision')
        );
    }

    #[Test]
    public function undeclaredMassAssignmentRulesAreAcceptedByDefault(): void
    {
        // The permissive default is deliberate: tightening it silently would
        // break existing models at runtime.
        $industry = new Industry();
        $industry->fill(['name' => 'Mining', 'slug' => 'mining']);

        $this->assertEquals('Mining', $industry->name);
    }

    #[Test]
    public function requiringRulesRejectsAModelThatDeclaredNone(): void
    {
        Model::requireAssignmentRules();

        try {
            (new Industry())->fill(['name' => 'Mining']);
            $this->fail('a model with no declared rules was mass assigned');
        } catch (MassAssignmentException $exception) {
            $this->assertStringContainsString('Industry', $exception->getMessage());
        } finally {
            Model::allowUndeclaredAssignment();
        }
    }

    #[Test]
    public function requiringRulesLeavesDeclaredModelsAndDirectAssignmentAlone(): void
    {
        Model::requireAssignmentRules();

        try {
            // User declares both, so it is unaffected
            $user = new User();
            $user->fill(['username' => 'declared', 'id' => 999]);
            $this->assertEquals('declared', $user->username);
            $this->assertNull($user->id, 'guarded primary key was filled');

            // Direct assignment is not mass assignment and stays available
            $industry = new Industry();
            $industry->name = 'Mining';
            $this->assertEquals('Mining', $industry->name);

            // An empty payload has nothing to check
            $this->assertInstanceOf(Industry::class, (new Industry())->fill([]));
        } finally {
            Model::allowUndeclaredAssignment();
        }
    }

    #[Test]
    public function queryExceptionKeepsSqlOutOfItsMessage(): void
    {
        // getMessage() reaches logs, error pages and error trackers, and SQL
        // text maps out the schema for whoever reads them. The driver's own
        // wording may still name what it choked on — what we control is not
        // appending the whole statement on top of it.
        $sql = 'SELECT `password`, `role` FROM no_such_table_here WHERE `id` = ?';

        try {
            (new Raw($sql, [1]))->withConnection('mysql')->fetchAll();
            $this->fail('the bad query did not throw');
        } catch (QueryException $exception) {
            $this->assertStringNotContainsString('[SQL:', $exception->getMessage());
            $this->assertStringNotContainsString('password', $exception->getMessage());
            $this->assertStringNotContainsString('SELECT', $exception->getMessage());

            // Still available deliberately
            $this->assertEquals($sql, $exception->getSql());
            $this->assertEquals([1], $exception->getBinds());
        }
    }

    #[Test]
    public function queryExceptionDebugModeRestoresSqlInTheMessage(): void
    {
        QueryException::enableDebug();

        try {
            (new Raw('SELECT * FROM no_such_table_here'))->withConnection('mysql')->fetchAll();
            $this->fail('the bad query did not throw');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('[SQL:', $exception->getMessage());
            $this->assertStringContainsString('no_such_table_here', $exception->getMessage());
        } finally {
            QueryException::disableDebug();
        }

        $this->assertFalse(QueryException::isDebug());
    }

    #[Test]
    public function undefinedQueryProxyMethodThrowsInsteadOfMatchingEverything(): void
    {
        // A typo used to return a bare query with no conditions, so
        // Query::wheer('id', 5) silently matched the whole table.
        // Dispatched through the magic method rather than written literally:
        // static analysis rightly rejects `Query::wheer(...)`, and a typo the
        // analyser catches was never the one that reached production.
        $this->expectException(BadMethodCallException::class);

        Query::__callStatic('wheer', ['id', 5]);
    }

    #[Test]
    public function validQueryProxyMethodStillWorks(): void
    {
        $query = Query::from('user')->where('username', 'alice');

        $this->assertInstanceOf(ActiveQuery::class, $query);
        $this->assertStringContainsString('WHERE', $query->getSQL());
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
