<?php

namespace Simsoft\DB\Traits;

use InvalidArgumentException;
use Simsoft\DB\Connection;
use Simsoft\DB\Grammar\Grammar;

/**
 * Trait Qualifier.
 *
 * Handles table/column name qualification with database-specific quoting.
 */
trait Qualifier
{
    /** @var null|string The table alias */
    protected ?string $alias = null;

    /** @var Grammar|null Cached grammar instance */
    private ?Grammar $grammar = null;

    /**
     * Set alias.
     *
     * @param string|null $alias The alias name.
     * @return static
     */
    public function alias(?string $alias): static
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Get alias.
     *
     * @return string|null
     */
    public function getAlias(): ?string
    {
        return $this->alias;
    }

    /**
     * Get the grammar instance for quoting.
     *
     * @return Grammar
     */
    protected function getGrammar(): Grammar
    {
        if ($this->grammar === null) {
            /** @phpstan-ignore function.alreadyNarrowedType */
            $connectionName = property_exists($this, 'connection') ? $this->connection : null;
            $this->grammar = Connection::grammar($connectionName);
        }
        return $this->grammar;
    }

    /**
     * Quote an identifier using the current grammar.
     *
     * @param string $identifier The identifier to quote.
     * @return string
     */
    protected function quote(string $identifier): string
    {
        return $this->getGrammar()->quoteIdentifier($identifier);
    }

    /**
     * Qualify an attribute name for use in SQL.
     *
     * Handles:
     * - `*` → {*} for deferred resolution
     * - `!table.col` → quoted table.col (explicit, legacy syntax)
     * - `table.col` → quoted table.col (dot = explicit table reference)
     * - `table.*` → quoted table.*
     * - `col` → {col} for deferred resolution (auto-prefixed later)
     *
     * @param string $attribute The attribute name.
     * @return string
     */
    protected function queryAttribute(string $attribute): string
    {
        // Legacy explicit prefix: !table.col
        if ($attribute[0] === '!') {
            $raw = ltrim($attribute, '!');
            $parts = explode('.', $raw, 2);
            if (isset($parts[1])) {
                return $parts[1] === '*'
                    ? $this->quote($parts[0]) . '.*'
                    : $this->quote($parts[0]) . '.' . $this->quote($parts[1]);
            }
            return $this->quote($raw);
        }

        // Already wrapped for deferred resolution
        if ($attribute[0] === '{') {
            return $attribute;
        }

        // JSON path notation: column->path (auto JSON_EXTRACT)
        if (str_contains($attribute, '->')) {
            $jsonParts = explode('->', $attribute, 2);
            return $this->getGrammar()->jsonExtract(
                '{' . $jsonParts[0] . '}',
                $jsonParts[1]
            );
        }

        // Dot-notation: table.col or table.* — resolve immediately
        if (str_contains($attribute, '.')) {
            $parts = explode('.', $attribute, 2);
            return $parts[1] === '*'
                ? $this->quote($parts[0]) . '.*'
                : $this->quote($parts[0]) . '.' . $this->quote($parts[1]);
        }

        // Simple column name — defer resolution
        return '{' . $attribute . '}';
    }

    /**
     * Get qualified sub-query.
     *
     * @param string $sql The sub query SQL.
     * @param string|null $alias The alias name.
     * @return string
     */
    public function getQualifiedSubQuery(string $sql, ?string $alias = null): string
    {
        $this->alias($alias);
        return "($sql) " . $this->quote($this->alias ?? '');
    }

    /**
     * Get a qualified table name.
     *
     * @param string $table The table name.
     * @param string|null $alias The table alias.
     * @return string
     * @throws InvalidArgumentException If the table name contains invalid characters.
     */
    public function getQualifiedTable(string $table, ?string $alias = null): string
    {
        self::validateIdentifier($table);
        if ($alias !== null) {
            self::validateIdentifier($alias);
        }

        if ($alias === null) {
            $this->alias($table);
            return $this->quote($table);
        }

        $this->alias($alias);
        return $this->quote($table) . ' ' . $this->quote($alias);
    }

    /**
     * Validate that an identifier (table/column name) is safe.
     *
     * @param string $identifier The identifier to validate.
     * @return void
     * @throws InvalidArgumentException If the identifier is invalid.
     */
    private static function validateIdentifier(string $identifier): void
    {
        if ($identifier === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $identifier)) {
            throw new InvalidArgumentException(
                "Invalid identifier: '$identifier'. Only alphanumeric characters, underscores, and dots are allowed."
            );
        }
    }

    /**
     * Get a qualified attribute name (used by Clause subclasses).
     *
     * @param string $attribute The attribute name.
     * @return string The qualified name.
     */
    public function getQualifiedAttribute(string $attribute): string
    {
        if ($attribute !== '*') {
            $attribute = $this->quote($attribute);
        }

        return $this->alias === null ? $attribute : $this->quote($this->alias) . ".$attribute";
    }

    /**
     * Resolve deferred `{attribute}` placeholders in SQL.
     *
     * @param string $sql The SQL to qualify.
     * @return string
     */
    public function getQualifiedSQL(string $sql): string
    {
        if (!str_contains($sql, '{')) {
            return $sql;
        }

        return preg_replace_callback('/\{([^}]+)}/', function (array $match) {
            $attribute = $match[1];
            $parts = explode('.', $attribute, 2);

            if (!isset($parts[1])) {
                return $this->getQualifiedAttribute($parts[0]);
            }

            return $parts[1] === '*'
                ? $this->quote($parts[0]) . '.*'
                : $this->quote($parts[0]) . '.' . $this->quote($parts[1]);
        }, $sql) ?? $sql;
    }

    /**
     * Replace placeholders with actual values for readable SQL (debug only).
     *
     * @param string $sql The SQL statement.
     * @param array<int, mixed> $values Values to replace.
     * @param string $placeHolder String to be replaced. Default: '?'.
     * @return string
     */
    public function getReadableSQL(string $sql, array $values, string $placeHolder = '?'): string
    {
        if ($placeHolder === '') {
            return $sql;
        }

        $segments = explode($placeHolder, $sql);
        $result = $segments[0];
        $count = count($segments);

        for ($idx = 1; $idx < $count; $idx++) {
            $value = array_shift($values) ?? $placeHolder;
            if ($value === null) {
                $value = 'NULL';
            } elseif (!is_numeric($value)) {
                $escaped = str_replace("'", "''", (string)$value);
                $value = "'$escaped'";
            }
            $result .= $value . $segments[$idx];
        }

        return $result;
    }
}
