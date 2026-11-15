<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Tools;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Datomatic\LaravelDatabaseMcp\Support\AuditLog;
use Datomatic\LaravelDatabaseMcp\Support\ColumnMask;
use Datomatic\LaravelDatabaseMcp\Tools\Concerns\InteractsWithDatabaseSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Override;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function json_encode;

#[Description("Inspect the database structure. Without arguments returns the list of allowed tables and their relationships (foreign keys). With a table argument returns that table's columns, types and relationships; masked columns are flagged with their masking strategy and whether they can be searched by exact value. Use this before query_database to discover table and column names.")]
class DescribeDatabaseTool extends Tool
{
    use InteractsWithDatabaseSchema;

    protected string $name = 'describe_database';

    public function handle(Request $request): Response
    {
        return AuditLog::record($this->name, $request, fn (): Response => $this->describe($request));
    }

    private function describe(Request $request): Response
    {
        $validated = $request->validate([
            'table' => ['string'],
        ]);

        if (! isset($validated['table'])) {
            return $this->describeOverview();
        }

        $table = $validated['table'];

        if (! $this->isTableAllowed($table)) {
            return Response::error("Table '{$table}' is not available.");
        }

        return $this->describeTable($table);
    }

    private function describeOverview(): Response
    {
        $overview = array_map(fn (string $table): array => array_filter([
            'table' => $table,
            'description' => $this->tableDescription($table),
            'references' => $this->outgoingRelationships($table),
        ], static fn (mixed $value): bool => $value !== null), $this->allowedTables());

        return $this->json([
            'tables' => array_values($overview),
        ]);
    }

    private function describeTable(string $table): Response
    {
        $allowedColumns = $this->allowedColumns($table);

        $columns = array_values(array_map(
            fn (array $column): array => $this->describeColumn($table, $column),
            array_filter(
                $this->schemaBuilder()->getColumns($table),
                static fn (array $column): bool => in_array($column['name'], $allowedColumns, true),
            ),
        ));

        return $this->json(array_filter([
            'table' => $table,
            'description' => $this->tableDescription($table),
            'columns' => $columns,
            'references' => $this->outgoingRelationships($table),
            'referenced_by' => $this->incomingRelationships($table),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, default: mixed}  $column
     * @return array<string, mixed>
     */
    private function describeColumn(string $table, array $column): array
    {
        $description = [
            'name' => $column['name'],
            'type' => $column['type'],
            'nullable' => $column['nullable'],
            'default' => $column['default'],
        ];

        $comment = $this->columnDescription($table, $column['name']);

        if ($comment !== null) {
            $description['description'] = $comment;
        }

        $mask = $this->columnMask($table, $column['name']);

        if ($mask instanceof ColumnMask) {
            $description['masked'] = $mask->strategy;
            $description['searchable'] = $mask->searchable;
        }

        return $description;
    }

    /**
     * Foreign keys defined on this table pointing at other allowed tables.
     *
     * @return list<array{column: string, references: string}>
     */
    private function outgoingRelationships(string $table): array
    {
        $allowedTables = $this->allowedTables();
        $relationships = [];

        foreach ($this->schemaBuilder()->getForeignKeys($table) as $foreignKey) {
            if (! in_array($foreignKey['foreign_table'], $allowedTables, true)) {
                continue;
            }

            $relationships[] = [
                'column' => $foreignKey['columns'][0],
                'references' => $foreignKey['foreign_table'].'.'.$foreignKey['foreign_columns'][0],
            ];
        }

        return $relationships;
    }

    /**
     * Foreign keys on other allowed tables pointing back at this table.
     *
     * @return list<array{table: string, column: string}>
     */
    private function incomingRelationships(string $table): array
    {
        $relationships = [];

        foreach ($this->allowedTables() as $otherTable) {
            if ($otherTable === $table) {
                continue;
            }

            foreach ($this->schemaBuilder()->getForeignKeys($otherTable) as $foreignKey) {
                if ($foreignKey['foreign_table'] === $table) {
                    $relationships[] = [
                        'table' => $otherTable,
                        'column' => $foreignKey['columns'][0],
                    ];
                }
            }
        }

        return $relationships;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function json(array $content): Response
    {
        return Response::text((string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Optional table name. Omit to list all allowed tables and their relationships.'),
        ];
    }
}
