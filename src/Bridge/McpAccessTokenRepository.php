<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Bridge;

use Carbon\CarbonImmutable;
use Laravel\Passport\Bridge\AccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

class McpAccessTokenRepository extends AccessTokenRepository
{
    /**
     * Shorten/lengthen the expiry of MCP-scoped access tokens (those issued
     * through Mcp::oauthRoutes(), carrying the "mcp:use" scope) before they
     * are persisted, without touching Passport's app-wide token lifetime.
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $ttl = config('database-mcp.oauth_token_ttl');

        if ($ttl !== null && $this->hasMcpScope($accessTokenEntity)) {
            $accessTokenEntity->setExpiryDateTime(CarbonImmutable::now()->addMinutes((int) $ttl));
        }

        parent::persistNewAccessToken($accessTokenEntity);
    }

    private function hasMcpScope(AccessTokenEntityInterface $accessTokenEntity): bool
    {
        foreach ($accessTokenEntity->getScopes() as $scope) {
            if ($scope->getIdentifier() === 'mcp:use') {
                return true;
            }
        }

        return false;
    }
}
