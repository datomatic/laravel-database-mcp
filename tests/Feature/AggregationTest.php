<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::table('countries')->insert([
        ['id' => 1, 'name' => 'Italy'],
        ['id' => 2, 'name' => 'France'],
    ]);

    DB::table('orders')->insert([
        ['code' => 'A', 'total' => 10, 'country_id' => 1],
        ['code' => 'B', 'total' => 20, 'country_id' => 1],
        ['code' => 'C', 'total' => 5, 'country_id' => 2],
    ]);
});

it('groups and counts rows', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'group_by' => ['country_id'],
        'aggregates' => [
            ['function' => 'COUNT', 'column' => '*', 'alias' => 'orders_count'],
            ['function' => 'SUM', 'column' => 'total', 'alias' => 'total_sum'],
        ],
        'order_by' => 'country_id',
    ])
        ->assertOk()
        ->assertSee('orders_count')
        ->assertSee('total_sum')
        ->assertSee('"orders_count":2')
        ->assertSee('"orders_count":1');
});

it('supports a global aggregate with distinct', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'aggregates' => [
            ['function' => 'COUNT', 'column' => 'country_id', 'alias' => 'distinct_countries', 'distinct' => true],
        ],
    ])
        ->assertOk()
        ->assertSee('"distinct_countries":2');
});

it('filters groups with having on an aggregate alias', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'group_by' => ['country_id'],
        'aggregates' => [
            ['function' => 'COUNT', 'column' => '*', 'alias' => 'orders_count'],
        ],
        'having' => [
            ['target' => 'orders_count', 'operator' => '>', 'value' => '1'],
        ],
    ])
        ->assertOk()
        ->assertSee('"count":1');
});

it('aggregates over a joined table column', function (): void {
    $userId = DB::table('users')->insertGetId(['email' => 'buyer@example.com', 'password' => 'x']);
    DB::table('orders')->where('code', 'A')->update(['user_id' => $userId]);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [
            ['table' => 'users', 'on' => 'user_id'],
        ],
        'group_by' => ['users.email'],
        'aggregates' => [
            ['function' => 'SUM', 'column' => 'orders.total', 'alias' => 'total_sum'],
        ],
    ])
        ->assertOk()
        ->assertSee('buyer@example.com')
        ->assertSee('total_sum');
});

it('orders by an aggregate alias', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'group_by' => ['country_id'],
        'aggregates' => [
            ['function' => 'SUM', 'column' => 'total', 'alias' => 'total_sum'],
        ],
        'order_by' => 'total_sum',
        'order_direction' => 'desc',
    ])->assertOk();
});

it('rejects an aggregate function outside the whitelist', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'aggregates' => [
            ['function' => 'MEDIAN', 'column' => 'total', 'alias' => 'x'],
        ],
    ])->assertHasErrors(['is not allowed']);
});

it('rejects star with a non-count function', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'aggregates' => [
            ['function' => 'SUM', 'column' => '*', 'alias' => 'x'],
        ],
    ])->assertHasErrors(['can only be used with COUNT']);
});

it('rejects an unknown aggregate column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'aggregates' => [
            ['function' => 'SUM', 'column' => 'not_a_column', 'alias' => 'x'],
        ],
    ])->assertHasErrors(['does not exist or is not allowed']);
});

it('rejects an illegal aggregate alias', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'aggregates' => [
            ['function' => 'SUM', 'column' => 'total', 'alias' => 'bad alias!'],
        ],
    ])->assertHasErrors();
});

it('rejects columns passed together with aggregates', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'aggregates' => [
            ['function' => 'COUNT', 'column' => '*', 'alias' => 'c'],
        ],
    ])->assertHasErrors(["Do not pass 'columns'"]);
});

it('rejects having without aggregates', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'having' => [
            ['target' => 'anything', 'operator' => '>', 'value' => '1'],
        ],
    ])->assertHasErrors(["can only be used together with 'aggregates'"]);
});

it('rejects a having target that is neither an alias nor a group column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'group_by' => ['country_id'],
        'aggregates' => [
            ['function' => 'COUNT', 'column' => '*', 'alias' => 'orders_count'],
        ],
        'having' => [
            ['target' => 'not_a_thing', 'operator' => '>', 'value' => '1'],
        ],
    ])->assertHasErrors(['must be an aggregate alias']);
});

it('rejects grouping by an unknown column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'group_by' => ['not_a_column'],
        'aggregates' => [
            ['function' => 'COUNT', 'column' => '*', 'alias' => 'c'],
        ],
    ])->assertHasErrors(['does not exist or is not allowed']);
});
