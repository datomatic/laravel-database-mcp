<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    foreach (\range(1, 5) as $i) {
        DB::table('users')->insert(['email' => "user{$i}@example.com", 'password' => 'x']);
    }
});

it('returns the first page', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['email'],
        'order_by' => 'id',
        'page' => 1,
        'per_page' => 2,
    ])
        ->assertOk()
        ->assertSee('user1@example.com')
        ->assertSee('user2@example.com')
        ->assertDontSee('user3@example.com')
        ->assertSee('"page":1')
        ->assertSee('"per_page":2');
});

it('returns the second page', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['email'],
        'order_by' => 'id',
        'page' => 2,
        'per_page' => 2,
    ])
        ->assertOk()
        ->assertSee('user3@example.com')
        ->assertSee('user4@example.com')
        ->assertDontSee('user1@example.com')
        ->assertSee('"page":2');
});

it('includes the total when requested', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['email'],
        'page' => 1,
        'per_page' => 2,
        'with_total' => true,
    ])
        ->assertOk()
        ->assertSee('"total":5')
        ->assertSee('"last_page":3');
});

it('omits the total when not requested', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['email'],
        'page' => 1,
        'per_page' => 2,
    ])
        ->assertOk()
        ->assertDontSee('"total"');
});

it('rejects a per_page above the maximum', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'per_page' => 5000,
    ])->assertHasErrors();
});
