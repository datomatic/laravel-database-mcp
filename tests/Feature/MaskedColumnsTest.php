<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('database-mcp.masked_columns', [
        'users.email' => ['strategy' => 'email', 'searchable' => true],
        'phone' => 'partial',
    ]);

    $this->marioId = DB::table('users')->insertGetId(['email' => 'mario@acme.it', 'firstname' => 'Mario', 'phone' => '3331234567', 'password' => 'x']);
    $this->luigiId = DB::table('users')->insertGetId(['email' => 'luigi@acme.it', 'firstname' => 'Luigi', 'phone' => null, 'password' => 'x']);

    DB::table('orders')->insert([
        ['code' => 'MARIO-1', 'total' => 10, 'user_id' => $this->marioId],
        ['code' => 'MARIO-2', 'total' => 20, 'user_id' => $this->marioId],
        ['code' => 'LUIGI-1', 'total' => 30, 'user_id' => $this->luigiId],
    ]);
});

it('masks values in the results', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'users', 'columns' => ['firstname', 'email', 'phone']])
        ->assertOk()
        ->assertSee(['Mario', 'm***@acme.it', '*******567'])
        ->assertDontSee(['mario@acme.it', '3331234567']);
});

it('keeps null masked values null', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['firstname', 'phone'],
        'filters' => [['column' => 'firstname', 'operator' => '=', 'value' => 'Luigi']],
    ])
        ->assertOk()
        ->assertSee('"phone":null');
});

it('masks values selected from a joined table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'joins' => [['table' => 'users', 'on' => 'user_id', 'columns' => ['email']]],
    ])
        ->assertOk()
        ->assertSee('"users.email":"m***@acme.it"')
        ->assertDontSee('mario@acme.it');
});

it('finds the orders of a customer by the exact value of a searchable masked email', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'joins' => [['table' => 'users', 'on' => 'user_id', 'columns' => ['email']]],
        'filters' => [['column' => 'users.email', 'operator' => '=', 'value' => 'mario@acme.it']],
    ])
        ->assertOk()
        ->assertSee(['MARIO-1', 'MARIO-2', 'm***@acme.it'])
        ->assertDontSee(['LUIGI-1', 'mario@acme.it']);
});

it('rejects pattern, list and range operators on a searchable masked column', function (string $operator, array $filter): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'filters' => [['column' => 'email', 'operator' => $operator, ...$filter]],
    ])->assertHasErrors(["Column 'email' is masked: filters may only use =, is_null, is_not_null."]);
})->with([
    'like' => ['like', ['value' => 'mario']],
    'starts_with' => ['starts_with', ['value' => 'm']],
    'ends_with' => ['ends_with', ['value' => '@acme.it']],
    'not_like' => ['not_like', ['value' => 'x']],
    '!=' => ['!=', ['value' => 'mario@acme.it']],
    '>' => ['>', ['value' => 'm']],
    'in' => ['in', ['values' => ['mario@acme.it', 'luigi@acme.it']]],
    'not_in' => ['not_in', ['values' => ['mario@acme.it']]],
    'between' => ['between', ['values' => ['a', 'n']]],
]);

it('rejects exact matches on a masked column that is not searchable', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'filters' => [['column' => 'phone', 'operator' => '=', 'value' => '3331234567']],
    ])->assertHasErrors(["Column 'phone' is masked: filters may only use is_null, is_not_null."]);
});

it('allows null checks on a masked column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'columns' => ['firstname'],
        'filters' => [['column' => 'phone', 'operator' => 'is_null']],
    ])
        ->assertOk()
        ->assertSee('Luigi')
        ->assertDontSee('Mario');
});

it('rejects sorting by a masked column', function (array $arguments, string $column): void {
    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'orders', ...$arguments])
        ->assertHasErrors(["Column '{$column}' is masked and cannot be used to sort."]);
})->with([
    'order_by on a joined table' => [['joins' => [['table' => 'users', 'on' => 'user_id']], 'order_by' => 'users.email'], 'users.email'],
    'sort on a joined table' => [['joins' => [['table' => 'users', 'on' => 'user_id']], 'sort' => [['column' => 'users.phone']]], 'users.phone'],
]);

it('rejects sorting a base table by a masked column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'users', 'order_by' => 'email'])
        ->assertHasErrors(["Column 'email' is masked and cannot be used to sort."]);
});

it('rejects grouping by a masked column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [['table' => 'users', 'on' => 'user_id']],
        'group_by' => ['users.email'],
        'aggregates' => [['function' => 'SUM', 'column' => 'total', 'alias' => 'spent']],
    ])->assertHasErrors(["Column 'users.email' is masked and cannot be used in group_by."]);
});

it('rejects aggregating a masked column with anything but COUNT', function (string $function): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'aggregates' => [['function' => $function, 'column' => 'email', 'alias' => 'value']],
    ])->assertHasErrors(["Column 'email' is masked: only COUNT can aggregate it."]);
})->with(['MIN', 'MAX', 'SUM', 'AVG']);

it('allows counting distinct values of a masked column', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'aggregates' => [['function' => 'COUNT', 'column' => 'phone', 'alias' => 'with_phone', 'distinct' => true]],
    ])
        ->assertOk()
        ->assertSee('"with_phone":1');
});

it('flags masked columns in describe_database', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'users'])
        ->assertOk()
        ->assertSee('"name":"email","type":"varchar","nullable":false,"default":null,"masked":"email","searchable":true')
        ->assertSee('"masked":"partial","searchable":false')
        ->assertDontSee('"name":"firstname","type":"varchar","nullable":true,"default":null,"masked"');
});

it('keeps a denied column denied even when it is also masked', function (): void {
    config()->set('database-mcp.masked_columns', ['password' => 'redact']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'users', 'columns' => ['password']])
        ->assertHasErrors(['One or more requested columns do not exist or are not allowed.']);
});

it('pseudonymises a hashed column consistently across rows', function (): void {
    config()->set('database-mcp.masked_columns', ['orders.user_id' => 'hash']);
    config()->set('database-mcp.mask_key', 'test-key');

    $token = substr(hash_hmac('sha256', (string) $this->marioId, 'test-key'), 0, 16);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code', 'user_id'],
        'filters' => [['column' => 'code', 'operator' => 'starts_with', 'value' => 'MARIO']],
    ])
        ->assertOk()
        ->assertSee('{"code":"MARIO-1","user_id":"'.$token.'"},{"code":"MARIO-2","user_id":"'.$token.'"}');
});
