<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Http\Controllers\DedupedOAuthRegisterController;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Passport\Client;

it('binds the deduping controller by default', function (): void {
    expect(app(OAuthRegisterController::class))->toBeInstanceOf(DedupedOAuthRegisterController::class);
});

it('defaults dedupe_oauth_clients to true', function (): void {
    expect(config('database-mcp.dedupe_oauth_clients'))->toBeTrue();
});

it('reuses an existing client with the same name instead of creating a new one', function (): void {
    $controller = app(OAuthRegisterController::class);

    $first = $controller(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:51000/callback'],
    ]));

    $second = $controller(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:52000/callback'],
    ]));

    expect(Client::count())->toBe(1)
        ->and($first->getData()->client_id)->toBe($second->getData()->client_id);

    expect(Client::sole()->redirect_uris)->toBe(['http://127.0.0.1:52000/callback']);
});

it('creates separate clients for different names', function (): void {
    $controller = app(OAuthRegisterController::class);

    $controller(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:51000/callback'],
    ]));

    $controller(Request::create('/', 'POST', [
        'client_name' => 'Cursor',
        'redirect_uris' => ['http://127.0.0.1:51000/callback'],
    ]));

    expect(Client::count())->toBe(2);
});
