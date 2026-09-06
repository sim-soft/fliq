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
    /** @var array<int, string> Comparison operators permitted in generated SQL */
    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=', '<=>',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
        'IN', 'NOT IN', 'IS', 'IS NOT',
        'BETWEEN', 'NOT BETWEEN',
        'REGEXP', 'NOT REGEXP', 'RLIKE',
    ];

    /** @var array<int, string> Logical operators permitted between conditions */
    private const ALLOWED_LOGICAL_OPERATORS = ['AND', 'OR'];

    /** @var null|string The table alias */
    protected ?string $alias = null;

    /** @var Grammar|null Cached grammar instance */
    private ?Grammar $grammar = null;

    /**
     * Set alias.
     *
     * The alias is interpolated into every statement this query builds, both as
     * the name of the table and as the prefix on each unqualified column, and it
     * cannot be parameter-bound. Quoting alone does not make it safe: the
     * grammars double an embedded quote character rather than reject it, so
     * `t`;--` arrived as a usable identifier and the surrounding punctuation
     * survived into the SQL. Every other identifier reaches the statement
     * through validateIdentifier(); the aliases set by from(['t' => ...]),
     * withAlias() and Aggregate did not, because they were assigned here
     * directly instead. Validating at the setter covers all of them at once.
     *
     * @param string|null $alias The alias name, or null to clear it.
     * @return static
     * @throws InvalidArgumentException If the alias contains invalid characters.
     */
    public function alias(?string $alias): static
    {
        // Null clears the alias, which is how withAlias() restores a query that
        // never had one; only a given name is checked.
        if ($alias !== null) {
            self::validateIdentifier($alias);
        }

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
     * @throws InvalidArgumentException If the alias contains invalid characters.
     */
    public function getQualifiedSubQuery(string $sql, ?string $alias = null): string
    {
        // A derived table must be named: MySQL and PostgreSQL both refuse one
        // that is not. Quoting a null alias produced an empty identifier, which
        // MySQL and SQLite happen to accept and PostgreSQL rejects outright
        // ("zero-length delimited identifier") — the same call built a
        // statement that ran on two engines and would not parse on the third.
        // Saying so here names the argument at fault instead of leaving the
        // server to complain about a table nobody wrote.
        if ($alias === null) {
            throw new InvalidArgumentException(
                'A sub-query used as a table must be given an alias to be referred to by.'
            );
        }

        self::validateIdentifier($alias);
        $this->alias($alias);

        return "($sql) " . $this->quote($alias);
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

        // Handle schema-qualified table names (e.g., "public.users")
        $quotedTable = $this->quoteTableName($table);

        if ($alias === null) {
            // Use just the table name (without schema) as the alias for column resolution
            $parts = explode('.', $table);
            $this->alias(end($parts));
            return $quotedTable;
        }

        $this->alias($alias);
        return $quotedTable . ' ' . $this->quote($alias);
    }

    /**
     * Validate and quote a table name for a statement that writes to it.
     *
     * getQualifiedTable() also sets the alias, which is what a SELECT wants and
     * an INSERT, UPDATE or DELETE does not — those name their columns bare. The
     * write builders therefore called quote() directly and so skipped both the
     * validation every read path gets and the schema handling, which left
     * "public.user" quoted as one identifier naming a table no server has.
     *
     * @param string $table The table name, optionally schema-qualified.
     * @return string The quoted table reference.
     * @throws InvalidArgumentException If the table name contains invalid characters.
     */
    protected function quoteTable(string $table): string
    {
        self::validateIdentifier($table);

        return $this->quoteTableName($table);
    }

    /**
     * Validate and quote a column name.
     *
     * Quoting alone does not make a column name safe: the grammars double an
     * embedded quote character rather than reject it, so a name carrying its
     * own punctuation survived into the statement as usable SQL.
     *
     * @param string $column The column name.
     * @return string The quoted column name.
     * @throws InvalidArgumentException If the column name contains invalid characters.
     */
    protected function quoteColumn(string $column): string
    {
        self::validateIdentifier($column);

        return $this->quote($column);
    }

    /**
     * Whether the connection's engine accepts MySQL's statement modifiers.
     *
     * Shared by the Ignore and LowPriority traits, which are used together by
     * Update and Delete and so cannot each declare it.
     *
     * @return bool
     */
    protected function supportsModifiers(): bool
    {
        return $this->getGrammar()->supportsStatementModifiers();
    }

    /**
     * Quote a table name, handling schema-qualified names (schema.table).
     *
     * @param string $table The table name, optionally schema-qualified.
     * @return string The quoted table reference.
     */
    private function quoteTableName(string $table): string
    {
        if (!str_contains($table, '.')) {
            return $this->quote($table);
        }

        $parts = explode('.', $table, 2);
        return $this->quote($parts[0]) . '.' . $this->quote($parts[1]);
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
     * Validate a comparison operator against a whitelist.
     *
     * Operators are interpolated into SQL and cannot be parameter-bound, so an
     * unvalidated operator is a structural injection vector — e.g., passing
     * 'UNION SELECT' would replace the comparison entirely.
     *
     * @param string $operator The operator to validate.
     * @return string The normalized (uppercased where applicable) operator.
     * @throws InvalidArgumentException If the operator is not recognized.
     */
    protected function validateOperator(string $operator): string
    {
        $normalised = strtoupper(trim($operator));

        // Symbol operators are returned as-is; word operators are uppercased.
        if (in_array($normalised, self::ALLOWED_OPERATORS, true)) {
            return $normalised;
        }

        throw new InvalidArgumentException(
            "Invalid operator: '$operator'. Allowed operators: "
            . implode(', ', self::ALLOWED_OPERATORS) . '.'
        );
    }

    /**
     * Validate a logical operator joining two conditions.
     *
     * The logical operator is the trailing argument of some twenty condition
     * methods and, like the comparison operator, is interpolated rather than
     * bound. An unrecognised value was previously spliced in as a bare token,
     * so isNull('deleted_at', 'OR 1=1 -- ') produced a WHERE clause that
     * commented out the rest of the conditions and returned the whole table.
     *
     * @param string $logicalOperator The operator to validate.
     * @return string The normalized (uppercased) operator.
     * @throws InvalidArgumentException If the operator is not AND or OR.
     */
    protected function validateLogicalOperator(string $logicalOperator): string
    {
        $normalised = strtoupper(trim($logicalOperator));

        if (in_array($normalised, self::ALLOWED_LOGICAL_OPERATORS, true)) {
            return $normalised;
        }

        throw new InvalidArgumentException(
            "Invalid logical operator: '$logicalOperator'. Allowed operators: "
            . implode(', ', self::ALLOWED_LOGICAL_OPERATORS) . '.'
        );
    }

    /**
     * Reject IS and IS NOT given a value they cannot compare against.
     *
     * @param string $operator The validated operator.
     * @param mixed $value The value to compare against.
     * @return void
     * @throws InvalidArgumentException If IS or IS NOT is given a non-null value.
     */
    protected function assertNullComparison(string $operator, mixed $value): void
    {
        // IS and IS NOT take NULL, TRUE or FALSE on their right-hand side, not
        // a placeholder. A null value is routed to a NULL check before reaching
        // here; anything else built `col IS ?`, which the server rejects as a
        // syntax error naming only the position in the statement. The caller is
        // told which operator and value are at odds instead.
        if ($value !== null && ($operator === 'IS' || $operator === 'IS NOT')) {
            throw new InvalidArgumentException(sprintf(
                '"%s" compares against NULL; got %s. Use = or != to compare a value.',
                $operator,
                get_debug_type($value)
            ));
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
     * The rendering belongs to the grammar: only the engine knows how its
     * string literals are escaped. Rendered here with one rule for every
     * driver, the result was a statement that did not mean what the executed
     * one meant — see {@see \Simsoft\DB\Grammar\Grammar::literal()}.
     *
     * @param string $sql The SQL statement.
     * @param array<int, mixed> $values Values to replace.
     * @param string $placeHolder String to be replaced. Default: '?'.
     * @return string
     */
    public function getReadableSQL(string $sql, array $values, string $placeHolder = '?'): string
    {
        return $this->getGrammar()->readableSQL($sql, $values, $placeHolder);
    }
}
