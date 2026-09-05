<?php

namespace Simsoft\DB\Grammar;

/**
 * Escaping for JSON path segments embedded in SQL string literals.
 *
 * JSON paths cannot be bound as parameters — the engines require them as
 * literal text — so they are interpolated into the generated SQL. Any quote
 * character in the path would otherwise close the literal early and let the
 * remainder be parsed as SQL.
 */
trait EscapesJsonPath
{
    /**
     * Quote a JSON path as a SQL string literal.
     *
     * @param string $path The JSON path, without surrounding quotes.
     * @return string The path as a quoted, escaped SQL literal.
     */
    protected function jsonPathLiteral(string $path): string
    {
        return "'" . $this->escapeStringLiteral($path) . "'";
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
