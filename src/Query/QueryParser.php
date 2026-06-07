<?php

namespace ReportingEngine\Query;

class QueryParser
{
    public function parseFields(string $sql, array $columns): array
    {
        $fields = [];
        foreach ($columns as $col) {
            $fields[] = [
                'name' => $col['name'],
                'type' => $col['type'],
                'selected' => true,
            ];
        }
        return $fields;
    }

    public function extractTableNames(string $sql): array
    {
        $tables = [];
        // Simple FROM clause extraction
        if (preg_match('/\bFROM\s+([^\s,;()]+(?:\.\w+)?)/i', $sql, $m)) {
            $tables[] = trim($m[1], '`"[]');
        }
        // JOINs
        preg_match_all('/\bJOIN\s+([^\s]+)/i', $sql, $matches);
        foreach ($matches[1] as $t) {
            $tables[] = trim($t, '`"[]');
        }
        return array_unique($tables);
    }

    public function extractParameters(string $sql): array
    {
        preg_match_all('/:(\w+)/', $sql, $matches);
        return array_unique($matches[1]);
    }
}
