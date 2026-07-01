<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Tools;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Datomatic\LaravelDatabaseMcp\Tools\Concerns\InteractsWithDatabaseSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

use function array_keys;
use function array_map;
use function count;
use function implode;
use function in_array;
use function json_encode;
use function reset;

#[Description('Run safe, read-only SELECT queries against allowed tables using structured parameters (no raw SQL). Supports joining related tables through their foreign keys (e.g. orders with their user).')]
class QueryDatabaseTool extends Tool
{
    use InteractsWithDatabaseSchema;

    protected string $name = 'query_database';

    /**
     * Operators allowed in filters.
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'like'];

    public function handle(Request $request): Response
    {
        $maxLimit = (int) config('database-mcp.max_limit', 100);

        $validated = $request->validate([
            'table' => ['required', 'string'],
            'columns' => ['array'],
            'columns.*' => ['string'],
            'joins' => ['array'],
            'joins.*.table' => ['required', 'string'],
            'joins.*.on' => ['string'],
            'joins.*.type' => ['in:inner,left'],
            'joins.*.columns' => ['array'],
            'joins.*.columns.*' => ['string'],
            'filters' => ['array'],
            'filters.*.column' => ['required', 'string'],
            'filters.*.operator' => ['required', 'string', 'in:' . implode(',', self::ALLOWED_OPERATORS)],
            'filters.*.value' => ['present'],
            'order_by' => ['string'],
            'order_direction' => ['in:asc,desc'],
            'limit' => ['integer', 'min:1', 'max:' . $maxLimit],
        ]);

        $table = $validated['table'];

        if (! $this->isTableAllowed($table)) {
            return Response::error("Table '{$table}' is not available.");
        }

        $tableColumns = $this->allowedColumns($table);

        $columns = $this->resolveColumns($validated['columns'] ?? [], $tableColumns);

        if ($columns === null) {
            return Response::error('One or more requested columns do not exist or are not allowed.');
        }

        $joins = $validated['joins'] ?? [];
        $hasJoins = $joins !== [];

        $query = $this->databaseConnection()->table($table);
        $select = $this->qualifiedSelect($table, $columns, $hasJoins);
        $joinedTables = [];

        foreach ($joins as $join) {
            $related = $join['table'];

            if ($related === $table || ! $this->isTableAllowed($related)) {
                return Response::error("Cannot join table '{$related}'.");
            }

            $candidates = $this->foreignKeyCandidates($table, $related);

            if ($candidates === []) {
                return Response::error("No foreign key relationship between '{$table}' and '{$related}'.");
            }

            if (isset($join['on'])) {
                if (! isset($candidates[$join['on']])) {
                    return Response::error("'{$join['on']}' is not a foreign key between '{$table}' and '{$related}'.");
                }

                $foreignKey = $candidates[$join['on']];
            } elseif (count($candidates) > 1) {
                return Response::error("Multiple relationships between '{$table}' and '{$related}'. Specify 'on' with one of: " . implode(', ', array_keys($candidates)) . '.');
            } else {
                $foreignKey = reset($candidates);
            }

            $relatedColumns = $this->resolveColumns($join['columns'] ?? [], $this->allowedColumns($related));

            if ($relatedColumns === null) {
                return Response::error("One or more columns requested on '{$related}' do not exist or are not allowed.");
            }

            match ($join['type'] ?? 'left') {
                'inner' => $query->join($related, $foreignKey['local'], '=', $foreignKey['foreign']),
                default => $query->leftJoin($related, $foreignKey['local'], '=', $foreignKey['foreign']),
            };

            $select = [...$select, ...$this->qualifiedSelect($related, $relatedColumns, true)];
            $joinedTables[] = $related;
        }

        $query->select($select);

        foreach ($validated['filters'] ?? [] as $filter) {
            if (! in_array($filter['column'], $tableColumns, true)) {
                return Response::error("Filter column '{$filter['column']}' does not exist or is not allowed.");
            }

            $value = $filter['operator'] === 'like'
                ? '%' . $filter['value'] . '%'
                : $filter['value'];

            $query->where($this->qualify($table, $filter['column'], $hasJoins), $filter['operator'], $value);
        }

        if (isset($validated['order_by'])) {
            if (! in_array($validated['order_by'], $tableColumns, true)) {
                return Response::error("Order column '{$validated['order_by']}' does not exist or is not allowed.");
            }

            $query->orderBy($this->qualify($table, $validated['order_by'], $hasJoins), $validated['order_direction'] ?? 'asc');
        }

        $rows = $query->limit($validated['limit'] ?? 50)->get();

        return Response::text((string) json_encode([
            'table' => $table,
            'joins' => $joinedTables,
            'count' => $rows->count(),
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<string>  $requested
     * @param  list<string>  $allowed
     * @return list<string>|null
     */
    private function resolveColumns(array $requested, array $allowed): ?array
    {
        if ($requested === []) {
            return $allowed;
        }

        foreach ($requested as $column) {
            if (! in_array($column, $allowed, true)) {
                return null;
            }
        }

        return $requested;
    }

    private function qualify(string $table, string $column, bool $qualify): string
    {
        return $qualify ? $table . '.' . $column : $column;
    }

    /**
     * Build select expressions, aliasing as "table.column" when joins are present
     * so columns from different tables never collide in the result rows.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function qualifiedSelect(string $table, array $columns, bool $qualify): array
    {
        if (! $qualify) {
            return $columns;
        }

        return array_map(
            static fn (string $column): string => $table . '.' . $column . ' as ' . $table . '.' . $column,
            $columns,
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Table to query. Sensitive tables (auth tokens, sessions, jobs) are blocked.')
                ->required(),
            'columns' => $schema->array()
                ->items($schema->string())
                ->description('Columns to select. Omit for all allowed columns. With joins, result keys are prefixed with the table name (e.g. "orders.id").'),
            'joins' => $schema->array()
                ->items($schema->object([
                    'table' => $schema->string()->description('Related table to join. Must share a foreign key with the base table.')->required(),
                    'on' => $schema->string()->description('Foreign key column to join on. Required only when the two tables share more than one relationship (e.g. orders.user_id vs orders.created_by).'),
                    'type' => $schema->string()->description('Join type: "left" (default) or "inner".'),
                    'columns' => $schema->array()->items($schema->string())->description('Columns from the related table. Omit for all allowed columns.'),
                ]))
                ->description('Join related tables via their foreign keys. The join condition is derived automatically; you only name the table.'),
            'filters' => $schema->array()
                ->items($schema->object([
                    'column' => $schema->string()->required(),
                    'operator' => $schema->string()->description('One of: ' . implode(', ', self::ALLOWED_OPERATORS))->required(),
                    'value' => $schema->string()->required(),
                ]))
                ->description('WHERE conditions, ANDed together.'),
            'order_by' => $schema->string()->description('Column to sort by.'),
            'order_direction' => $schema->string()->description('asc or desc.'),
            'limit' => $schema->integer()
                ->description('Max rows (1 to the configured maximum, default 50).')
                ->min(1),
        ];
    }
}
