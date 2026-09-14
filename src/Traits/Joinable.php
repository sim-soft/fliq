<?php

namespace Simsoft\DB\Traits;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Joined table and sub-query sources.
 *
 * A join is registered unrendered and only turned into SQL by getJoinSQL().
 * Quoting it at join() time froze whichever grammar was current into the stored
 * string, so a connection named afterwards produced a statement quoted for one
 * engine and run against another. Only the parts that are not identifiers — the
 * join keyword and a sub-query's SQL — are fixed when the join is registered.
 *
 * Joins are keyed by alias, so re-joining the same alias replaces the earlier
 * entry rather than emitting it twice.
 */
trait Joinable
{
    /**
     * Joined relationships, keyed by alias, held unrendered.
     *
     * @var array<string, array{keyword: string, table: string|null, sub: string|null, alias: string|null, on: array{foreign: string, local: string}|null}>
     */
    protected array $joins = [];

    /**
     * Join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table The table name
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @param string $type Join type. LEFT, RIGHT, INNER, OUTER, etc.
     * @return static
     */
    public function join(string|array $table, array $on = [], string $type = 'INNER'): static
    {
        $alias = null;
        $join = $type ? strtoupper($type) . ' JOIN' : 'JOIN';

        if (is_array($table)) {
            return $this->joinSubQuery($join, $table, $on);
        }

        if (!str_contains($table, '(')) {
            $expressions = explode(' ', trim($table));
            $table = $expressions[0];
            $alias = end($expressions);
        }

        // A CROSS JOIN pairs every row and takes no ON clause, so calling
        // crossJoin() the documented way — with no keys — used to build one out
        // of empty strings: `ON `post`.`` = {}`, which the server rejects
        // outright. With no keys there is nothing to match on, so the clause is
        // omitted entirely.
        $named = $alias === null || $alias === $table ? null : $alias;

        $this->joins[$named ?? $table] = [
            'keyword' => $join,
            'table' => $table,
            'sub' => null,
            'alias' => $named,
            'on' => $on === [] ? null : $this->onParts($on, $table, $alias),
        ];

        return $this;
    }

    /**
     * Cross-join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table the join table
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function crossJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'CROSS');
    }

    /**
     * Left join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table the join table
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function leftJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'LEFT');
    }

    /**
     * Right join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table the join table
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function rightJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'RIGHT');
    }

    /**
     * Left outer join table.
     *
     * @param string $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function leftOuterJoin(string $table, array $on = []): static
    {
        return $this->join($table, $on, 'LEFT OUTER');
    }

    /**
     * Right outer join table.
     *
     * @param string $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function rightOuterJoin(string $table, array $on = []): static
    {
        return $this->join($table, $on, 'RIGHT OUTER');
    }

    /**
     * Generate JOINS statement
     *
     * @return string|null
     */
    public function getJoinSQL(): ?string
    {
        if (empty($this->joins)) {
            return null;
        }

        return implode(' ', array_map(fn(array $join): string => $this->renderJoin($join), $this->joins));
    }

    /**
     * Join a sub-query given in the array form: [alias => query].
     *
     * @param string $join The join keyword, e.g. 'INNER JOIN'.
     * @param array<string, string|ActiveQuery|Raw> $table The alias mapped to the sub-query.
     * @param array<string, string> $on The matching attributes.
     * @return static
     */
    private function joinSubQuery(string $join, array $table, array $on): static
    {
        $alias = (string)array_key_first($table);
        $subQuery = current($table);

        // The sub-query was folded into the table string and then handed to
        // quote(), which wrapped the whole SELECT in backticks as though it
        // were one column name — the server refused it as an over-long
        // identifier. It was also aliased twice, `(...) AS p` AS `p`, and its
        // bind values were dropped, so no shape of this call could ever run.
        // The alias is the only part that is an identifier; the sub-query is
        // parenthesised SQL and its binds are kept for the JOIN section, which
        // getSQL() emits after FROM and before WHERE.
        if ($subQuery instanceof ActiveQuery || $subQuery instanceof Raw) {
            $this->appendSectionBinds($this->joinBinds, $subQuery->getBinds());
        }

        $this->joins[$alias] = [
            'keyword' => $join,
            'table' => null,
            'sub' => (string)$subQuery,
            'alias' => $alias,
            'on' => $on === [] ? null : $this->onParts($on, $alias, $alias),
        ];

        return $this;
    }

    /**
     * Split an ON pair into the parts the clause is rendered from.
     *
     * The keys name columns, which cannot be quoted until the grammar is known,
     * so only the prefix-stripping is done here.
     *
     * @param array<string, string> $on The matching attributes.
     * @param string $table The join table, for stripping a redundant key prefix.
     * @param string|null $alias The join alias, for stripping a redundant key prefix.
     * @return array{foreign: string, local: string}
     */
    private function onParts(array $on, string $table, ?string $alias): array
    {
        $foreignKey = (string)array_key_first($on);

        // Strip a table / alias prefix from a foreign key if it matches the join table or alias
        // e.g., ['s.supp_idx' => 'supp_idx'] with alias 's' → foreignKey becomes 'supp_idx'
        if (str_contains($foreignKey, '.')) {
            $fkParts = explode('.', $foreignKey, 2);
            if ($fkParts[0] === $table || $fkParts[0] === $alias) {
                $foreignKey = $fkParts[1];
            }
        }

        return ['foreign' => $foreignKey, 'local' => (string)current($on)];
    }

    /**
     * Render one registered join for the current grammar.
     *
     * @param array{keyword: string, table: string|null, sub: string|null, alias: string|null, on: array{foreign: string, local: string}|null} $join
     * @return string
     */
    private function renderJoin(array $join): string
    {
        [$qualifier, $sql] = $this->renderJoinSource($join);

        if ($join['on'] === null) {
            return $sql;
        }

        return "$sql ON $qualifier." . $this->quote($join['on']['foreign'])
            . ' = ' . $this->queryAttribute($join['on']['local']);
    }

    /**
     * Render a join's source, and the qualifier its ON clause refers to it by.
     *
     * @param array{keyword: string, table: string|null, sub: string|null, alias: string|null, on: array{foreign: string, local: string}|null} $join
     * @return array{0: string, 1: string} The qualifier, then the rendered source.
     */
    private function renderJoinSource(array $join): array
    {
        if ($join['sub'] !== null) {
            $qualifier = $this->quote((string)$join['alias']);
            return [$qualifier, $join['keyword'] . ' (' . $join['sub'] . ") AS $qualifier"];
        }

        if ($join['alias'] !== null) {
            $qualifier = $this->quote($join['alias']);
            return [$qualifier, $join['keyword'] . ' ' . $this->quote((string)$join['table']) . " AS $qualifier"];
        }

        // An unaliased join is referred to by its own name.
        $qualifier = $this->quote((string)$join['table']);
        return [$qualifier, $join['keyword'] . " $qualifier"];
    }

    /**
     * Merge JOIN clauses from another query.
     *
     * @param array<string, array{keyword: string, table: string|null, sub: string|null, alias: string|null, on: array{foreign: string, local: string}|null}> $joins
     * @return void
     */
    private function mergeJoins(array $joins): void
    {
        foreach ($joins as $key => $join) {
            $this->joins[$key] = $join;
        }
    }
}
