<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Generator\ObserverGenerator;

/**
 * Unit tests for ObserverGenerator code generation logic.
 */
class ObserverGeneratorTest extends TestCase
{
    // ------------------------------------------------------------------
    // BASIC GENERATION
    // ------------------------------------------------------------------

    #[Test]
    public function generatesBasicObserver(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('<?php', $code);
        $this->assertStringContainsString('namespace App\\Observers;', $code);
        $this->assertStringContainsString('use App\\Models\\User;', $code);
        $this->assertStringContainsString('class UserObserver', $code);
    }

    #[Test]
    public function containsAllEightDefaultEvents(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->skipValidation()
            ->preview();

        $events = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted'];
        foreach ($events as $event) {
            $this->assertStringContainsString("public function $event(", $code);
        }
    }

    #[Test]
    public function methodsReceiveModelParameter(): void
    {
        $code = ObserverGenerator::forModel('Order')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('public function creating(Order $order): ?bool', $code);
        $this->assertStringContainsString('public function deleted(Order $order): void', $code);
    }

    /**
     * The four cancellable events are typed ?bool; the other four are void.
     *
     * This used to assert all eight were void, which is what made the stub
     * unusable: the docblock told the reader to return false from a method
     * that PHP will not let return anything.
     */
    #[Test]
    public function beforeEventsReturnNullableBoolAndAfterEventsReturnVoid(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->skipValidation()
            ->preview();

        $this->assertStringNotContainsString('bool|void', $code);
        $this->assertEquals(4, substr_count($code, '): ?bool'));
        $this->assertEquals(4, substr_count($code, '): void'));

        // A ?bool method that falls off its end is a TypeError, so the
        // untouched stub has to return something.
        $this->assertEquals(4, substr_count($code, 'return null;'));
    }

    // ------------------------------------------------------------------
    // CUSTOM OPTIONS
    // ------------------------------------------------------------------

    #[Test]
    public function respectsCustomNamespace(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->namespace('App\\Listeners')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('namespace App\\Listeners;', $code);
    }

    #[Test]
    public function respectsCustomModelNamespace(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->modelNamespace('Domain\\Models')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('use Domain\\Models\\User;', $code);
    }

    #[Test]
    public function respectsSpecificEvents(): void
    {
        $code = ObserverGenerator::forModel('Payment')
            ->events(['creating', 'deleting'])
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('public function creating(Payment $payment): ?bool', $code);
        $this->assertStringContainsString('public function deleting(Payment $payment): ?bool', $code);
        $this->assertStringNotContainsString('public function created(', $code);
        $this->assertStringNotContainsString('public function updated(', $code);
        $this->assertStringNotContainsString('public function saved(', $code);
    }

    #[Test]
    public function singleEventGeneratesOneMethod(): void
    {
        $code = ObserverGenerator::forModel('Audit')
            ->events(['created'])
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('public function created(Audit $audit): void', $code);
        $this->assertEquals(1, substr_count($code, 'public function '));
    }

    // ------------------------------------------------------------------
    // PHPDOC & CLASS STRUCTURE
    // ------------------------------------------------------------------

    #[Test]
    public function classDocblockContainsRegistrationHint(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('User::observe(new UserObserver())', $code);
    }

    #[Test]
    public function classDocblockContainsModelName(): void
    {
        $code = ObserverGenerator::forModel('Order')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('Observes lifecycle events on the Order model', $code);
    }

    #[Test]
    public function beforeEventsHaveCancelReturnDoc(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->skipValidation()
            ->preview();

        // "before" events should mention cancellation in their doc, and say
        // what the stub's own return value means.
        $this->assertStringContainsString(
            'Return false to cancel the operation; null to continue.',
            $code
        );
    }

    #[Test]
    public function afterEventsDoNotHaveCancelDoc(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->events(['created', 'updated', 'saved', 'deleted'])
            ->skipValidation()
            ->preview();

        // "after" events should NOT mention cancellation
        $this->assertStringNotContainsString('Return false to cancel', $code);
    }

    // ------------------------------------------------------------------
    // PARAMETER NAMING
    // ------------------------------------------------------------------

    #[Test]
    public function parameterNameIsLowerCamelCase(): void
    {
        $code = ObserverGenerator::forModel('UserProfile')
            ->events(['creating'])
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('UserProfile $userProfile', $code);
    }

    #[Test]
    public function simpleModelNameParam(): void
    {
        $code = ObserverGenerator::forModel('Post')
            ->events(['creating'])
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('Post $post', $code);
    }

    // ------------------------------------------------------------------
    // VALIDATION
    // ------------------------------------------------------------------

    #[Test]
    public function skipValidationAllowsGeneration(): void
    {
        $code = ObserverGenerator::forModel('NonExistentModel')
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('class NonExistentModelObserver', $code);
    }

    #[Test]
    public function generateThrowsWhenModelNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        ObserverGenerator::forModel('CompletelyFakeModel999')
            ->namespace('App\\Observers')
            ->modelNamespace('App\\FakeNamespace')
            ->outputDir(sys_get_temp_dir())
            ->generate();
    }

    // ------------------------------------------------------------------
    // EVENT DESCRIPTIONS
    // ------------------------------------------------------------------

    #[Test]
    public function creatingEventHasCorrectDescription(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->events(['creating'])
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('model is being created (before INSERT)', $code);
    }

    #[Test]
    public function deletedEventHasCorrectDescription(): void
    {
        $code = ObserverGenerator::forModel('User')
            ->events(['deleted'])
            ->skipValidation()
            ->preview();

        $this->assertStringContainsString('model was deleted (after DELETE)', $code);
    }
}
