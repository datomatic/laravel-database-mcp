<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;

it('lists allowed tables in the overview', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class)
        ->assertOk()
        ->assertSee('users')
        ->assertSee('orders');
});

it('omits denied tables from the overview', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class)
        ->assertOk()
        ->assertDontSee('password_resets');
});

it('describes a table without exposing denied columns', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'users'])
        ->assertOk()
        ->assertSee('email')
        ->assertSee('referenced_by')
        ->assertDontSee('"name":"password"')
        ->assertDontSee('"name":"remember_token"');
});

it('exposes outgoing relationships for a table with foreign keys', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'orders'])
        ->assertOk()
        ->assertSee('users.id');
});

it('blocks describing a denied table', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'password_resets'])
        ->assertHasErrors(['is not available']);
});

it('blocks describing a table that does not exist', function (): void {
    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'definitely_not_a_table'])
        ->assertHasErrors(['is not available']);
});
