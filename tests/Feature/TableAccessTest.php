<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

it('rejects a schema-qualified name for a denied table', function (string $tool): void {
    DB::table('password_resets')->insert(['email' => 'leak@example.com', 'token' => 'leaked-token']);

    DatabaseServer::tool($tool, ['table' => 'main.password_resets'])
        ->assertHasErrors(["Table 'main.password_resets' is not available."])
        ->assertDontSee('leaked-token');
})->with([QueryDatabaseTool::class, DescribeDatabaseTool::class]);

it('rejects a schema-qualified name for an allowed table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'main.users'])
        ->assertHasErrors(["Table 'main.users' is not available."]);
});

it('rejects a differently-cased name for a denied table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'PASSWORD_RESETS'])
        ->assertHasErrors(["Table 'PASSWORD_RESETS' is not available."]);
});

it('rejects joining a schema-qualified denied table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'joins' => [['table' => 'main.password_resets']],
    ])->assertHasErrors(["Cannot join table 'main.password_resets'."]);
});

it('hides tables matching a wildcard deny pattern', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class)
        ->assertOk()
        ->assertDontSee('telescope_entries')
        ->assertDontSee('oauth_clients');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'telescope_entries'])
        ->assertHasErrors(["Table 'telescope_entries' is not available."]);
});

it('exposes only allowlisted tables when an allowlist is set', function (): void {
    config()->set('database-mcp.allowed_tables', ['users', 'order*']);

    DatabaseServer::tool(DescribeDatabaseTool::class)
        ->assertOk()
        ->assertSee('"table":"users"')
        ->assertSee('"table":"orders"')
        ->assertDontSee('"table":"countries"');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries'])
        ->assertHasErrors(["Table 'countries' is not available."]);
});

it('still applies the deny list on top of the allowlist', function (): void {
    config()->set('database-mcp.allowed_tables', ['*']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'password_resets'])
        ->assertHasErrors(["Table 'password_resets' is not available."]);
});

it('hides views by default', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'order_codes'])
        ->assertHasErrors(["Table 'order_codes' is not available."]);
});

it('exposes views when enabled', function (): void {
    config()->set('database-mcp.include_views', true);

    DB::table('orders')->insert(['code' => 'VIEW-1', 'total' => 10]);

    DatabaseServer::tool(DescribeDatabaseTool::class)
        ->assertOk()
        ->assertSee('"table":"order_codes"');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'order_codes'])
        ->assertOk()
        ->assertSee('VIEW-1');
});

it('applies the deny list to views', function (): void {
    config()->set('database-mcp.include_views', true);
    config()->set('database-mcp.denied_tables', ['order_codes']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'order_codes'])
        ->assertHasErrors(["Table 'order_codes' is not available."]);
});
