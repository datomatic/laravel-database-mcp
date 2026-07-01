<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Support\Facades\DB;

it('joins a related table through a disambiguated foreign key', function (): void {
    $userId = DB::table('users')->insertGetId(['email' => 'buyer@example.com', 'password' => 'x']);
    DB::table('orders')->insert(['code' => 'ORDER-1', 'user_id' => $userId]);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'joins' => [
            ['table' => 'users', 'on' => 'user_id', 'columns' => ['email']],
        ],
    ])
        ->assertOk()
        ->assertSee('ORDER-1')
        ->assertSee('buyer@example.com')
        ->assertSee('users.email');
});

it('errors when the relationship is ambiguous and no column is given', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [
            ['table' => 'users'],
        ],
    ])->assertHasErrors(['Multiple relationships']);
});

it('errors when the on column is not a real foreign key', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [
            ['table' => 'users', 'on' => 'not_a_fk'],
        ],
    ])->assertHasErrors(['is not a foreign key']);
});

it('errors when there is no relationship between the tables', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'users',
        'joins' => [
            ['table' => 'countries'],
        ],
    ])->assertHasErrors(['No foreign key relationship']);
});

it('blocks joining a denied table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [
            ['table' => 'password_resets'],
        ],
    ])->assertHasErrors(['Cannot join table']);
});

it('rejects a denied column requested on a joined table', function (): void {
    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'joins' => [
            ['table' => 'users', 'on' => 'user_id', 'columns' => ['password']],
        ],
    ])->assertHasErrors(['do not exist or are not allowed']);
});

it('joins a single-relationship table without specifying on', function (): void {
    $countryId = DB::table('countries')->insertGetId(['name' => 'Italy']);
    DB::table('orders')->insert(['code' => 'ORDER-IT', 'country_id' => $countryId]);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'orders',
        'columns' => ['code'],
        'joins' => [
            ['table' => 'countries', 'columns' => ['name']],
        ],
    ])
        ->assertOk()
        ->assertSee('ORDER-IT')
        ->assertSee('Italy');
});
