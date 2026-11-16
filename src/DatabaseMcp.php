<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp;

use Closure;
use Laravel\Mcp\Request;

use function is_int;
use function is_string;

final class DatabaseMcp
{
    /**
     * @var (Closure(Request): (int|string|null))|null
     */
    private static ?Closure $tenantResolver = null;

    /**
     * Register how the current tenant is resolved for a tool call. Kept out
     * of the config file because closures cannot be cached by config:cache.
     *
     * @param  (Closure(Request): (int|string|null))|null  $resolver
     */
    public static function resolveTenantUsing(?Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    public static function hasTenantResolver(): bool
    {
        return self::$tenantResolver instanceof Closure;
    }

    public static function resolveTenant(Request $request): int|string|null
    {
        if (! self::$tenantResolver instanceof Closure) {
            return null;
        }

        $tenant = (self::$tenantResolver)($request);

        return is_int($tenant) || (is_string($tenant) && $tenant !== '') ? $tenant : null;
    }
}
