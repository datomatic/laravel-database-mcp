<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Http\Controllers\DedupedOAuthRegisterController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;

it('binds the deduping controller by default', function (): void {
    expect(resolve(OAuthRegisterController::class))->toBeInstanceOf(DedupedOAuthRegisterController::class);
});

it('defaults dedupe_oauth_clients to true', function (): void {
    expect(config('database-mcp.dedupe_oauth_clients'))->toBeTrue();
});

it('reuses an existing client with the same name instead of creating a new one', function (): void {
    $controller = resolve(OAuthRegisterController::class);

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
    $controller = resolve(OAuthRegisterController::class);

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

it('grants the mcp:use scope to new and reused clients when the scopes column exists', function (): void {
    Schema::table('oauth_clients', function (Blueprint $table): void {
        $table->text('scopes')->nullable();
    });

    $controller = resolve(OAuthRegisterController::class);

    $controller(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:51000/callback'],
    ]));

    Client::sole()->forceFill(['scopes' => []])->save();

    $controller(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:52000/callback'],
    ]));

    expect(Client::sole()->scopes)->toBe([Registrar::OAUTH_SCOPE]);
});

it('persists client metadata when the columns exist', function (): void {
    Schema::table('oauth_clients', function (Blueprint $table): void {
        $table->string('logo_uri', 2048)->nullable();
        $table->string('client_uri', 2048)->nullable();
    });

    $response = resolve(OAuthRegisterController::class)(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:51000/callback'],
        'logo_uri' => 'https://claude.ai/logo.png',
        'client_uri' => 'https://claude.ai',
    ]));

    expect($response->getData()->logo_uri)->toBe('https://claude.ai/logo.png')
        ->and($response->getData()->client_uri)->toBe('https://claude.ai')
        ->and(Client::sole()->logo_uri)->toBe('https://claude.ai/logo.png');
});

it('rejects an invalid redirect uri', function (): void {
    $response = resolve(OAuthRegisterController::class)(Request::create('/', 'POST', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['not-a-url'],
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData()->error)->toBe('invalid_redirect_uri')
        ->and(Client::count())->toBe(0);
});
