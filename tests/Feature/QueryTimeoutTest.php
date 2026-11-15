<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Support\QueryTimeout;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

it('builds the timeout statements for each driver', function (string $driver, bool $isMaria, ?array $expected): void {
    expect(QueryTimeout::statements($driver, $isMaria, 5))->toBe($expected);
})->with([
    'mysql' => ['mysql', false, ['SET SESSION max_execution_time = 5000', 'SET SESSION max_execution_time = DEFAULT']],
    'mysql driver on mariadb' => ['mysql', true, ['SET SESSION max_statement_time = 5', 'SET SESSION max_statement_time = DEFAULT']],
    'mariadb' => ['mariadb', true, ['SET SESSION max_statement_time = 5', 'SET SESSION max_statement_time = DEFAULT']],
    'pgsql' => ['pgsql', false, ['SET statement_timeout = 5000', 'RESET statement_timeout']],
    'sqlite' => ['sqlite', false, null],
    'sqlsrv' => ['sqlsrv', false, null],
]);

it('sets the timeout before the callback and resets it afterwards', function (): void {
    $calls = [];

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$calls): bool {
        $calls[] = $sql;

        return true;
    });

    $result = QueryTimeout::run($connection, 3, function () use (&$calls): string {
        $calls[] = 'query';

        return 'rows';
    });

    expect($result)->toBe('rows')
        ->and($calls)->toBe(['SET statement_timeout = 3000', 'query', 'RESET statement_timeout']);
});

it('resets the timeout even when the query fails', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('statement')->with('SET statement_timeout = 3000')->once()->andReturnTrue();
    $connection->shouldReceive('statement')->with('RESET statement_timeout')->once()->andReturnTrue();

    QueryTimeout::run($connection, 3, fn (): never => throw new RuntimeException('canceling statement due to statement timeout'));
})->throws(RuntimeException::class, 'canceling statement due to statement timeout');

it('runs the callback untouched when the timeout is disabled', function (?int $seconds): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('statement');

    expect(QueryTimeout::run($connection, $seconds, fn (): string => 'rows'))->toBe('rows');
})->with([null, 0]);

it('queries normally on a driver without a session timeout', function (): void {
    config()->set('database-mcp.query_timeout', 5);

    DB::table('countries')->insert(['name' => 'Italy']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries'])
        ->assertOk()
        ->assertSee('Italy');
});
