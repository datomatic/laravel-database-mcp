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
    | OAuth Client Deduplication
    |--------------------------------------------------------------------------
    |
    | MCP clients doing OAuth (via Mcp::oauthRoutes()) register a new Passport
    | client on every connection, since the loopback redirect URI they use
    | changes each run even though the client name stays the same. When
    | enabled, this package reuses the existing Passport client for a given
    | name instead of creating a new one, updating its redirect URI in place.
    | Requires Laravel Passport; ignored otherwise.
    |
    */

    'dedupe_oauth_clients' => env('DATABASE_MCP_DEDUPE_OAUTH_CLIENTS', true),

    /*
    |--------------------------------------------------------------------------
    | OAuth Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | Passport's Passport::tokensExpireIn()/refreshTokensExpireIn() are
    | app-wide and affect every Passport client, not just MCP clients. Set
    | these (in minutes) to override the access/refresh token lifetime only
    | for tokens issued with the "mcp:use" scope, i.e. tokens obtained
    | through Mcp::oauthRoutes(). Leave null to keep Passport's app-wide
    | lifetime. Requires Laravel Passport; ignored otherwise.
    |
    */

    'oauth_token_ttl' => env('DATABASE_MCP_OAUTH_TOKEN_TTL'),

    'oauth_refresh_token_ttl' => env('DATABASE_MCP_OAUTH_REFRESH_TOKEN_TTL'),

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    |
    | Name and instructions advertised to MCP clients. Set a project-specific
    | name so the same package reused across projects stays distinguishable.
    |
    */

    'name' => env('MCP_DATABASE_NAME', env('APP_NAME', 'Laravel').' Database'),

    'instructions' => env('MCP_DATABASE_INSTRUCTIONS', <<<'MARKDOWN'
        Read-only access to the application database. Use `describe_database` to discover
        tables, columns and relationships, then `query_database` to read rows (with joins,
        filters and ordering). Sensitive tables and columns are hidden. Columns flagged as
        "masked" return anonymised values: they cannot be sorted, grouped or pattern-matched,
        and "searchable" ones can only be looked up by their full exact value.
        MARKDOWN),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | "max_limit" caps the rows returned per query. "query_timeout" caps, in
    | seconds, how long a query may run (MySQL, MariaDB and PostgreSQL; not
    | supported on SQLite or SQL Server). Set it to null to disable.
    |
    */

    'max_limit' => env('DATABASE_MCP_MAX_LIMIT', 100),

    'query_timeout' => env('DATABASE_MCP_QUERY_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Aggregate Functions
    |--------------------------------------------------------------------------
    |
    | The aggregate functions the query tool is allowed to build.
    |
    */

    'aggregate_functions' => ['SUM', 'COUNT', 'MIN', 'MAX', 'AVG'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Tables
    |--------------------------------------------------------------------------
    |
    | When set to a list, ONLY matching tables are exposed (an allowlist), and
    | the deny list below still applies on top. Leave null to expose every
    | table that is not denied. Entries accept "*" wildcards ("report_*").
    |
    */

    'allowed_tables' => null,

    /*
    |--------------------------------------------------------------------------
    | Views
    |--------------------------------------------------------------------------
    |
    | Expose database views alongside tables. Views are a convenient way to
    | hand the assistant pre-joined or pre-aggregated data. Allowed/denied
    | table lists apply to views too.
    |
    */

    'include_views' => env('DATABASE_MCP_INCLUDE_VIEWS', false),

    /*
    |--------------------------------------------------------------------------
    | Denied Tables
    |--------------------------------------------------------------------------
    |
    | Tables that are never listed, described or queried. Entries accept "*"
    | wildcards ("telescope_*").
    |
    */

    'denied_tables' => [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'notifications',
        'oauth_*',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'pulse_*',
        'sessions',
        'telescope_*',
        'webhook_calls',
    ],

    /*
    |--------------------------------------------------------------------------
    | Denied Columns
    |--------------------------------------------------------------------------
    |
    | Columns stripped from every result and description. A bare entry applies
    | to every table ("password"); a "table.column" entry applies only to
    | matching tables ("users.email"). Both parts accept "*" wildcards
    | ("*_token", "customers.*_iban").
    |
    */

    'denied_columns' => [
        'password',
        'two_factor_recovery_codes',
        '*_secret',
        '*_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Masked Columns
    |--------------------------------------------------------------------------
    |
    | Columns whose values are anonymised in every result instead of being
    | hidden. Keys use the same patterns as "denied_columns" ("phone",
    | "users.email", "customers.*_iban"); the first match wins and a denied
    | column stays denied. The value is a strategy name, or an array with:
    |
    |   strategy    "redact"  -> ***
    |               "partial" -> *******123 (last "visible" chars, default 4)
    |               "email"   -> m***@acme.com
    |               "hash"    -> stable pseudonym (HMAC with "mask_key")
    |   searchable  allow "=" with the full exact value, so rows can be looked
    |               up by a value the user already knows (default false)
    |   visible     characters kept by "partial" (default 4)
    |
    | Masked columns can never be sorted, grouped, pattern-matched or
    | aggregated except with COUNT, so their values cannot be reconstructed.
    |
    */

    'masked_columns' => [
        // 'users.email' => ['strategy' => 'email', 'searchable' => true],
        // 'phone' => 'partial',
        // 'customers.tax_code' => 'hash',
    ],

    /*
    | Key for the "hash" strategy. Defaults to APP_KEY. Set a dedicated key to
    | keep pseudonyms stable across APP_KEY rotations.
    */

    'mask_key' => env('DATABASE_MCP_MASK_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Audit Log
    |--------------------------------------------------------------------------
    |
    | When enabled every tool call is logged with the authenticated user, the
    | arguments, the outcome and the duration. "channel" picks a logging
    | channel from config/logging.php (null = the default channel).
    |
    */

    'audit_log' => [
        'enabled' => env('DATABASE_MCP_AUDIT_LOG', false),
        'channel' => env('DATABASE_MCP_AUDIT_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Descriptions
    |--------------------------------------------------------------------------
    |
    | Business meaning attached to specific tables. When set the text is added
    | to the "describe_database" output so the assistant knows, per project,
    | what a table represents and where to look. This complements the global
    | "instructions" above without replacing it.
    |
    | Example:
    |     'invoices' => 'Issued invoices. Revenue = SUM(total_amount) where status = paid.',
    |
    */

    'table_descriptions' => [
        // 'orders' => 'Customer orders. grand_total is tax-inclusive, stored in cents.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Descriptions
    |--------------------------------------------------------------------------
    |
    | Business meaning attached to specific columns, keyed by "table.column".
    | Added to the per-column output of "describe_database".
    |
    | Example:
    |     'orders.grand_total' => 'Total in cents, tax included.',
    |
    */

    'column_descriptions' => [
        // 'orders.grand_total' => 'Total in cents, tax included.',
    ],

];
