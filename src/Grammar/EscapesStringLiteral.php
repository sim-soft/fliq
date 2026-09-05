<?php

namespace Simsoft\DB\Grammar;

/**
 * Escaping for text embedded in SQL string literals.
 *
 * Some parts of a statement cannot be bound as parameters — JSON paths and
 * PostgreSQL text search configurations must reach the engine as literal
 * text — so they are interpolated into the generated SQL. Any quote character
 * in them would otherwise close the literal early and let the remainder be
 * parsed as SQL.
 */
trait EscapesStringLiteral
{
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
