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
use Override;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function json_encode;

#[Description("Inspect the database structure. Without arguments returns the list of allowed tables and their relationships (foreign keys). With a table argument returns that table's columns, types and relationships. Use this before query_database to discover table and column names.")]
class DescribeDatabaseTool extends Tool
{
    use InteractsWithDatabaseSchema;

    protected string $name = 'describe_database';

    public function handle(Request $request): Response
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
            fn (array $column): array => array_filter([
                'name' => $column['name'],
                'type' => $column['type'],
                'nullable' => $column['nullable'],
                'default' => $column['default'],
                'description' => $this->columnDescription($table, $column['name']),
            ], static fn (mixed $value, string $key): bool => $key !== 'description' || $value !== null, ARRAY_FILTER_USE_BOTH),
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
