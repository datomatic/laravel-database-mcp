<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\DatabaseMcp;
use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;

beforeEach(function (): void {
    config()->set('database-mcp.tenant', ['column' => 'tenant_id', 'shared_tables' => ['countries']]);

    DatabaseMcp::resolveTenantUsing(fn (Request $request): mixed => $request->user()?->tenant_id);

    $alpha = DB::table('projects')->insertGetId(['tenant_id' => 1, 'name' => 'Alpha project']);
    $beta = DB::table('projects')->insertGetId(['tenant_id' => 2, 'name' => 'Beta project']);

    DB::table('tasks')->insert([
        ['tenant_id' => 1, 'project_id' => $alpha, 'title' => 'Alpha task'],
        ['tenant_id' => 2, 'project_id' => $beta, 'title' => 'Beta task'],
        // Inconsistent row pointing at another tenant's project: a join must not surface it.
        ['tenant_id' => 2, 'project_id' => $alpha, 'title' => 'Foreign task'],
    ]);

    DB::table('countries')->insert(['name' => 'Italy']);
});

function actingAsTenant(int $tenant): void
{
    DatabaseServer::actingAs(new GenericUser(['id' => 1, 'tenant_id' => $tenant]));
}

it('only returns rows of the current tenant', function (): void {
    actingAsTenant(1);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'projects'])
        ->assertOk()
        ->assertSee('Alpha project')
        ->assertDontSee('Beta project');
});

it('scopes the rows to whichever tenant is resolved', function (): void {
    actingAsTenant(2);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'projects'])
        ->assertOk()
        ->assertSee('Beta project')
        ->assertDontSee('Alpha project');
});

it('cannot reach another tenant through filters', function (): void {
    actingAsTenant(1);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'projects',
        'filters' => [['column' => 'name', 'operator' => '=', 'value' => 'Beta project']],
    ])
        ->assertOk()
        ->assertSee('"count":0');
});

it('hides the tenant column', function (): void {
    actingAsTenant(1);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'projects'])
        ->assertOk()
        ->assertDontSee('tenant_id');

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'projects',
        'filters' => [['column' => 'tenant_id', 'operator' => '=', 'value' => 2]],
    ])->assertHasErrors(["Filter column 'tenant_id' does not exist or is not allowed."]);

    DatabaseServer::tool(DescribeDatabaseTool::class, ['table' => 'projects'])
        ->assertOk()
        ->assertDontSee('"name":"tenant_id"');
});

it('scopes joined tables to the current tenant', function (string $type): void {
    actingAsTenant(1);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'projects',
        'columns' => ['name'],
        'joins' => [['table' => 'tasks', 'type' => $type, 'columns' => ['title']]],
    ])
        ->assertOk()
        ->assertSee(['Alpha project', 'Alpha task'])
        ->assertDontSee(['Foreign task', 'Beta']);
})->with(['left', 'inner']);

it('scopes aggregates and totals to the current tenant', function (): void {
    actingAsTenant(2);

    DatabaseServer::tool(QueryDatabaseTool::class, [
        'table' => 'tasks',
        'aggregates' => [['function' => 'COUNT', 'column' => '*', 'alias' => 'tasks']],
    ])
        ->assertOk()
        ->assertSee('"tasks":2');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'tasks', 'page' => 1, 'with_total' => true])
        ->assertOk()
        ->assertSee('"total":2');
});

it('hides tables without the tenant column unless they are shared', function (): void {
    actingAsTenant(1);

    DatabaseServer::tool(DescribeDatabaseTool::class)
        ->assertOk()
        ->assertSee(['"table":"projects"', '"table":"tasks"', '"table":"countries"'])
        ->assertDontSee(['"table":"task_notes"', '"table":"users"']);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'task_notes'])
        ->assertHasErrors(["Table 'task_notes' is not available."]);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries'])
        ->assertOk()
        ->assertSee('Italy');
});

it('rejects the query when no tenant is resolved', function (): void {
    DatabaseServer::actingAs(new GenericUser(['id' => 1, 'tenant_id' => null]));

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'projects'])
        ->assertHasErrors(['No tenant could be resolved for this request.']);
});

it('rejects the query when no tenant resolver is registered', function (): void {
    DatabaseMcp::resolveTenantUsing(null);
    actingAsTenant(1);

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'countries'])
        ->assertHasErrors(['Tenant scoping is enabled but no tenant resolver is registered.']);
});

it('leaves queries unscoped when tenant scoping is off', function (): void {
    config()->set('database-mcp.tenant.column');

    DatabaseServer::tool(QueryDatabaseTool::class, ['table' => 'projects'])
        ->assertOk()
        ->assertSee(['Alpha project', 'Beta project', 'tenant_id']);
});
