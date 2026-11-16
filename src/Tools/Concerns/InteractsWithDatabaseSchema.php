<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Tools\Concerns;

use Closure;
use Datomatic\LaravelDatabaseMcp\Support\ColumnMask;
use Datomatic\LaravelDatabaseMcp\Support\QueryTimeout;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function str_contains;

trait InteractsWithDatabaseSchema
{
    /**
     * @var list<string>|null
     */
    private ?array $allowedTablesCache = null;

    /**
     * @var array<string, list<string>>
     */
    private array $allowedColumnsCache = [];

    /**
     * @var array<string, list<string>>
     */
    private array $columnListingCache = [];

    /**
     * @return list<string>
     */
    private function deniedTables(): array
    {
        return array_values((array) config('database-mcp.denied_tables', []));
    }

    /**
     * Patterns of the only tables that may be exposed, or null to expose every
     * table not explicitly denied.
     *
     * @return list<string>|null
     */
    private function allowlistedTables(): ?array
    {
        $allowed = config('database-mcp.allowed_tables');

        return is_array($allowed) ? array_values($allowed) : null;
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

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withQueryTimeout(Closure $callback): mixed
    {
        $timeout = config('database-mcp.query_timeout');

        return QueryTimeout::run(
            $this->databaseConnection(),
            $timeout === null ? null : (int) $timeout,
            $callback,
        );
    }

    /**
     * A table is allowed only when its exact name appears in the allowed
     * listing. Matching against the live listing (rather than asking the
     * schema builder whether the table exists) rejects schema-qualified or
     * differently-cased names that would otherwise slip past the deny list.
     */
    private function isTableAllowed(string $table): bool
    {
        return in_array($table, $this->allowedTables(), true);
    }

    private function isTableExposed(string $table): bool
    {
        if ($this->matchesAny($table, $this->deniedTables())) {
            return false;
        }

        $allowlist = $this->allowlistedTables();

        return $allowlist === null || $this->matchesAny($table, $allowlist);
    }

    /**
     * @return list<string>
     */
    private function allowedTables(): array
    {
        if ($this->allowedTablesCache !== null) {
            return $this->allowedTablesCache;
        }

        $builder = $this->schemaBuilder();
        $objects = $builder->getTables();

        if ((bool) config('database-mcp.include_views', false)) {
            $objects = [...$objects, ...$builder->getViews()];
        }

        // Only expose objects in the connection's own schema(s): the database
        // on MySQL, the search path on PostgreSQL, "main" on SQLite.
        $schemas = $builder->getCurrentSchemaListing();

        if ($schemas !== null) {
            $objects = array_filter(
                $objects,
                static fn (array $object): bool => ! isset($object['schema']) || in_array($object['schema'], $schemas, true),
            );
        }

        $names = array_unique(array_map(static fn (array $object): string => $object['name'], $objects));

        return $this->allowedTablesCache = array_values(array_filter(
            $names,
            fn (string $table): bool => $this->isTableExposed($table) && $this->isTableTenantSafe($table),
        ));
    }

    /**
     * @return list<string>
     */
    private function allowedColumns(string $table): array
    {
        return $this->allowedColumnsCache[$table] ??= array_values(array_filter(
            $this->columnListing($table),
            fn (string $column): bool => $column !== $this->tenantColumn() && ! $this->isColumnDenied($table, $column),
        ));
    }

    /**
     * @return list<string>
     */
    private function columnListing(string $table): array
    {
        return $this->columnListingCache[$table] ??= array_values($this->schemaBuilder()->getColumnListing($table));
    }

    /**
     * The column holding the tenant key, or null when tenant scoping is off.
     */
    private function tenantColumn(): ?string
    {
        $column = config('database-mcp.tenant.column');

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * Whether rows of the table must be filtered on the tenant column.
     */
    private function isTenantScoped(string $table): bool
    {
        $column = $this->tenantColumn();

        return $column !== null && in_array($column, $this->columnListing($table), true);
    }

    /**
     * With tenant scoping on, a table without the tenant column is hidden
     * unless it is declared as shared: a child table (e.g. order_items) that
     * only reaches the tenant through its parent would otherwise leak rows
     * of every tenant.
     */
    private function isTableTenantSafe(string $table): bool
    {
        if ($this->tenantColumn() === null || $this->isTenantScoped($table)) {
            return true;
        }

        return $this->matchesAny($table, array_values((array) config('database-mcp.tenant.shared_tables', [])));
    }

    private function isColumnDenied(string $table, string $column): bool
    {
        return collect($this->deniedColumns())->contains(
            fn (string $pattern): bool => $this->matchesColumnPattern($pattern, $table, $column),
        );
    }

    /**
     * The mask applied to a column, or null when the column is not masked.
     * Keys of "masked_columns" use the same patterns as "denied_columns";
     * the first matching entry wins.
     */
    private function columnMask(string $table, string $column): ?ColumnMask
    {
        foreach ((array) config('database-mcp.masked_columns', []) as $pattern => $mask) {
            if (is_string($pattern) && (is_string($mask) || is_array($mask)) && $this->matchesColumnPattern($pattern, $table, $column)) {
                return ColumnMask::fromConfig($mask);
            }
        }

        return null;
    }

    /**
     * A column pattern is either a bare column pattern applied to every table
     * ("password", "*_token") or a "table.column" pattern scoped to matching
     * tables ("users.email", "customers.*_iban").
     */
    private function matchesColumnPattern(string $pattern, string $table, string $column): bool
    {
        if (! str_contains($pattern, '.')) {
            return str($column)->is($pattern);
        }

        [$tablePattern, $columnPattern] = explode('.', $pattern, 2);

        return str($table)->is($tablePattern) && str($column)->is($columnPattern);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $value, array $patterns): bool
    {
        return collect($patterns)->contains(static fn (mixed $pattern): bool => is_string($pattern) && str($value)->is($pattern));
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
