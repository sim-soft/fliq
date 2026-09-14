<?php

namespace Simsoft\DB\Traits;

use Traversable;

/**
 * Trait Error
 *
 */
trait Error
{
    /** @var array<int, string> The errors storage */
    protected array $errors = [];

    /**
     * Add an error message.
     *
     * @param string $message The error message.
     * @return void
     */
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Add an array of error messages.
     *
     * Keys are discarded and the messages appended, which is what the storage
     * declares and what addError() does one at a time. Spreading kept string
     * keys, so a batch keyed by field name — the shape a caller building
     * ['email' => 'is required'] would naturally reach for — overwrote any
     * earlier message sharing a key instead of adding to it, and two calls
     * about the same field left one message where there should have been two.
     * The errors array then held string keys its own type forbids.
     *
     * @param array<array-key, string> $messages Array of error messages.
     * @return void
     */
    public function addErrors(array $messages = []): void
    {
        foreach ($messages as $message) {
            $this->errors[] = $message;
        }
    }

    /**
     * Import errors from a Validator Errors object.
     *
     * Flattens the grouped error messages and appends them to this model's errors.
     * Accepts any iterable where each value is an array of error message strings.
     *
     * Appended rather than spread, for the reason given on addErrors(). The
     * bundled validator appends within each field, so its inner arrays are
     * integer-keyed and spreading them happened to renumber; any other source
     * keying its messages by rule name would have lost every field after the
     * first to share one.
     *
     * @param Traversable<string, array<array-key, string>> $errors The validator errors object.
     * @return void
     */
    public function addValidationErrors(Traversable $errors): void
    {
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                $this->errors[] = $message;
            }
        }
    }

    /**
     * Discard every recorded error.
     *
     * Errors accumulate and nothing removed them, so one transient failure was
     * permanent: a driver whose first connection attempt failed and whose
     * second succeeded still answered hasError() true and listed the stale
     * message, for a connection that was working. Anything that can succeed
     * after failing has to be able to say so.
     *
     * @return void
     */
    public function clearErrors(): void
    {
        $this->errors = [];
    }

    /**
     * Determine there is no errors.
     *
     * @return bool True if no errors.
     */
    public function noError(): bool
    {
        return empty($this->errors);
    }

    /**
     * Determine there are errors.
     *
     * @return bool True if there are errors.
     */
    public function hasError(): bool
    {
        return !$this->noError();
    }

    /**
     * Get all errors
     *
     * @return array<int, string> Array of error messages.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
