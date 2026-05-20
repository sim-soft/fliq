<?php

namespace Simsoft\DB\Traits;

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
     * @param array<int, string> $messages Array of error messages.
     * @return void
     */
    public function addErrors(array $messages = []): void
    {
        if ($messages) {
            $this->errors = [...$this->errors, ...$messages];
        }
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
