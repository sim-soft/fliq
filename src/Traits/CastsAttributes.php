<?php

namespace Simsoft\DB\Traits;

use InvalidArgumentException;

/**
 * CastsAttributes trait.
 *
 * Applies a model's `$casts` on the way into and out of `$attributes`, and
 * decides whether an assignment is a real change.
 *
 * Two rules govern the whole trait:
 *
 * - A cast describes the column's type, not whether it has a value. `NULL`
 *   passes through untouched in both directions, so a nullable column reads
 *   back as `null` and can be cleared by assigning `null`.
 * - Reading is a read. Presenting a value through its cast never alters what
 *   the model holds, so `getAttributes()` keeps reporting the raw stored
 *   values.
 *
 * @property array<string, string> $casts
 * @property array<string, mixed> $attributes
 */
trait CastsAttributes
{
    /** @var array<int, string> The cast names this trait understands. */
    private const SUPPORTED_CASTS = [
        'int', 'integer', 'bool', 'boolean', 'float', 'double', 'real',
        'string', 'binary', 'array', 'json',
    ];

    /**
     * Determine whether assigning a value actually changes an attribute.
     *
     * @param string $name Attribute's name.
     * @param mixed $value The value about to be stored, already cast.
     * @return bool
     */
    private function isChanged(string $name, mixed $value): bool
    {
        if (!array_key_exists($name, $this->attributes)) {
            return true;
        }

        $current = $this->attributes[$name];

        // Compared with ==, NULL, 0, false and '' were all "the same value", so
        // clearing a populated column to NULL, setting a NULL column to 0, or
        // emptying a string was recorded as no change and dropped from the
        // UPDATE — the caller's write never reached the database and nothing
        // reported that. NULL is therefore only unchanged against NULL.
        if ($current === null || $value === null) {
            return $current !== $value;
        }

        // Everything else stays loose on purpose: a driver hands back '5' where
        // the caller assigns int 5, and re-saving an untouched row must not
        // rewrite every column because of a type difference the database will
        // never see. Same-type values still compare exactly.
        if (gettype($current) === gettype($value)) {
            return $current !== $value;
        }

        return !is_scalar($current) || !is_scalar($value) || (string)$current !== (string)$value;
    }

    /**
     * Apply the declared cast for an attribute, if any.
     *
     * @param string $name Attribute's name.
     * @param mixed $value The incoming value.
     * @return mixed The value as it should be stored in $attributes.
     * @throws InvalidArgumentException If the declared cast is not supported.
     */
    private function castValue(string $name, mixed $value): mixed
    {
        if (!array_key_exists($name, $this->casts)) {
            return $value;
        }

        // Casting through meant `$model->score = null` stored 0 and
        // `->name = null` stored '', so a nullable column could never be cleared
        // through a cast attribute — the write reached the database as 0.
        if ($value === null) {
            return null;
        }

        return match ($this->casts[$name]) {
            'int', 'integer' => (int)$value,
            'bool', 'boolean' => $this->castToBoolean($value),
            'float', 'double', 'real' => (float)$value,
            'string', 'binary' => (string)$value,
            'array', 'json' => $this->castToJson($value),
            // Falling through to an arm that stored the value untouched meant a
            // typo such as 'interger' left the cast the model declared silently
            // unapplied.
            default => throw new InvalidArgumentException(
                "Unknown cast type '{$this->casts[$name]}' for attribute '$name' on " . static::class
                . '. Supported: ' . implode(', ', self::SUPPORTED_CASTS) . '.'
            ),
        };
    }

    /**
     * Present a stored value through its declared cast.
     *
     * @param string $name Attribute's name.
     * @param mixed $value The stored value.
     * @return mixed
     */
    private function readCast(string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // 'array' and 'json' are stored encoded so they bind as an ordinary
        // parameter, and are decoded on the way out. 'array' kept a live PHP
        // array in $attributes, which the mysqli driver bound as the literal
        // string "Array" — five characters where a list belonged.
        $decoded = match ($this->casts[$name]) {
            'array', 'json' => $this->decodeStructured($value),
            default => $this->castValue($name, $value),
        };

        // An 'array' attribute answers with an array whatever the column holds,
        // so callers can foreach it without checking. 'json' stays faithful to
        // the document, which may legitimately be a scalar.
        return $this->casts[$name] === 'array' ? (array)$decoded : $decoded;
    }

    /**
     * Decode a value stored for an 'array' or 'json' cast.
     *
     * @param mixed $value The stored value.
     * @return mixed
     */
    private function decodeStructured(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        // Malformed JSON read back as an empty array, a value the caller cannot
        // distinguish from a column that legitimately holds []. Handing back the
        // raw string keeps the corruption visible.
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Encode a value for an 'array' or 'json' attribute.
     *
     * @param mixed $value The value to encode.
     * @return string The encoded JSON.
     * @throws InvalidArgumentException If the value cannot be encoded.
     */
    private function castToJson(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value);

        // json_encode() returns false on a resource, NAN, or invalid UTF-8. That
        // false was stored as-is and reached the database as an empty string,
        // destroying the column's contents without a word.
        if ($encoded === false) {
            throw new InvalidArgumentException(
                'Cannot encode value as JSON: ' . json_last_error_msg() . '.'
            );
        }

        return $encoded;
    }

    /**
     * Cast a value to boolean, handling PostgreSQL string representations.
     *
     * PostgreSQL returns boolean columns as 't'/'f' or 'true'/'false' strings
     * depending on PDO configuration. This method normalizes all formats.
     *
     * @param mixed $value The value to cast.
     * @return bool
     */
    private function castToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array(strtolower($value), ['f', 'false', '0', '', 'no', 'off'], true);
        }

        return (bool)$value;
    }
}
