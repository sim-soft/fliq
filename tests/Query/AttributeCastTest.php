<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Model;

/**
 * @property mixed $n
 * @property mixed $b
 * @property mixed $f
 * @property mixed $s
 * @property mixed $a
 * @property mixed $j
 * @property mixed $plain
 */
class CastModel extends Model
{
    protected string $table = 'cast_model';
    protected array $fillable = ['n', 'b', 'f', 's', 'a', 'j', 'plain'];
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
 */
class BadCastModel extends Model
{
    protected string $table = 'bad_cast';
    protected array $fillable = ['n'];
    protected array $casts = ['n' => 'interger'];
}

/**
 * @property mixed $n
 * @property mixed $t
 */
class PlainModel extends Model
{
    protected string $table = 'plain_model';
    protected array $fillable = ['n', 't'];
}

/**
 * Attribute casting and the dirty tracking that decides what reaches the
 * database.
 */
class AttributeCastTest extends TestCase
{
    /**
     * An existing row, as the database hands it back.
     *
     * hydrate() is the path every fetch goes through: raw column values, no
     * casts applied on the way in, nothing dirty.
     *
     * @param array<string, mixed> $attributes
     * @return CastModel
     */
    private function stored(array $attributes): CastModel
    {
        return CastModel::hydrate($attributes);
    }

    /**
     * An existing row of a model with no casts.
     *
     * @param array<string, mixed> $attributes
     * @return PlainModel
     */
    private function plain(array $attributes): PlainModel
    {
        return PlainModel::hydrate($attributes);
    }

    // ---- a cast never applies to NULL ----

    #[Test]
    public function assigningNullToACastAttributeStoresNull(): void
    {
        $model = new CastModel();
        $model->n = null;
        $model->b = null;
        $model->s = null;
        $model->a = null;
        $model->j = null;

        // Cast through, these became 0, false, '' and '[]' — a nullable column
        // could never be cleared, and the write reached the database as a value.
        $this->assertSame(
            ['n' => null, 'b' => null, 's' => null, 'a' => null, 'j' => null],
            $model->getAttributes()
        );
    }

    #[Test]
    public function aNullColumnReadsBackAsNull(): void
    {
        $model = $this->stored(['n' => null, 'b' => null, 's' => null, 'j' => null]);

        $this->assertNull($model->n);
        $this->assertNull($model->b);
        $this->assertNull($model->s);
        $this->assertNull($model->j);
    }

    #[Test]
    public function readingACastAttributeDoesNotRewriteTheModel(): void
    {
        $model = $this->stored(['n' => null, 'b' => null, 'j' => null]);

        $this->assertNull($model->n);
        $this->assertNull($model->b);
        $this->assertNull($model->j);

        // Reading used to assign, so a NULL column became 0 and then reported 0
        // from every serializer, with no way left to tell it from a real zero.
        $this->assertSame(['n' => null, 'b' => null, 'j' => null], $model->getAttributes());
        $this->assertFalse($model->isDirty());
    }

    #[Test]
    public function readingAnAttributeThatWasNeverSetDoesNotCreateIt(): void
    {
        $model = new CastModel();

        $this->assertNull($model->n);
        $this->assertSame([], $model->getAttributes());
        $this->assertFalse(isset($model->n));
    }

    // ---- unknown cast names ----

    #[Test]
    public function anUnknownCastTypeIsRejectedOnWrite(): void
    {
        $model = new BadCastModel();

        // Returning the value untouched meant the cast the model asked for was
        // never applied and nothing said so.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown cast type 'interger' for attribute 'n'");
        $model->n = '42abc';
    }

    #[Test]
    public function anUnknownCastTypeIsRejectedOnRead(): void
    {
        $model = BadCastModel::hydrate(['n' => '42abc']);

        $this->expectException(InvalidArgumentException::class);
        $this->assertNull($model->n);
    }

    // ---- scalar casts ----

