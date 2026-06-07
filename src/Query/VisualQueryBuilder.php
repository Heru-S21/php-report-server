<?php

namespace ReportingEngine\Query;

use ReportingEngine\Connection\DriverInterface;

class VisualQueryBuilder
{
    public function buildSql(array $visualJson, DriverInterface $driver): string
    {
        $tables = $visualJson['tables'] ?? [];
        $joins = $visualJson['joins'] ?? [];
        $where = $visualJson['where'] ?? [];
        $orderBy = $visualJson['orderBy'] ?? [];
        $groupBy = $visualJson['groupBy'] ?? [];
        $limit = $visualJson['limit'] ?? null;

        if (empty($tables)) {
            return '';
        }

        // Build SELECT
        $selectCols = [];
        foreach ($tables as $table) {
            $alias = $table['alias'] ?? $table['name'];
            if (!empty($table['columns'])) {
                foreach ($table['columns'] as $col) {
                    if (!empty($col['selected'])) {
                        $name = $driver->quoteIdentifier($col['name']);
                        $colAlias = !empty($col['alias']) ? " AS " . $driver->quoteIdentifier($col['alias']) : '';
                        $selectCols[] = $driver->quoteIdentifier($alias) . '.' . $name . $colAlias;
                    }
                }
            }
        }

        $select = !empty($selectCols) ? implode(', ', $selectCols) : '*';

        // Build FROM
        $fromParts = [];
        foreach ($tables as $table) {
            $name = $driver->quoteIdentifier($table['name']);
            $alias = $table['alias'] ?? $table['name'];
            if ($alias !== $table['name']) {
                $fromParts[] = $name . ' ' . $driver->quoteIdentifier($alias);
            } else {
                $fromParts[] = $name;
            }
        }
        $from = implode(', ', $fromParts);

        // Build JOINs
        $joinParts = [];
        foreach ($joins as $join) {
            $type = strtoupper($join['type'] ?? 'INNER');
            $rightTable = $driver->quoteIdentifier($join['rightTable'] ?? '');
            $rightAlias = $this->findAlias($tables, $join['rightTable'] ?? '');
            $leftAlias = $this->findAlias($tables, $join['leftTable'] ?? '');
            $joinParts[] = sprintf(
                '%s JOIN %s %s ON %s.%s = %s.%s',
                $type,
                $rightTable,
                $driver->quoteIdentifier($rightAlias),
                $driver->quoteIdentifier($leftAlias),
                $driver->quoteIdentifier($join['leftCol'] ?? ''),
                $driver->quoteIdentifier($rightAlias),
                $driver->quoteIdentifier($join['rightCol'] ?? '')
            );
        }

        // Build WHERE
        $whereParts = [];
        $whereConjunctions = [];
        foreach ($where as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'] ?? '';
            $whereConjunctions[] = strtoupper($condition['conjunction'] ?? 'AND');

            $clause = $field . ' ' . $operator;
            if (!in_array($operator, ['IS NULL', 'IS NOT NULL'])) {
                $clause .= ' ' . $this->quoteValue($value);
            }
            $whereParts[] = $clause;
        }

        // Build ORDER BY
        $orderParts = [];
        foreach ($orderBy as $order) {
            $orderParts[] = ($order['field'] ?? '') . ' ' . strtoupper($order['direction'] ?? 'ASC');
        }

        // Build GROUP BY
        $groupParts = [];
        foreach ($groupBy as $group) {
            $groupParts[] = $group['field'] ?? '';
        }

        // Assemble SQL
        $sql = "SELECT {$select}\nFROM {$from}";

        if (!empty($joinParts)) {
            $sql .= "\n" . implode("\n", $joinParts);
        }

        if (!empty($whereParts)) {
            $glue = ' ' . ($whereConjunctions[0] ?? 'AND') . ' ';
            $sql .= "\nWHERE " . implode($glue, $whereParts);
        }

        if (!empty($groupParts)) {
            $sql .= "\nGROUP BY " . implode(', ', $groupParts);
        }

        if (!empty($orderParts)) {
            $sql .= "\nORDER BY " . implode(', ', $orderParts);
        }

        if ($limit !== null) {
            $sql .= "\nLIMIT " . (int)$limit;
        }

        return $sql;
    }

    private function findAlias(array $tables, string $tableName): string
    {
        foreach ($tables as $t) {
            if ($t['name'] === $tableName) {
                return $t['alias'] ?? $tableName;
            }
        }
        return $tableName;
    }

    private function quoteValue(string $value): string
    {
        // Simple value quoting
        if (is_numeric($value)) return $value;
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
