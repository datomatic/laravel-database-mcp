<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Tools\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function str_contains;

trait InteractsWithDatabaseSchema
{
    /**
     * @var list<string>|null
     */
    private ?array $allowedTablesCache = null;

    /**
     * @return list<string>
     */
    private function deniedTables(): array
    {
        return array_values((array) config('database-mcp.denied_tables', []));
    }

    /**
     * @return list<string>
     */
    private function deniedColumns(): array
    {
        return array_values((array) config('database-mcp.denied_columns', []));
    }

    /**
     * Read-only connection used for every database operation.
     */
    private function databaseConnection(): Connection
    {
        return DB::connection(config('database-mcp.connection'));
    }

    private function schemaBuilder(): Builder
    {
        return Schema::connection(config('database-mcp.connection'));
    }

    private function isTableAllowed(string $table): bool
    {
        return ! in_array($table, $this->deniedTables(), true) && $this->schemaBuilder()->hasTable($table);
    }

    /**
     * @return list<string>
     */
    private function allowedTables(): array
    {
        if ($this->allowedTablesCache !== null) {
            return $this->allowedTablesCache;
        }

        $connection = $this->databaseConnection();
        $tables = $this->schemaBuilder()->getTables();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $database = $connection->getDatabaseName();
            $tables = array_filter(
                $tables,
                static fn (array $table): bool => ($table['schema'] ?? null) === $database,
            );
        }

        $names = array_map(static fn (array $table): string => $table['name'], $tables);

        return $this->allowedTablesCache = array_values(array_filter(
            $names,
            fn (string $table): bool => ! in_array($table, $this->deniedTables(), true),
        ));
    }

    /**
     * @return list<string>
     */
    private function allowedColumns(string $table): array
    {
        return array_values(array_diff($this->schemaBuilder()->getColumnListing($table), $this->deniedColumns()));
    }

    private function tableDescription(string $table): ?string
    {
        $descriptions = (array) config('database-mcp.table_descriptions', []);
        $description = $descriptions[$table] ?? null;

        return is_string($description) && $description !== '' ? $description : null;
    }

    private function columnDescription(string $table, string $column): ?string
    {
        $descriptions = (array) config('database-mcp.column_descriptions', []);
        $description = $descriptions["{$table}.{$column}"] ?? null;

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * Resolve a column reference ("table.column" or bare "column" on the base
     * table) to its table, column and qualified name, or null when the column
     * is not allowed or does not belong to the base or a joined table.
     *
     * @param  list<string>  $joinedTables
     * @return array{table: string, column: string, qualified: string}|null
     */
    private function resolveQualifiedColumn(string $baseTable, array $joinedTables, string $reference): ?array
    {
        if (str_contains($reference, '.')) {
            [$table, $column] = explode('.', $reference, 2);

            if ($table !== $baseTable && ! in_array($table, $joinedTables, true)) {
                return null;
            }
        } else {
            $table = $baseTable;
            $column = $reference;
        }

        if (! in_array($column, $this->allowedColumns($table), true)) {
            return null;
        }

        return [
            'table' => $table,
            'column' => $column,
            'qualified' => $table.'.'.$column,
        ];
    }

    /**
     * All foreign keys linking two tables, in either direction.
     *
     * Each candidate is keyed by its foreign key column so callers can
     * disambiguate when two tables are linked by more than one relationship
     * (e.g. orders.user_id and orders.created_by both point at users).
     *
     * @return array<string, array{local: string, foreign: string}>
     */
    private function foreignKeyCandidates(string $base, string $related): array
    {
        $candidates = [];

        foreach ($this->schemaBuilder()->getForeignKeys($base) as $foreignKey) {
            if ($foreignKey['foreign_table'] === $related) {
                $candidates[$foreignKey['columns'][0]] = [
                    'local' => $base.'.'.$foreignKey['columns'][0],
                    'foreign' => $related.'.'.$foreignKey['foreign_columns'][0],
                ];
            }
        }

        foreach ($this->schemaBuilder()->getForeignKeys($related) as $foreignKey) {
            if ($foreignKey['foreign_table'] === $base) {
                $candidates[$foreignKey['columns'][0]] = [
                    'local' => $base.'.'.$foreignKey['foreign_columns'][0],
                    'foreign' => $related.'.'.$foreignKey['columns'][0],
                ];
            }
        }

        return $candidates;
    }
}
