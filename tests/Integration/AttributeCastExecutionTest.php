<?php

namespace Integration;

use InvalidArgumentException;
use Models\Setting;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\DB;
use Simsoft\DB\Model;
use Throwable;

/**
 * @property mixed $n
 * @property mixed $b
 * @property mixed $f
 * @property mixed $s
 * @property mixed $a
 * @property mixed $j
 * @property mixed $note
 */
class CastRow extends Model
{
    protected string $table = 'cast_row';
    protected array $fillable = ['n', 'b', 'f', 's', 'a', 'j', 'note'];
    protected array $casts = [
        'n' => 'int',
        'b' => 'bool',
        'f' => 'float',
        's' => 'string',
        'a' => 'array',
        'j' => 'json',
    ];
}

/**
 * @property mixed $n
 * @property mixed $t
 * @property mixed $note
 */
class PlainRow extends Model
{
    protected string $table = 'plain_row';
    protected array $fillable = ['n', 't', 'note'];
}

/**
 * Attribute casting against what the database actually stores.
 *
 * Every assertion here reads the column back with a raw query rather than
 * trusting the model that wrote it — a cast defect is invisible from inside
 * the model, because the model is the thing that is wrong.
 */
class AttributeCastExecutionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::raw('DROP TABLE IF EXISTS cast_row');
        DB::raw(
            'CREATE TABLE cast_row ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, n INT NULL DEFAULT 99, b TINYINT(1) NULL, '
            . 'f DOUBLE NULL, s VARCHAR(64) NULL, a TEXT NULL, j TEXT NULL, note VARCHAR(64) NULL'
            . ')'
        );

        DB::raw('DROP TABLE IF EXISTS plain_row');
        DB::raw(
            'CREATE TABLE plain_row ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, n INT NULL, t VARCHAR(64) NULL, note VARCHAR(64) NULL'
            . ')'
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            DB::raw('DROP TABLE IF EXISTS cast_row');
            DB::raw('DROP TABLE IF EXISTS plain_row');
        }

        parent::tearDown();
    }

    /**
     * The single row of a probe table, straight from the database.
     *
     * @param string $table
     * @return array<string, mixed>
     */
    private function row(string $table): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = DB::query("SELECT * FROM $table ORDER BY id LIMIT 1", [], 'mysql');
        return $rows[0] ?? [];
    }

    // ---- NULL reaches the database as NULL ----

    #[Test]
    public function anExplicitNullOnInsertBeatsTheColumnDefault(): void
    {
        $model = new CastRow();
        $model->note = 'ins';
        $model->n = null;
        $model->save();

        // A null was not marked dirty, so the column was left out of the INSERT
        // and MySQL applied DEFAULT 99 — the row held 99 where the caller had
        // written null, and nothing reported the substitution.
        $this->assertNull($this->row('cast_row')['n']);
    }

    #[Test]
    public function aPopulatedCastColumnCanBeClearedToNull(): void
    {
        DB::raw("INSERT INTO cast_row (n, s, j, note) VALUES (42, 'hello', '{\"k\":1}', 'upd')");

        $model = CastRow::find()->first();
        $this->assertInstanceOf(CastRow::class, $model);

        $model->n = null;
        $model->s = null;
        $model->j = null;
        $model->save();

        // Cast through, these were written as 0, '' and the string 'null'.
        $row = $this->row('cast_row');
        $this->assertNull($row['n']);
        $this->assertNull($row['s']);
        $this->assertNull($row['j']);
    }

    #[Test]
    public function aNullColumnReadsBackAsNullNotAsZero(): void
    {
        DB::raw("INSERT INTO cast_row (n, b, s, j, note) VALUES (NULL, NULL, NULL, NULL, 'n')");

        $model = CastRow::find()->first();
        $this->assertInstanceOf(CastRow::class, $model);

        $this->assertNull($this->row('cast_row')['n']);
        $this->assertNull($model->n);
        $this->assertNull($model->b);
        $this->assertNull($model->s);
        $this->assertNull($model->j);
    }

    #[Test]
    public function readingACastColumnDoesNotChangeWhatTheModelReports(): void
    {
        DB::raw("INSERT INTO cast_row (n, b, note) VALUES (NULL, NULL, 'n')");

        $model = CastRow::find()->first();
        $this->assertInstanceOf(CastRow::class, $model);

        $before = $model->toArray();
        $this->assertNull($model->n);
        $this->assertNull($model->b);

        // Reading assigned, so a NULL column became 0 and toArray() then
        // reported 0 — a value the caller could not tell from a real zero.
        $this->assertSame($before, $model->toArray());
        $this->assertFalse($model->isDirty());
    }

    // ---- dirty tracking decides what is written ----

    #[Test]
    public function settingANullColumnToZeroWritesZero(): void
    {
        DB::raw("INSERT INTO plain_row (n, note) VALUES (NULL, 'x')");

        $model = PlainRow::find()->first();
        $this->assertInstanceOf(PlainRow::class, $model);

        $model->n = 0;
        $this->assertTrue($model->isDirty('n'));
        $model->save();

        // NULL == 0 in PHP, so this was recorded as no change and the UPDATE
        // never carried the column.
        $this->assertSame(0, (int)$this->row('plain_row')['n']);
    }

    #[Test]
    public function clearingATextColumnToAnEmptyStringWritesAnEmptyString(): void
    {
        DB::raw("INSERT INTO plain_row (n, t, note) VALUES (1, NULL, 'x')");

        $model = PlainRow::find()->first();
        $this->assertInstanceOf(PlainRow::class, $model);

        $model->t = '';
        $model->save();

        $this->assertSame('', $this->row('plain_row')['t']);
    }

    #[Test]
    public function clearingAZeroColumnToNullWritesNull(): void
    {
        DB::raw("INSERT INTO plain_row (n, note) VALUES (0, 'x')");

        $model = PlainRow::find()->first();
        $this->assertInstanceOf(PlainRow::class, $model);

        $model->n = null;
        $model->save();

        $this->assertNull($this->row('plain_row')['n']);
    }

    #[Test]
    public function resavingAnUntouchedRowChangesNothing(): void
    {
        DB::raw("INSERT INTO plain_row (n, t, note) VALUES (5, 'hello', 'x')");
        $before = $this->row('plain_row');

        $model = PlainRow::find()->first();
        $this->assertInstanceOf(PlainRow::class, $model);

        // A driver hands back '5' where the caller assigns int 5. Comparing
        // strictly would rewrite every column over that type difference.
        $model->n = 5;
        $model->t = 'hello';
        $this->assertFalse($model->isDirty());
        $model->save();

        $this->assertSame($before, $this->row('plain_row'));
    }

    // ---- array and json round-trip through a real column ----

    #[Test]
    public function anArrayCastRoundTripsThroughTheDatabase(): void
    {
        $model = new CastRow();
        $model->note = 'arr';
        $model->a = ['one', 'two'];
        $model->save();

        // The live PHP array was bound by the mysqli driver as the literal
        // string "Array" — five characters where a list belonged.
        $this->assertSame('["one","two"]', $this->row('cast_row')['a']);

        $loaded = CastRow::find()->first();
        $this->assertInstanceOf(CastRow::class, $loaded);
        $this->assertSame(['one', 'two'], $loaded->a);
    }

    #[Test]
    public function aJsonCastRoundTripsThroughTheDatabase(): void
    {
        $model = new CastRow();
        $model->note = 'j';
        $model->j = ['a' => 1, 'b' => [2, 3]];
        $model->save();

        $this->assertSame('{"a":1,"b":[2,3]}', $this->row('cast_row')['j']);

        $loaded = CastRow::find()->first();
        $this->assertInstanceOf(CastRow::class, $loaded);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $loaded->j);
    }

    #[Test]
    public function aValueThatCannotBeEncodedNeverReachesTheDatabase(): void
    {
        $model = new CastRow();
        $model->note = 'bad';
        $model->save();

        // json_encode() returns false for invalid UTF-8, and that false was
        // stored as-is and written to the column as an empty string.
        $rejected = $this->assign($model, 'j', ["\xB1\x31"]);
        $this->assertInstanceOf(InvalidArgumentException::class, $rejected);

        $model->save();
        $this->assertNull($this->row('cast_row')['j']);
    }

    /**
     * Assign an attribute, returning whatever the assignment threw.
     *
     * The throw happens inside __set(), which static analysis does not follow
     * through a magic property write.
     *
     * @param Model $model The model to assign on.
     * @param string $attribute The attribute name.
     * @param mixed $value The value to assign.
     * @return Throwable|null
     */
    private function assign(Model $model, string $attribute, mixed $value): ?Throwable
    {
        try {
            $model->{$attribute} = $value;
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    // ---- the fixture's own JSON column ----

    #[Test]
    public function theSettingFixtureDecodesItsJsonColumn(): void
    {
        /** @var array<int, array<string, mixed>> $truth */
        $truth = DB::query('SELECT metadata FROM setting WHERE id = 1', [], 'mysql');
        /** @var string $raw */
        $raw = $truth[0]['metadata'];

        $setting = Setting::findByPk(1);
        $this->assertInstanceOf(Setting::class, $setting);

        $this->assertSame(json_decode($raw, true), $setting->metadata);
        $this->assertSame(json_decode($raw, true), $setting->toArray()['metadata']);

        // getAttributes() stays raw so callers can still see what is stored.
        $this->assertSame($raw, $setting->getAttributes()['metadata']);
    }

    #[Test]
    public function writingTheSettingJsonColumnStoresValidJson(): void
    {
        $setting = new Setting();
        $setting->fill([
            'group' => 'probe',
            'key' => 'cast_test',
            'value' => 'v',
            'metadata' => ['priority' => 9, 'tags' => ['probe']],
        ]);
        $setting->save();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = DB::query("SELECT metadata FROM setting WHERE `group` = 'probe'", [], 'mysql');
        /** @var string $stored */
        $stored = $rows[0]['metadata'];

        // The fixture column is JSON; MySQL rejects anything that is not, and
        // normalises key order on the way in, so compare the document.
        $this->assertEqualsCanonicalizing(
            ['priority' => 9, 'tags' => ['probe']],
            json_decode($stored, true)
        );

        DB::raw("DELETE FROM setting WHERE `group` = 'probe'");
    }
}
