<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

it('returns rows from an allowed table', function (): void {
    DB::table('users')->insert(['email' => 'caveman@example.com', 'password' => 'secret']);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['id', 'email'],
    ])
        ->assertOk()
        ->assertSee('caveman@example.com');
});

it('strips denied columns even when selecting all columns', function (): void {
    DB::table('users')->insert(['email' => 'secret@example.com', 'password' => 'super-secret']);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
    ])
        ->assertOk()
        ->assertSee('secret@example.com')
        ->assertDontSee('super-secret')
        ->assertDontSee('remember_token');
});

it('rejects explicitly requesting a denied column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['id', 'password'],
    ])->assertHasErrors(['do not exist or are not allowed']);
});

it('blocks querying a denied table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'password_resets',
    ])->assertHasErrors(['is not available']);
});

it('blocks querying a table that does not exist', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'definitely_not_a_table',
    ])->assertHasErrors(['is not available']);
});

it('applies filters with bound values', function (): void {
    DB::table('users')->insert(['email' => 'match@example.com', 'password' => 'x']);
    DB::table('users')->insert(['email' => 'other@example.com', 'password' => 'x']);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['email'],
        'filters' => [
            ['column' => 'email', 'operator' => '=', 'value' => 'match@example.com'],
        ],
    ])
        ->assertOk()
        ->assertSee('match@example.com')
        ->assertDontSee('other@example.com');
});

it('rejects a filter on an unknown column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'filters' => [
            ['column' => 'not_a_column', 'operator' => '=', 'value' => 'x'],
        ],
    ])->assertHasErrors(['does not exist or is not allowed']);
});

it('rejects an operator outside the allowed list', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'filters' => [
            ['column' => 'email', 'operator' => 'sleep', 'value' => 'x'],
        ],
    ])->assertHasErrors();
});

it('rejects a limit above the maximum', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'limit' => 5000,
    ])->assertHasErrors();
});

it('rejects ordering by an unknown column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'order_by' => 'not_a_column',
    ])->assertHasErrors(['does not exist or is not allowed']);
});
