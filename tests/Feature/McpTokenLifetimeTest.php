<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Bridge\McpAccessTokenRepository;
use Datomatic\LaravelDatabaseMcp\Bridge\McpRefreshTokenRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\RefreshToken;
use Laravel\Passport\Bridge\Scope;

beforeEach(fn () => Date::setTestNow('2026-07-20 12:00:00'));
afterEach(fn () => Date::setTestNow());

function makeMcpAccessToken(): AccessToken
{
    $token = new AccessToken(null, [new Scope('mcp:use')], new Client('client-id', 'Claude Code'));
    $token->setIdentifier(Str::random(80));
    $token->setExpiryDateTime(Date::now()->addDay()->toDateTimeImmutable());

    return $token;
}

it('overrides the expiry of mcp-scoped access tokens when a ttl is configured', function (): void {
    config(['database-mcp.oauth_token_ttl' => 5]);

    $token = makeMcpAccessToken();

    (new McpAccessTokenRepository(resolve(Dispatcher::class)))->persistNewAccessToken($token);

    expect($token->getExpiryDateTime()->getTimestamp())
        ->toBe(Date::now()->addMinutes(5)->getTimestamp());
});

it('leaves the expiry of non-mcp access tokens untouched when a ttl is configured', function (): void {
    config(['database-mcp.oauth_token_ttl' => 5]);

    $token = new AccessToken(null, [new Scope('other:scope')], new Client('client-id', 'Some App'));
    $token->setIdentifier(Str::random(80));

    $expiry = Date::now()->addDay()->toDateTimeImmutable();
    $token->setExpiryDateTime($expiry);

    (new McpAccessTokenRepository(resolve(Dispatcher::class)))->persistNewAccessToken($token);

    expect($token->getExpiryDateTime())->toEqual($expiry);
});

it('does not override the expiry when no ttl is configured', function (): void {
    config(['database-mcp.oauth_token_ttl' => null]);

    $token = makeMcpAccessToken();
    $expiry = $token->getExpiryDateTime();

    (new McpAccessTokenRepository(resolve(Dispatcher::class)))->persistNewAccessToken($token);

    expect($token->getExpiryDateTime())->toEqual($expiry);
});

it('overrides the expiry of refresh tokens tied to an mcp-scoped access token', function (): void {
    config(['database-mcp.oauth_refresh_token_ttl' => 10]);

    $refreshToken = new RefreshToken;
    $refreshToken->setIdentifier(Str::random(80));
    $refreshToken->setAccessToken(makeMcpAccessToken());
    $refreshToken->setExpiryDateTime(Date::now()->addWeek()->toDateTimeImmutable());

    (new McpRefreshTokenRepository(resolve(Dispatcher::class)))->persistNewRefreshToken($refreshToken);

    expect($refreshToken->getExpiryDateTime()->getTimestamp())
        ->toBe(Date::now()->addMinutes(10)->getTimestamp());
});

it('defaults both ttl config keys to null', function (): void {
    expect(config('database-mcp.oauth_token_ttl'))->toBeNull()
        ->and(config('database-mcp.oauth_refresh_token_ttl'))->toBeNull();
});
