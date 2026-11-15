<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Tools;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Datomatic\LaravelDatabaseMcp\Support\AuditLog;
use Datomatic\LaravelDatabaseMcp\Support\ColumnMask;
use Datomatic\LaravelDatabaseMcp\Tools\Concerns\InteractsWithDatabaseSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Override;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function ceil;
use function count;
use function implode;
use function in_array;
use function is_numeric;
use function is_scalar;
use function json_encode;
use function property_exists;
use function reset;
use function strtoupper;

#[Description('Run safe, read-only SELECT queries against allowed tables using structured parameters (no raw SQL). Supports joining related tables through their foreign keys, filters (comparison, like, in, between, null checks), multi-column sorting, aggregations (SUM/COUNT/MIN/MAX/AVG) with GROUP BY and HAVING, and page-based pagination.')]
class QueryDatabaseTool extends Tool
{
    use InteractsWithDatabaseSchema;

    protected string $name = 'query_database';

    /**
     * Operators allowed in HAVING conditions.
     *
     * @var list<string>
     */
    private const array COMPARISON_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'like'];

    /**
     * Operators allowed in filters (WHERE conditions).
     *
     * @var list<string>
     */
    private const array FILTER_OPERATORS = [
        ...self::COMPARISON_OPERATORS,
        'not_like', 'starts_with', 'ends_with',
        'in', 'not_in', 'between',
        'is_null', 'is_not_null',
    ];

    /**
     * Filter operators that compare against a list of values.
     *
     * @var list<string>
     */
    private const array LIST_OPERATORS = ['in', 'not_in', 'between'];

    /**
     * Filter operators that take no value at all.
     *
     * @var list<string>
     */
    private const array NULL_OPERATORS = ['is_null', 'is_not_null'];

    public function handle(Request $request): Response
    {
        return AuditLog::record($this->name, $request, fn (): Response => $this->query($request));
    }

    private function query(Request $request): Response
    {
        $maxLimit = (int) config('database-mcp.max_limit', 100);

        /** @var list<string> $aggregateFunctions */
        $aggregateFunctions = array_map(
            strtoupper(...),
            array_values((array) config('database-mcp.aggregate_functions', [])),
        );

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
            'aggregates' => ['array'],
            'aggregates.*.function' => ['required', 'string'],
            'aggregates.*.column' => ['required', 'string'],
            'aggregates.*.alias' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'aggregates.*.distinct' => ['boolean'],
            'group_by' => ['array'],
            'group_by.*' => ['string'],
            'having' => ['array'],
            'having.*.target' => ['required', 'string'],
            'having.*.operator' => ['required', 'string', 'in:'.implode(',', self::COMPARISON_OPERATORS)],
            'having.*.value' => ['present'],
            'filters' => ['array'],
            'filters.*.column' => ['required', 'string'],
            'filters.*.operator' => ['required', 'string', 'in:'.implode(',', self::FILTER_OPERATORS)],
            'filters.*.value' => ['nullable'],
            'filters.*.values' => ['array', 'max:1000'],
            'order_by' => ['string'],
            'order_direction' => ['in:asc,desc'],
            'sort' => ['array'],
            'sort.*.column' => ['required', 'string'],
            'sort.*.direction' => ['in:asc,desc'],
            'limit' => ['integer', 'min:1', 'max:'.$maxLimit],
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:'.$maxLimit],
            'with_total' => ['boolean'],
        ]);

        $table = $validated['table'];

        if (! $this->isTableAllowed($table)) {
            return Response::error("Table '{$table}' is not available.");
        }

        $query = $this->databaseConnection()->table($table);

        // Joins -------------------------------------------------------------
        $joins = $validated['joins'] ?? [];
        $hasJoins = $joins !== [];
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
                return Response::error("Multiple relationships between '{$table}' and '{$related}'. Specify 'on' with one of: ".implode(', ', array_keys($candidates)).'.');
            } else {
                $foreignKey = reset($candidates);
            }

            match ($join['type'] ?? 'left') {
                'inner' => $query->join($related, $foreignKey['local'], '=', $foreignKey['foreign']),
                default => $query->leftJoin($related, $foreignKey['local'], '=', $foreignKey['foreign']),
            };

            $joinedTables[] = $related;
        }

        $aggregates = $validated['aggregates'] ?? [];
        $aggregateMode = $aggregates !== [];
        $aggregateAliases = [];
        $groupColumns = [];
        $masks = [];

        // Column selection --------------------------------------------------
        if ($aggregateMode) {
            $selection = $this->applyAggregateSelection($query, $table, $joinedTables, $aggregates, $validated['group_by'] ?? [], $aggregateFunctions);

            if ($selection instanceof Response) {
                return $selection;
            }

            [$aggregateAliases, $groupColumns] = $selection;

            if (($validated['columns'] ?? []) !== []) {
                return Response::error("Do not pass 'columns' together with 'aggregates'; use 'group_by' for the non-aggregated columns.");
            }

            $having = $this->applyHaving($query, $validated['having'] ?? [], $aggregateAliases, $groupColumns);

            if ($having instanceof Response) {
                return $having;
            }
        } else {
            if (($validated['having'] ?? []) !== []) {
                return Response::error("'having' can only be used together with 'aggregates'.");
            }

            $columns = $this->resolveColumns($validated['columns'] ?? [], $this->allowedColumns($table));

            if ($columns === null) {
                return Response::error('One or more requested columns do not exist or are not allowed.');
            }

            $select = $this->qualifiedSelect($table, $columns, $hasJoins);
            $masks = $this->resultMasks($table, $columns, $hasJoins);

            foreach ($joins as $join) {
                $relatedColumns = $this->resolveColumns($join['columns'] ?? [], $this->allowedColumns($join['table']));

                if ($relatedColumns === null) {
                    return Response::error("One or more columns requested on '{$join['table']}' do not exist or are not allowed.");
                }

                $select = [...$select, ...$this->qualifiedSelect($join['table'], $relatedColumns, true)];
                $masks = [...$masks, ...$this->resultMasks($join['table'], $relatedColumns, true)];
            }

            $query->select($select);
        }

        // Filters (WHERE) ---------------------------------------------------
        foreach ($validated['filters'] ?? [] as $filter) {
            $resolved = $this->resolveQualifiedColumn($table, $joinedTables, $filter['column']);

            if ($resolved === null) {
                return Response::error("Filter column '{$filter['column']}' does not exist or is not allowed.");
            }

            $mask = $this->columnMask($resolved['table'], $resolved['column']);

            if ($mask instanceof ColumnMask && ! in_array($filter['operator'], $this->maskedFilterOperators($mask), true)) {
                return Response::error("Column '{$filter['column']}' is masked: filters may only use ".implode(', ', $this->maskedFilterOperators($mask)).'.');
            }

            $error = $this->applyFilter($query, $hasJoins ? $resolved['qualified'] : $resolved['column'], $filter);

            if ($error instanceof Response) {
                return $error;
            }
        }

        // Ordering ----------------------------------------------------------
        if (isset($validated['order_by']) && isset($validated['sort'])) {
            return Response::error("Pass either 'order_by' or 'sort', not both.");
        }

        $sorts = isset($validated['order_by'])
            ? [['column' => $validated['order_by'], 'direction' => $validated['order_direction'] ?? 'asc']]
            : $validated['sort'] ?? [];

        foreach ($sorts as $sort) {
            if (! $aggregateMode && $this->isMaskedReference($table, $joinedTables, $sort['column'])) {
                return Response::error("Column '{$sort['column']}' is masked and cannot be used to sort.");
            }

            $orderColumn = $this->resolveOrderColumn($sort['column'], $aggregateMode, $aggregateAliases, $groupColumns, $table, $joinedTables, $hasJoins);

            if ($orderColumn === null) {
                return Response::error("Order column '{$sort['column']}' does not exist or is not allowed.");
            }

            $query->orderBy($orderColumn, $sort['direction'] ?? 'asc');
        }

        // Pagination / limit ------------------------------------------------
        $paginated = isset($validated['page']) || isset($validated['per_page']);
        $pagination = null;

        $countQuery = $paginated && ($validated['with_total'] ?? false) && ! $aggregateMode ? clone $query : null;

        if ($paginated) {
            $pagination = ['page' => $validated['page'] ?? 1, 'per_page' => $validated['per_page'] ?? 50];

            $query->forPage($pagination['page'], $pagination['per_page']);
        } else {
            $query->limit($validated['limit'] ?? 50);
        }

        [$rows, $total] = $this->withQueryTimeout(static fn (): array => [$query->get(), $countQuery?->count()]);

        if ($masks !== []) {
            $rows = $rows->map(static function (object $row) use ($masks): object {
                foreach ($masks as $key => $mask) {
                    if (property_exists($row, $key)) {
                        $row->{$key} = $mask->apply($row->{$key});
                    }
                }

                return $row;
            });
        }

        if ($pagination !== null && $total !== null) {
            $pagination['total'] = $total;
            $pagination['last_page'] = (int) ceil($total / $pagination['per_page']);
        }

        $payload = [
            'table' => $table,
            'joins' => $joinedTables,
            'count' => $rows->count(),
            'rows' => $rows,
        ];

        if ($aggregateMode) {
            $payload['group_by'] = $groupColumns;
            $payload['aggregates'] = $aggregateAliases;
        }

        if ($pagination !== null) {
            $payload['pagination'] = $pagination;
        }

        return Response::text((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Apply the GROUP BY columns and aggregate expressions to the query.
     *
     * @param  array<int, array{function: string, column: string, alias: string, distinct?: bool}>  $aggregates
     * @param  list<string>  $groupBy
     * @param  list<string>  $joinedTables
     * @param  list<string>  $aggregateFunctions
     * @return Response|array{0: list<string>, 1: list<string>}
     */
    private function applyAggregateSelection(Builder $query, string $table, array $joinedTables, array $aggregates, array $groupBy, array $aggregateFunctions): Response|array
    {
        $grammar = $query->getGrammar();
        $groupColumns = [];

        foreach ($groupBy as $reference) {
            $resolved = $this->resolveQualifiedColumn($table, $joinedTables, $reference);

            if ($resolved === null) {
                return Response::error("Group by column '{$reference}' does not exist or is not allowed.");
            }

            if ($this->columnMask($resolved['table'], $resolved['column']) instanceof ColumnMask) {
                return Response::error("Column '{$reference}' is masked and cannot be used in group_by.");
            }

            $query->groupBy($resolved['qualified']);
            $query->addSelect($resolved['qualified'].' as '.$reference);
            $groupColumns[] = $reference;
        }

        $aggregateAliases = [];

        foreach ($aggregates as $aggregate) {
            $function = strtoupper($aggregate['function']);

            if (! in_array($function, $aggregateFunctions, true)) {
                return Response::error("Aggregate function '{$aggregate['function']}' is not allowed.");
            }

            $column = $aggregate['column'];
            $distinct = ($aggregate['distinct'] ?? false) === true;

            if ($column === '*') {
                if ($function !== 'COUNT') {
                    return Response::error("The '*' column can only be used with COUNT.");
                }

                $wrapped = '*';
                $distinct = false;
            } else {
                $resolved = $this->resolveQualifiedColumn($table, $joinedTables, $column);

                if ($resolved === null) {
                    return Response::error("Aggregate column '{$column}' does not exist or is not allowed.");
                }

                if ($function !== 'COUNT' && $this->columnMask($resolved['table'], $resolved['column']) instanceof ColumnMask) {
                    return Response::error("Column '{$column}' is masked: only COUNT can aggregate it.");
                }

                $wrapped = $grammar->wrap($resolved['qualified']);
            }

            $expression = $function.'('.($distinct ? 'DISTINCT ' : '').$wrapped.') as '.$grammar->wrap($aggregate['alias']);
            $query->selectRaw($expression);
            $aggregateAliases[] = $aggregate['alias'];
        }

        return [$aggregateAliases, $groupColumns];
    }

    /**
     * @param  array<int, array{target: string, operator: string, value: mixed}>  $having
     * @param  list<string>  $aggregateAliases
     * @param  list<string>  $groupColumns
     */
    private function applyHaving(Builder $query, array $having, array $aggregateAliases, array $groupColumns): ?Response
    {
        foreach ($having as $condition) {
            $target = $condition['target'];

            $isAlias = in_array($target, $aggregateAliases, true);

            if (! $isAlias && ! in_array($target, $groupColumns, true)) {
                return Response::error("Having target '{$target}' must be an aggregate alias or a group_by column.");
            }

            $value = $this->filterValue($condition['operator'], $condition['value']);

            // Aggregate aliases have no column affinity, so numeric comparisons
            // must bind a number rather than a string to compare correctly.
            if ($isAlias && $condition['operator'] !== 'like' && is_numeric($value)) {
                $value += 0;
            }

            $query->having($target, $condition['operator'], $value);
        }

        return null;
    }

    /**
     * @param  list<string>  $aggregateAliases
     * @param  list<string>  $groupColumns
     * @param  list<string>  $joinedTables
     */
    private function resolveOrderColumn(string $orderBy, bool $aggregateMode, array $aggregateAliases, array $groupColumns, string $table, array $joinedTables, bool $hasJoins): ?string
    {
        if ($aggregateMode) {
            if (in_array($orderBy, $aggregateAliases, true) || in_array($orderBy, $groupColumns, true)) {
                return $orderBy;
            }

            return null;
        }

        $resolved = $this->resolveQualifiedColumn($table, $joinedTables, $orderBy);

        if ($resolved === null) {
            return null;
        }

        return $hasJoins ? $resolved['qualified'] : $resolved['column'];
    }

    /**
     * @param  array{column: string, operator: string, value?: mixed, values?: array<int, mixed>}  $filter
     */
    private function applyFilter(Builder $query, string $column, array $filter): ?Response
    {
        $operator = $filter['operator'];

        if (in_array($operator, self::NULL_OPERATORS, true)) {
            match ($operator) {
                'is_null' => $query->whereNull($column),
                default => $query->whereNotNull($column),
            };

            return null;
        }

        if (in_array($operator, self::LIST_OPERATORS, true)) {
            $values = $filter['values'] ?? [];

            foreach ($values as $value) {
                if (! is_scalar($value)) {
                    return Response::error("Filter '{$filter['column']}' values must be scalars.");
                }
            }

            if ($operator === 'between') {
                if (count($values) !== 2) {
                    return Response::error("Filter '{$filter['column']}' with 'between' needs exactly two 'values': [min, max].");
                }

                $query->whereBetween($column, array_values($values));

                return null;
            }

            if ($values === []) {
                return Response::error("Filter '{$filter['column']}' with '{$operator}' needs a non-empty 'values' list.");
            }

            match ($operator) {
                'in' => $query->whereIn($column, $values),
                default => $query->whereNotIn($column, $values),
            };

            return null;
        }

        if (! array_key_exists('value', $filter)) {
            return Response::error("Filter '{$filter['column']}' with '{$operator}' needs a 'value'.");
        }

        $value = $filter['value'];

        match ($operator) {
            'not_like' => $query->where($column, 'not like', '%'.$value.'%'),
            'starts_with' => $query->where($column, 'like', $value.'%'),
            'ends_with' => $query->where($column, 'like', '%'.$value),
            default => $query->where($column, $operator, $this->filterValue($operator, $value)),
        };

        return null;
    }

    /**
     * Masks to apply to the result rows, keyed by the row key each selected
     * column ends up under.
     *
     * @param  list<string>  $columns
     * @return array<string, ColumnMask>
     */
    private function resultMasks(string $table, array $columns, bool $qualify): array
    {
        $masks = [];

        foreach ($columns as $column) {
            $mask = $this->columnMask($table, $column);

            if ($mask instanceof ColumnMask) {
                $masks[$qualify ? $table.'.'.$column : $column] = $mask;
            }
        }

        return $masks;
    }

    /**
     * Operators that cannot reveal a masked value: null checks always, and an
     * exact match when the column is searchable (the caller must already know
     * the full value). Pattern matching, ranges and lists stay blocked, since
     * they would let the value be reconstructed or enumerated.
     *
     * @return list<string>
     */
    private function maskedFilterOperators(ColumnMask $mask): array
    {
        return $mask->searchable ? ['=', ...self::NULL_OPERATORS] : self::NULL_OPERATORS;
    }

    /**
     * @param  list<string>  $joinedTables
     */
    private function isMaskedReference(string $table, array $joinedTables, string $reference): bool
    {
        $resolved = $this->resolveQualifiedColumn($table, $joinedTables, $reference);

        return $resolved !== null && $this->columnMask($resolved['table'], $resolved['column']) instanceof ColumnMask;
    }

    private function filterValue(string $operator, mixed $value): mixed
    {
        return $operator === 'like' ? '%'.$value.'%' : $value;
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
            static fn (string $column): string => $table.'.'.$column.' as '.$table.'.'.$column,
            $columns,
        );
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Table to query. Sensitive tables (auth tokens, sessions, jobs) are blocked.')
                ->required(),
            'columns' => $schema->array()
                ->items($schema->string())
                ->description('Columns to select. Omit for all allowed columns. Not allowed together with "aggregates" (use "group_by"). With joins, result keys are prefixed with the table name (e.g. "orders.id").'),
            'joins' => $schema->array()
                ->items($schema->object([
                    'table' => $schema->string()->description('Related table to join. Must share a foreign key with the base table.')->required(),
                    'on' => $schema->string()->description('Foreign key column to join on. Required only when the two tables share more than one relationship (e.g. orders.user_id vs orders.created_by).'),
                    'type' => $schema->string()->description('Join type: "left" (default) or "inner".'),
                    'columns' => $schema->array()->items($schema->string())->description('Columns from the related table. Omit for all allowed columns.'),
                ]))
                ->description('Join related tables via their foreign keys. The join condition is derived automatically; you only name the table.'),
            'aggregates' => $schema->array()
                ->items($schema->object([
                    'function' => $schema->string()->description('One of SUM, COUNT, MIN, MAX, AVG.')->required(),
                    'column' => $schema->string()->description('Column to aggregate ("table.column" or a base column). Use "*" only with COUNT.')->required(),
                    'alias' => $schema->string()->description('Result key for this aggregate (letters, digits, underscore).')->required(),
                    'distinct' => $schema->boolean()->description('Aggregate distinct values (e.g. COUNT(DISTINCT column)).'),
                ]))
                ->description('Aggregate expressions. When present the result is grouped; pass the non-aggregated columns in "group_by" instead of "columns".'),
            'group_by' => $schema->array()
                ->items($schema->string())
                ->description('Columns to group by ("table.column" or a base column). Required for any non-aggregated column when using "aggregates".'),
            'having' => $schema->array()
                ->items($schema->object([
                    'target' => $schema->string()->description('An aggregate alias or a group_by column.')->required(),
                    'operator' => $schema->string()->description('One of: '.implode(', ', self::COMPARISON_OPERATORS))->required(),
                    'value' => $schema->string()->required(),
                ]))
                ->description('Conditions on grouped results (only with "aggregates").'),
            'filters' => $schema->array()
                ->items($schema->object([
                    'column' => $schema->string()->description('Column to filter ("table.column" for a joined table, or a base column).')->required(),
                    'operator' => $schema->string()->description('One of: '.implode(', ', self::FILTER_OPERATORS).'. "like"/"not_like" match anywhere, "starts_with"/"ends_with" match a prefix/suffix.')->required(),
                    'value' => $schema->string()->description('Value for comparison and like-style operators. Omit for is_null/is_not_null.'),
                    'values' => $schema->array()->items($schema->string())->description('Values for "in"/"not_in", or [min, max] for "between".'),
                ]))
                ->description('WHERE conditions, ANDed together. May target the base table or any joined table. Masked columns (see describe_database) only accept is_null/is_not_null, plus "=" with the full exact value when searchable.'),
            'order_by' => $schema->string()->description('Single column to sort by ("table.column" for a joined table). In aggregate mode this may be an aggregate alias or a group_by column. Use "sort" for several columns.'),
            'order_direction' => $schema->string()->description('asc or desc (used with "order_by").'),
            'sort' => $schema->array()
                ->items($schema->object([
                    'column' => $schema->string()->description('Column, aggregate alias or group_by column, as for "order_by".')->required(),
                    'direction' => $schema->string()->description('asc (default) or desc.'),
                ]))
                ->description('Sort by several columns, in order. Do not combine with "order_by".'),
            'limit' => $schema->integer()
                ->description('Max rows for a non-paginated query (1 to the configured maximum, default 50).')
                ->min(1),
            'page' => $schema->integer()->description('Page number (1-based). Enables pagination.')->min(1),
            'per_page' => $schema->integer()->description('Rows per page (1 to the configured maximum, default 50).')->min(1),
            'with_total' => $schema->boolean()->description('Include the total row count and last_page (runs an extra COUNT; ignored in aggregate mode).'),
        ];
    }
}