    #[Test]
    public function scalarCastsApplyOnRead(): void
    {
        $model = $this->stored(['n' => '7', 'b' => 1, 'f' => '1.5', 's' => 42]);

        $this->assertSame(7, $model->n);
        $this->assertTrue($model->b);
        $this->assertSame(1.5, $model->f);
        $this->assertSame('42', $model->s);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function booleanProvider(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            "string '1'" => ['1', true],
            "string '0'" => ['0', false],
            'pg t' => ['t', true],
            'pg f' => ['f', false],
            'pg true' => ['true', true],
            'pg false' => ['false', false],
            'uppercase FALSE' => ['FALSE', false],
            'empty string' => ['', false],
            'no' => ['no', false],
            'off' => ['off', false],
            'yes' => ['yes', true],
        ];
    }

    #[Test]
    #[DataProvider('booleanProvider')]
    public function theBooleanCastNormalisesEveryEngineRepresentation(mixed $value, bool $expected): void
    {
        $model = new CastModel();
        $model->b = $value;

        $this->assertSame($expected, $model->b);
    }

    // ---- array and json ----

    #[Test]
    public function anArrayCastRoundTrips(): void
    {
        $model = new CastModel();
        $model->a = ['one', 'two'];

        // (array) left a live PHP array in the attributes, which the mysqli
        // driver bound as the literal string "Array".
        $this->assertSame('["one","two"]', $model->getAttributes()['a']);
        $this->assertSame(['one', 'two'], $model->a);
    }

    #[Test]
    public function anArrayCastDecodesWhatTheDatabaseStored(): void
    {
        // (array)'["one","two"]' produced ['["one","two"]'] — the list came back
        // nested inside a string.
        $this->assertSame(['one', 'two'], $this->stored(['a' => '["one","two"]'])->a);
    }

    #[Test]
    public function anArrayCastAlwaysAnswersWithAnArray(): void
    {
        $this->assertSame(['hello'], $this->stored(['a' => 'hello'])->a);
        $this->assertSame([5], $this->stored(['a' => 5])->a);
    }

    #[Test]
    public function aJsonCastRoundTrips(): void
    {
        $model = new CastModel();
        $model->j = ['a' => 1, 'b' => [2, 3]];

        $this->assertSame('{"a":1,"b":[2,3]}', $model->getAttributes()['j']);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $model->j);
    }

    #[Test]
    public function aJsonCastAcceptsAnAlreadyEncodedString(): void
    {
        $model = new CastModel();
        $model->j = '{"already":"json"}';

        $this->assertSame('{"already":"json"}', $model->getAttributes()['j']);
        $this->assertSame(['already' => 'json'], $model->j);
    }

    #[Test]
    public function malformedJsonIsHandedBackAsStoredRatherThanAsAnEmptyArray(): void
    {
        // Reading back [] gave the caller a value indistinguishable from a
        // column that legitimately holds an empty list.
        $this->assertSame('{not valid json', $this->stored(['j' => '{not valid json'])->j);
    }

    #[Test]
    public function aValueThatCannotBeEncodedIsRejected(): void
    {
        $model = new CastModel();

        // json_encode() returns false here; that false was stored and reached
        // the database as an empty string, destroying the column.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot encode value as JSON');
        $model->j = NAN;
    }

    #[Test]
    public function invalidUtf8InsideAnArrayIsRejected(): void
    {
        $model = new CastModel();

        $this->expectException(InvalidArgumentException::class);
        $model->j = ["\xB1\x31"];
    }

    // ---- serialization ----

    #[Test]
    public function toArrayPresentsCastValues(): void
    {
        $model = $this->stored([
            'n' => '7', 'b' => 1, 'f' => '1.5', 's' => 42,
            'a' => '["x"]', 'j' => '{"k":"v"}', 'plain' => 'p',
        ]);

        $this->assertSame([
            'n' => 7, 'b' => true, 'f' => 1.5, 's' => '42',
            'a' => ['x'], 'j' => ['k' => 'v'], 'plain' => 'p',
        ], $model->toArray());
    }

    #[Test]
    public function toArrayReportsTheSameValuesBeforeAndAfterReadingAProperty(): void
    {
        $model = $this->stored(['n' => null, 'j' => null]);
        $before = $model->toArray();

        $this->assertNull($model->n);
        $this->assertNull($model->j);

        $this->assertSame($before, $model->toArray());
    }

