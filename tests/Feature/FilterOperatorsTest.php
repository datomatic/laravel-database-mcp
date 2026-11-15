<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Testing\TestResponse;

beforeEach(function (): void {
    $userId = DB::table('users')->insertGetId(['email' => 'owner@example.com', 'firstname' => 'Owner', 'password' => 'x']);

    DB::table('orders')->insert([
        ['code' => 'ORD-100', 'total' => 10, 'user_id' => $userId],
        ['code' => 'ORD-200', 'total' => 20, 'user_id' => null],
        ['code' => 'INV-300', 'total' => 30, 'user_id' => $userId],
    ]);
});

function filterOrders(array $filter): TestResponse
{
    return DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'filters' => [$filter],
    ]);
}

it('filters with in', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'in', 'values' => ['ORD-100', 'INV-300']])
        ->assertOk()
        ->assertSee(['ORD-100', 'INV-300'])
        ->assertDontSee('ORD-200');
});

it('filters with not_in', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'not_in', 'values' => ['ORD-100']])
        ->assertOk()
        ->assertSee(['ORD-200', 'INV-300'])
        ->assertDontSee('ORD-100');
});

it('filters with between', function (): void {
    filterOrders(['column' => 'total', 'operator' => 'between', 'values' => [15, 35]])
        ->assertOk()
        ->assertSee(['ORD-200', 'INV-300'])
        ->assertDontSee('ORD-100');
});

it('filters with is_null without a value', function (): void {
    filterOrders(['column' => 'user_id', 'operator' => 'is_null'])
        ->assertOk()
        ->assertSee('ORD-200')
        ->assertDontSee(['ORD-100', 'INV-300']);
});

it('filters with is_not_null', function (): void {
    filterOrders(['column' => 'user_id', 'operator' => 'is_not_null'])
        ->assertOk()
        ->assertSee(['ORD-100', 'INV-300'])
        ->assertDontSee('ORD-200');
});

it('filters with starts_with', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'starts_with', 'value' => 'ORD'])
        ->assertOk()
        ->assertSee(['ORD-100', 'ORD-200'])
        ->assertDontSee('INV-300');
});

it('filters with ends_with', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'ends_with', 'value' => '00'])
        ->assertOk()
        ->assertSee(['ORD-100', 'ORD-200', 'INV-300']);

    filterOrders(['column' => 'code', 'operator' => 'ends_with', 'value' => '-300'])
        ->assertOk()
        ->assertSee('INV-300')
        ->assertDontSee(['ORD-100', 'ORD-200']);
});

it('filters with not_like', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'not_like', 'value' => 'ORD'])
        ->assertOk()
        ->assertSee('INV-300')
        ->assertDontSee(['ORD-100', 'ORD-200']);
});

it('rejects between without exactly two values', function (): void {
    filterOrders(['column' => 'total', 'operator' => 'between', 'values' => [10]])
        ->assertHasErrors(["Filter 'total' with 'between' needs exactly two 'values': [min, max]."]);
});

it('rejects in with an empty values list', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'in', 'values' => []])
        ->assertHasErrors(["Filter 'code' with 'in' needs a non-empty 'values' list."]);
});

it('rejects non-scalar values in a list filter', function (): void {
    filterOrders(['column' => 'code', 'operator' => 'in', 'values' => [['nested']]])
        ->assertHasErrors(["Filter 'code' values must be scalars."]);
});

it('rejects a comparison filter without a value', function (): void {
    filterOrders(['column' => 'code', 'operator' => '='])
        ->assertHasErrors(["Filter 'code' with '=' needs a 'value'."]);
});

it('filters on a joined table column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'joins' => [['table' => 'users', 'on' => 'user_id', 'columns' => ['firstname']]],
        'filters' => [['column' => 'users.firstname', 'operator' => '=', 'value' => 'Owner']],
    ])
        ->assertOk()
        ->assertSee(['ORD-100', 'INV-300'])
        ->assertDontSee('ORD-200');
});

it('rejects filtering on a column of a table that is not joined', function (): void {
    filterOrders(['column' => 'users.firstname', 'operator' => '=', 'value' => 'Owner'])
        ->assertHasErrors(["Filter column 'users.firstname' does not exist or is not allowed."]);
});

it('sorts by several columns', function (): void {
    DB::table('orders')->insert(['code' => 'ORD-050', 'total' => 20]);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'sort' => [
            ['column' => 'total', 'direction' => 'desc'],
            ['column' => 'code'],
        ],
    ])
        ->assertOk()
        ->assertSee('"rows":[{"code":"INV-300"},{"code":"ORD-050"},{"code":"ORD-200"},{"code":"ORD-100"}]');
});

it('sorts by a joined table column', function (): void {
    $otherId = DB::table('users')->insertGetId(['email' => 'aaa@example.com', 'firstname' => 'Aaron', 'password' => 'x']);
    DB::table('orders')->insert(['code' => 'ORD-AAA', 'total' => 5, 'user_id' => $otherId]);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'joins' => [['table' => 'users', 'on' => 'user_id', 'type' => 'inner', 'columns' => []]],
        'filters' => [['column' => 'code', 'operator' => 'in', 'values' => ['ORD-AAA', 'ORD-100']]],
        'order_by' => 'users.firstname',
    ])
        ->assertOk()
        ->assertSee('{"orders.code":"ORD-AAA"');
});

it('rejects combining order_by and sort', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'order_by' => 'code',
        'sort' => [['column' => 'total']],
    ])->assertHasErrors(["Pass either 'order_by' or 'sort', not both."]);
});
