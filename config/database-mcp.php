<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection the tools read from. Point it at a database user with
    | SELECT-only privileges so the assistant can never write data. When null
    | the application's default connection is used.
    |
    */

    'connection' => env('DATABASE_MCP_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Route Registration
    |--------------------------------------------------------------------------
    |
    | When enabled the package registers the MCP server over HTTP at the given
    | path with the given middleware. Set "register_route" to false to wire the
    | server up yourself in routes/ai.php.
    |
    | The default guard is Laravel Sanctum. If you authenticate the endpoint
    | with a different guard (e.g. Passport's "auth:api"), override this.
    |
    */

    'register_route' => env('DATABASE_MCP_REGISTER_ROUTE', true),

    'path' => env('DATABASE_MCP_PATH', 'database-mcp'),

    'middleware' => ['auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | Authorization Gate
    |--------------------------------------------------------------------------
    |
    | The ability checked before the server can be reached (applied as a
    | "can:" middleware). Define this gate in your own service provider to
    | decide who may access it. If the gate is left undefined the package
    | falls back to allowing local environments only. Set to null to disable
    | the gate check entirely.
    |
    */

    'gate' => 'access-database-mcp',

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    |
    | Name and instructions advertised to MCP clients. Set a project-specific
    | name so the same package reused across projects stays distinguishable.
    |
    */

    'name' => env('MCP_DATABASE_NAME', env('APP_NAME', 'Laravel') . ' Database'),

    'instructions' => env('MCP_DATABASE_INSTRUCTIONS', <<<'MARKDOWN'
        Read-only access to the application database. Use `describe_database` to discover
        tables, columns and relationships, then `query_database` to read rows (with joins,
        filters and ordering). Sensitive tables and columns are hidden.
        MARKDOWN),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_limit' => env('DATABASE_MCP_MAX_LIMIT', 100),

    /*
    |--------------------------------------------------------------------------
    | Denied Tables
    |--------------------------------------------------------------------------
    |
    | Tables that are never listed, described or queried.
    |
    */

    'denied_tables' => [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_device_codes',
        'oauth_refresh_tokens',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'sessions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Denied Columns
    |--------------------------------------------------------------------------
    |
    | Columns stripped from every result and description, on any table.
    |
    */

    'denied_columns' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
    ],

];