    #[Test]
    public function getAttributesReportsWhatIsStoredNotWhatIsPresented(): void
    {
        $model = $this->stored(['j' => '{"k":"v"}']);

        $this->assertSame('{"k":"v"}', $model->getAttributes()['j']);
        $this->assertSame(['k' => 'v'], $model->toArray()['j']);
    }

    #[Test]
    public function aFieldFilterStillAppliesCasts(): void
    {
        $model = $this->stored(['n' => '7', 'j' => '{"k":"v"}', 'plain' => 'p']);

        $this->assertSame(['n' => 7], $model->toArray(['n']));
    }

    #[Test]
    public function toJsonSerializesTheDecodedDocument(): void
    {
        $model = $this->stored(['j' => '{"k":"v"}']);

        $this->assertSame('{"j":{"k":"v"}}', $model->toJson());
    }

    // ---- dirty tracking ----

    #[Test]
    public function assigningNullOnANewRecordMarksItDirty(): void
    {
        $model = new PlainModel();
        $model->n = null;

        // Skipped as "not a value", the column was left out of the INSERT and
        // the table DEFAULT won over the caller's explicit NULL.
        $this->assertTrue($model->isDirty('n'));
        $this->assertSame(['n' => null], $model->getAttributes());
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function looseEqualityProvider(): array
    {
        return [
            'null to zero' => [null, 0],
            'zero to null' => [0, null],
            'null to empty string' => [null, ''],
            'empty string to null' => ['', null],
            'null to false' => [null, false],
            'false to null' => [false, null],
            'null to empty array' => [null, []],
        ];
    }

    #[Test]
    #[DataProvider('looseEqualityProvider')]
    public function aChangeBetweenLooselyEqualValuesIsStillAChange(mixed $from, mixed $to): void
    {
        $model = $this->plain(['n' => $from]);
        $model->n = $to;

        // Compared with ==, all of these read as "no change", so the write was
        // dropped from the UPDATE and never reached the database.
        $this->assertTrue($model->isDirty('n'));
        $this->assertSame($to, $model->getAttributes()['n']);
    }

    #[Test]
    public function reassigningTheSameValueLeavesTheModelClean(): void
    {
        $model = $this->plain(['n' => 5, 't' => 'hello']);
        $model->n = 5;
        $model->t = 'hello';

        $this->assertFalse($model->isDirty());
    }

    #[Test]
    public function aDriverTypeDifferenceIsNotAChange(): void
    {
        // A driver hands back '5' where the caller assigns int 5. Re-saving an
        // untouched row must not rewrite every column over that.
        $model = $this->plain(['n' => '5']);
        $model->n = 5;

        $this->assertFalse($model->isDirty('n'));
    }

    #[Test]
    public function aRealChangeBetweenSameTypedValuesIsDetected(): void
    {
        $model = $this->plain(['n' => 5]);
        $model->n = 9;

        $this->assertTrue($model->isDirty('n'));
    }

    #[Test]
    public function anEmptyStringIsDistinctFromAZeroString(): void
    {
        $model = $this->plain(['t' => '']);
        $model->t = '0';

        $this->assertTrue($model->isDirty('t'));
    }

    #[Test]
    public function aCastThatNormalisesTheValueDoesNotMarkTheModelDirty(): void
    {
        // The database hands back '7'; the int cast stores 7. Assigning 7 again
        // must not look like a change just because the cast ran.
        $model = $this->stored(['n' => 7]);
        $model->n = '7';

        $this->assertFalse($model->isDirty('n'));
    }

    #[Test]
    public function aCastAttributeClearedToNullIsDirty(): void
    {
        $model = $this->stored(['n' => 42, 'j' => '{"k":1}']);
        $model->n = null;
        $model->j = null;

        $this->assertTrue($model->isDirty('n'));
        $this->assertTrue($model->isDirty('j'));
        $this->assertNull($model->getAttributes()['n']);
        $this->assertNull($model->getAttributes()['j']);
    }
}
