<?php

namespace Simsoft\DB\Grammar;

use Stringable;

/**
 * Escaping for text embedded in SQL string literals.
 *
 * Some parts of a statement cannot be bound as parameters — JSON paths and
 * PostgreSQL text search configurations must reach the engine as literal
 * text — so they are interpolated into the generated SQL. Any quote character
 * in them would otherwise close the literal early and let the remainder be
 * parsed as SQL.
 *
 * The debug renderings (`dump()`, `dd()`, `getFullSQL()`) interpolate whole
 * bind values for the same reason, and are built on the same escaping.
 */
trait EscapesStringLiteral
{
    /**
     * Render a bind value the way it would have to be written literally.
     *
     * Only the engine knows how its literals are spelled, so this belongs
     * beside the escaping rather than in the query builder. Nothing built here
     * is ever executed: values reach the server as bound parameters, and this
     * is what the statement is *shown* as.
     *
     * The point of the rendering is to mean what the executed statement meant,
     * so it follows what the driver sends rather than what the value looks like
     * in PHP. `PDOStatement::execute($binds)` binds every value as a string, so
     * on these engines a bound 7 compares as '7' and not as the number 7 — and
     * against a text column those differ: '007' = 7 is true where '007' = '7'
     * is not. Grammars whose driver binds by type override this.
     *
     * @param mixed $value The bind value.
     * @return string The value as a SQL literal.
     */
    public function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        // (string) matches the conversion PDO itself applies: true is '1',
        // false is the empty string, and a float takes its precision from the
        // same cast.
        if (is_scalar($value) || $value instanceof Stringable) {
            return $this->stringLiteral((string)$value);
        }

        // Arrays, resources and objects with no string form cannot be bound at
        // all. Naming the type keeps the statement parseable and says why it
        // would not run, where casting one raised an Error out of a method
        // whose whole purpose is to be safe to call while debugging.
        return $this->stringLiteral('[' . get_debug_type($value) . ']');
    }

    /**
     * Interpolate bind values into a statement for display.
     *
     * @param string $sql The statement, with placeholders.
     * @param array<int, mixed> $values The bind values, in statement order.
     * @param string $placeHolder The placeholder to replace. Default: '?'.
     * @return string
     */
    public function readableSQL(string $sql, array $values, string $placeHolder = '?'): string
    {
        if ($placeHolder === '') {
            return $sql;
        }

        $segments = explode($placeHolder, $sql);
        $result = $segments[0];
        $count = count($segments);

        for ($idx = 1; $idx < $count; $idx++) {
            // A statement short of binds keeps its placeholder. Substituting
            // one in as though it were a value rendered it as the string '?',
            // which reads as a value the caller passed and never did — and
            // which the server then rejects against a typed column.
            $result .= ($values === [] ? $placeHolder : $this->literal(array_shift($values)))
                . $segments[$idx];
        }

        return $result;
    }
    /**
     * Quote a value as a SQL string literal.
     *
     * @param string $value The raw value, without surrounding quotes.
     * @return string The value as a quoted, escaped SQL literal.
     */
    protected function stringLiteral(string $value): string
    {
        return "'" . $this->escapeStringLiteral($value) . "'";
    }

    /**
     * Escape the contents of a SQL string literal.
     *
     * Doubling the single quote is the SQL standard escape. Grammars whose
     * string literals also treat backslash as an escape character override
     * this method.
     *
     * @param string $value The raw value.
     * @return string The escaped value.
     */
    protected function escapeStringLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
