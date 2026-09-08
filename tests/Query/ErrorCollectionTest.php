<?php

namespace Query;

use ArrayIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Model;
use Simsoft\Validator\Support\Errors;

/**
 * Errors accumulate; none of them may overwrite another.
 *
 * addErrors() and addValidationErrors() merged with the spread operator, which
 * preserves string keys. A batch keyed by field name — ['email' => 'is
 * required'], the shape a caller naturally reaches for — therefore overwrote any
 * earlier message sharing that key rather than adding to it, so two calls about
 * one field left one message where there should have been two. The errors array
 * also ended up holding string keys its own array<int, string> type forbids.
 * Both now append, which is what addError() has always done one at a time.
 */
class ErrorCollectionTest extends TestCase
{
    private function model(): Model
    {
        return new class extends Model {
            protected string $table = 'user';
        };
    }

    #[Test]
    public function messagesSharingAFieldKeyAcrossCallsAreAllKept(): void
    {
        $model = $this->model();
        $model->addErrors(['email' => 'Email is required']);
        $model->addErrors(['email' => 'Invalid email format']);

        $this->assertSame(['Email is required', 'Invalid email format'], $model->getErrors());
    }

    #[Test]
    public function aBatchKeyedByFieldNameKeepsEveryMessage(): void
    {
        $model = $this->model();
        $model->addError('existing');
        $model->addErrors(['email' => 'Email is required', 'name' => 'Name is required']);

        $this->assertSame(
            ['existing', 'Email is required', 'Name is required'],
            $model->getErrors()
        );
    }

    #[Test]
    public function theStoredKeysAreAlwaysIntegers(): void
    {
        $model = $this->model();
        $model->addErrors(['email' => 'oops', 'name' => 'bad']);

        $keys = array_keys($model->getErrors());
        $this->assertSame([0, 1], $keys, 'the storage declares array<int, string>');
    }

    #[Test]
    public function aKeyedBatchDoesNotDisplaceAPositionalError(): void
    {
        $model = $this->model();
        $model->addError('first');
        $model->addErrors([0 => 'batch']);

        $this->assertSame(['first', 'batch'], $model->getErrors());
    }

    #[Test]
    public function numericStringKeysDoNotCollideWithTheAppendCounter(): void
    {
        $model = $this->model();
        $model->addError('first');
        $model->addError('second');
        $model->addErrors(['1' => 'batch']);

        $this->assertCount(3, $model->getErrors());
        $this->assertSame(['first', 'second', 'batch'], $model->getErrors());
    }

    #[Test]
    public function aPlainListIsAppendedInOrder(): void
    {
        $model = $this->model();
        $model->addErrors(['first', 'second']);
        $model->addErrors(['third']);

        $this->assertSame(['first', 'second', 'third'], $model->getErrors());
    }

    #[Test]
    public function addErrorAndAddErrorsAgreeOnTheResult(): void
    {
        $one = $this->model();
        $one->addError('p');
        $one->addError('q');

        $many = $this->model();
        $many->addErrors(['p', 'q']);

        $this->assertSame($one->getErrors(), $many->getErrors());
    }

    #[Test]
    public function anEmptyBatchChangesNothing(): void
    {
        $model = $this->model();
        $model->addError('kept');
        $model->addErrors([]);

        $this->assertSame(['kept'], $model->getErrors());
    }

    #[Test]
    public function addErrorsDefaultsToAddingNothing(): void
    {
        $model = $this->model();
        $model->addErrors();

        $this->assertSame([], $model->getErrors());
        $this->assertFalse($model->hasError());
    }

    #[Test]
    public function anEmptyStringIsStillAMessage(): void
    {
        // Only the batch being empty means "nothing to add"; a message that
        // happens to be falsy is a message the caller asked to record.
        $model = $this->model();
        $model->addErrors(['', '0']);

        $this->assertSame(['', '0'], $model->getErrors());
        $this->assertTrue($model->hasError());
    }

    #[Test]
    public function validationErrorsAreFlattenedInFieldOrder(): void
    {
        $model = $this->model();
        $model->addValidationErrors(new ArrayIterator([
            'name' => ['Name is required', 'Name too short'],
            'email' => ['Invalid email'],
        ]));

        $this->assertSame(
            ['Name is required', 'Name too short', 'Invalid email'],
            $model->getErrors()
        );
    }

    #[Test]
    public function validationMessagesKeyedByRuleNameAreAllKept(): void
    {
        // The bundled validator appends within each field, so its inner arrays
        // are integer-keyed. A source that keys them by rule name instead used
        // to lose every field after the first to share a rule.
        $model = $this->model();
        $model->addValidationErrors(new ArrayIterator([
            'email' => ['required' => 'Email is required'],
            'name' => ['required' => 'Name is required'],
        ]));

        $this->assertSame(['Email is required', 'Name is required'], $model->getErrors());
    }

    #[Test]
    public function theBundledValidatorErrorsObjectRoundTrips(): void
    {
        $errors = new Errors();
        $errors->add('name', 'Name is required');
        $errors->add('email', 'Email is required');
        $errors->add('email', 'Invalid email');

        $model = $this->model();
        $model->addValidationErrors($errors);

        $this->assertSame(
            ['Name is required', 'Email is required', 'Invalid email'],
            $model->getErrors()
        );
    }

    #[Test]
    public function validationErrorsAppendToWhatIsAlreadyThere(): void
    {
        $model = $this->model();
        $model->addError('earlier');
        $model->addValidationErrors(new ArrayIterator(['email' => ['Invalid email']]));

        $this->assertSame(['earlier', 'Invalid email'], $model->getErrors());
    }

    #[Test]
    public function anEmptyValidatorAddsNothing(): void
    {
        $model = $this->model();
        $model->addValidationErrors(new ArrayIterator([]));

        $this->assertFalse($model->hasError());
    }

    #[Test]
    public function aFieldWithNoMessagesAddsNothing(): void
    {
        $model = $this->model();
        $model->addValidationErrors(new ArrayIterator(['email' => []]));

        $this->assertSame([], $model->getErrors());
        $this->assertTrue($model->noError());
    }

    #[Test]
    public function clearingLeavesTheModelAbleToCollectAgain(): void
    {
        $model = $this->model();
        $model->addErrors(['e1', 'e2']);
        $this->assertTrue($model->hasError());

        $model->clearErrors();
        $this->assertSame([], $model->getErrors());
        $this->assertTrue($model->noError());

        $model->addErrors(['e3']);
        $this->assertSame(['e3'], $model->getErrors(), 'and it renumbers from zero');
        $this->assertSame([0], array_keys($model->getErrors()));
    }

    #[Test]
    public function hasErrorAndNoErrorAlwaysDisagree(): void
    {
        $model = $this->model();
        $this->assertNotSame($model->hasError(), $model->noError());

        $model->addErrors(['something']);
        $this->assertNotSame($model->hasError(), $model->noError());
    }
}
