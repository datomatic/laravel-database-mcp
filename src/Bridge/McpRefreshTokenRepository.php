<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Bridge;

use Carbon\CarbonImmutable;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

class McpRefreshTokenRepository extends RefreshTokenRepository
{
    /**
     * Shorten/lengthen the expiry of refresh tokens tied to an MCP-scoped
     * access token (see McpAccessTokenRepository) before they are persisted,
     * without touching Passport's app-wide refresh token lifetime.
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $ttl = config('database-mcp.oauth_refresh_token_ttl');

        if ($ttl !== null && $this->hasMcpScope($refreshTokenEntity)) {
            $refreshTokenEntity->setExpiryDateTime(CarbonImmutable::now()->addMinutes((int) $ttl));
        }

        parent::persistNewRefreshToken($refreshTokenEntity);
    }

    private function hasMcpScope(RefreshTokenEntityInterface $refreshTokenEntity): bool
    {
        foreach ($refreshTokenEntity->getAccessToken()->getScopes() as $scope) {
            if ($scope->getIdentifier() === 'mcp:use') {
                return true;
            }
        }

        return false;
    }
}
