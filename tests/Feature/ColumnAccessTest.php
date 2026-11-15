<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

it('strips columns matching a wildcard deny pattern', function (): void {
    DB::table('users')->insert(['email' => 'a@example.com', 'password' => 'x', 'remember_token' => 'remember-me-secret']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'users'])
        ->assertOk()
        ->assertSee('a@example.com')
        ->assertDontSee('remember-me-secret');
});

it('denies a table-scoped column only on matching tables', function (): void {
    config()->set('database-mcp.denied_columns', ['password', 'users.email']);

    DB::table('countries')->insert(['name' => 'Italy']);
    DB::table('users')->insert(['email' => 'hidden@example.com', 'firstname' => 'Ada', 'password' => 'x']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'users'])
        ->assertOk()
        ->assertSee('Ada')
        ->assertDontSee('hidden@example.com');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries'])
        ->assertOk()
        ->assertSee('Italy');

    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'users'])
        ->assertOk()
        ->assertDontSee('"name":"email"');
});

it('supports wildcards on both parts of a table-scoped column pattern', function (): void {
    config()->set('database-mcp.denied_columns', ['password', 'user*.first*']);

    DB::table('users')->insert(['email' => 'b@example.com', 'firstname' => 'Grace', 'password' => 'x']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'users'])
        ->assertOk()
        ->assertSee('b@example.com')
        ->assertDontSee('Grace');
});

it('rejects filtering on a table-scoped denied column', function (): void {
    config()->set('database-mcp.denied_columns', ['users.email']);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'filters' => [['column' => 'email', 'operator' => '=', 'value' => 'x']],
    ])->assertHasErrors(["Filter column 'email' does not exist or is not allowed."]);
});

it('strips a table-scoped denied column from a joined table', function (): void {
    config()->set('database-mcp.denied_columns', ['users.email']);

    $userId = DB::table('users')->insertGetId(['email' => 'joined@example.com', 'firstname' => 'Linus', 'password' => 'x']);
    DB::table('orders')->insert(['code' => 'J-1', 'user_id' => $userId]);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [['table' => 'users', 'on' => 'user_id']],
    ])
        ->assertOk()
        ->assertSee('Linus')
        ->assertDontSee('joined@example.com');
});
