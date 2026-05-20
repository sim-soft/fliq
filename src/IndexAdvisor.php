<?php

namespace Simsoft\DB;

/**
 * IndexAdvisor class.
 *
 * Analyzes logged queries and suggests missing indexes based on
 * WHERE, JOIN, and ORDER BY clause patterns.
 */
class IndexAdvisor
{
    /**
     * Analyze logged queries and suggest indexes.
     *
     * Examines WHERE, JOIN, and ORDER BY clauses to identify columns
     * that would benefit from indexing.
     *
     * @return array<int, array{table: string, columns: array<string>, reason: string}>
     */
    public static function suggest(): array
    {
        $queries = QueryLogger::getQueries();
        $suggestions = [];
        $seen = [];

        foreach ($queries as $query) {
            $sql = $query['sql'];
            $newSuggestions = self::analyzeQuery($sql);
            foreach ($newSuggestions as $suggestion) {
                $key = $suggestion['table'] . ':' . implode(',', $suggestion['columns']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $suggestions[] = $suggestion;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Analyze a single SQL query for index opportunities.
     *
     * @param string $sql The SQL query to analyze.
     * @return array<int, array{table: string, columns: array<string>, reason: string}>
     */
    private static function analyzeQuery(string $sql): array
    {
        $suggestions = [];

        // Extract table name from FROM clause
        if (preg_match('/FROM\s+[`"]?(\w+)[`"]?/i', $sql, $tableMatch)) {
            $table = $tableMatch[1];

            // Find WHERE columns
            $whereColumns = self::extractWhereColumns($sql, $table);
            if (!empty($whereColumns)) {
                $suggestions[] = [
                    'table' => $table,
                    'columns' => $whereColumns,
                    'reason' => 'Used in WHERE clause',
                ];
            }

            // Find ORDER BY columns without LIMIT (full table scan)
            $orderColumns = self::extractOrderColumns($sql, $table);
            if (!empty($orderColumns) && !str_contains(strtoupper($sql), 'LIMIT')) {
                $suggestions[] = [
                    'table' => $table,
                    'columns' => $orderColumns,
                    'reason' => 'Used in ORDER BY without LIMIT',
                ];
            }
        }

        // Find JOIN columns
        $pattern = '/JOIN\s+[`"]?(\w+)[`"]?\s+ON\s+[`"]?\w+[`"]?\.[`"]?(\w+)[`"]?\s*=\s*[`"]?\w+[`"]?\.[`"]?(\w+)[`"]?/i';
        if (preg_match_all($pattern, $sql, $joinMatches, PREG_SET_ORDER)) {
            foreach ($joinMatches as $match) {
                $suggestions[] = [
                    'table' => $match[1],
                    'columns' => [$match[2]],
                    'reason' => 'Used in JOIN condition',
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Extract columns used in WHERE conditions for a given table.
     *
     * @param string $sql The SQL query.
     * @param string $table The table name.
     * @return array<string>
     */
    private static function extractWhereColumns(string $sql, string $table): array
    {
        $columns = [];
        $pattern = '/[`"]?' . preg_quote($table, '/') . '[`"]?\.[`"]?(\w+)[`"]?\s*(?:=|>|<|>=|<=|!=|LIKE|IN|BETWEEN|IS)/i';
        if (preg_match_all($pattern, $sql, $matches)) {
            $columns = array_unique($matches[1]);
        }
        return array_values($columns);
    }

    /**
     * Extract columns used in ORDER BY for a given table.
     *
     * @param string $sql The SQL query.
     * @param string $table The table name.
     * @return array<string>
     */
    private static function extractOrderColumns(string $sql, string $table): array
    {
        $columns = [];
        if (preg_match('/ORDER\s+BY\s+(.+)/i', $sql, $orderMatch)) {
            $orderClause = $orderMatch[1];
            // Remove everything after LIMIT if present
            $limitPos = stripos($orderClause, 'LIMIT');
            if ($limitPos !== false) {
                $orderClause = substr($orderClause, 0, $limitPos);
            }
            $pattern = '/[`"]?' . preg_quote($table, '/') . '[`"]?\.[`"]?(\w+)[`"]?/i';
            if (preg_match_all($pattern, $orderClause, $matches)) {
                $columns = array_unique($matches[1]);
            }
        }
        return array_values($columns);
    }

    /**
     * Get suggestions as formatted SQL CREATE INDEX statements.
     *
     * @param string|null $connectionName Connection name for proper quoting. Null uses default.
     * @return array<int, string>
     */
    public static function suggestSQL(?string $connectionName = null): array
    {
        $suggestions = self::suggest();
        $statements = [];
        $grammar = Connection::grammar($connectionName);

        foreach ($suggestions as $suggestion) {
            $table = $suggestion['table'];
            $cols = $suggestion['columns'];
            $indexName = 'idx_' . $table . '_' . implode('_', $cols);
            $colList = implode(', ', array_map(fn(string $col): string => $grammar->quoteIdentifier($col), $cols));
            $quotedTable = $grammar->quoteIdentifier($table);
            $quotedIndex = $grammar->quoteIdentifier($indexName);
            $statements[] = "CREATE INDEX $quotedIndex ON $quotedTable ($colList); -- {$suggestion['reason']}";
        }

        return $statements;
    }
}
