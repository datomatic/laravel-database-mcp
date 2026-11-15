<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Log;

it('does not log when the audit log is disabled', function (): void {
    Log::shouldReceive('channel')->never();

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries'])->assertOk();
});

it('logs a successful query with the user, arguments and outcome', function (): void {
    config()->set('database-mcp.audit_log', ['enabled' => true, 'channel' => 'audit']);

    Log::shouldReceive('channel')->once()->with('audit')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'database-mcp tool call'
        && $context['tool'] === 'query_database'
        && $context['user_id'] === 7
        && $context['arguments'] === ['table' => 'countries', 'limit' => 5]
        && $context['outcome'] === 'ok'
        && $context['error'] === null
        && is_float($context['duration_ms']));

    DatabaseServer::actingAs(new GenericUser(['id' => 7]))
        ->tool(QueryDatabaseTool::class, ['table' => 'countries', 'limit' => 5])
        ->assertOk();
});

it('logs a rejected query with its error', function (): void {
    config()->set('database-mcp.audit_log', ['enabled' => true, 'channel' => null]);

    Log::shouldReceive('channel')->once()->with(null)->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(fn (string $message, array $context): bool => $context['outcome'] === 'error'
        && $context['error'] === "Table 'password_resets' is not available.");

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'password_resets'])
        ->assertHasErrors(["Table 'password_resets' is not available."]);
});

it('logs invalid arguments', function (): void {
    config()->set('database-mcp.audit_log', ['enabled' => true, 'channel' => null]);

    Log::shouldReceive('channel')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(fn (string $message, array $context): bool => $context['outcome'] === 'invalid');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries', 'limit' => 0])->assertHasErrors();
});

it('logs describe calls', function (): void {
    config()->set('database-mcp.audit_log', ['enabled' => true, 'channel' => null]);

    Log::shouldReceive('channel')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(fn (string $message, array $context): bool => $context['tool'] === 'describe_database'
        && $context['outcome'] === 'ok');

    DatabaseServer::tool(DescribeDatabaseTool::class)->assertOk();
});
