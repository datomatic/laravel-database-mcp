# Upgrade Guide

## Upgrading from 0.2 to 1.0

Estimated time: 10 minutes.

1.0 keeps the tool API backward compatible: every `describe_database` / `query_database` call that
worked on 0.2 still works. Most of the work concerns requirements and, if you published it, your
config file.

### 1. Requirements (high impact)

| | 0.2 | 1.0 |
|---|---|---|
| PHP | 8.3+ | 8.3+ |
| Laravel | 11, 12, 13 | **12.41.1+**, 13 |
| `laravel/mcp` | ^0.9.5, ^1.0 | **^1.0** |

Laravel 11 is end of life and is no longer supported. If you are on Laravel 11 or on
`laravel/mcp` 0.x, upgrade them first (or together):

```bash
composer require datomatic/laravel-database-mcp:^1.0 -W
```

### 2. Published config (high impact)

Skip this section if you never ran `vendor:publish --tag=database-mcp-config`: you get every new
default automatically.

If you did publish `config/database-mcp.php`, Laravel merges it with the package config **key by
key**. That means:

- **New keys** (`query_timeout`, `allowed_tables`, `include_views`, `audit_log`) are picked up from
  the package automatically, with their defaults. Add them to your file only if you want to change
  them or keep them visible.
- **`denied_tables` and `denied_columns`** in your file fully replace the package lists, so you do
  **not** get the new defaults. Update them by hand.

Compare your lists with the new defaults and merge in what you are missing:

```php
'denied_tables' => [
    'cache',
    'cache_locks',
    'failed_jobs',
    'job_batches',
    'jobs',
    'migrations',
    'notifications',      // new
    'oauth_*',            // replaces the individual oauth_* tables
    'password_reset_tokens',
    'password_resets',
    'personal_access_tokens',
    'pulse_*',            // new
    'sessions',
    'telescope_*',        // new
    'webhook_calls',      // new
],

'denied_columns' => [
    'password',
    'two_factor_recovery_codes',
    '*_secret',           // replaces two_factor_secret, and covers any other *_secret
    '*_token',            // replaces remember_token and api_token, and covers any other *_token
],
```

Both lists now accept `*` wildcards, and `denied_columns` accepts `table.column` entries to hide a
column on specific tables only (`users.email`). See
[Restricting tables and columns](README.md#restricting-tables-and-columns).

Optionally add the new options to your file:

```php
'query_timeout' => env('DATABASE_MCP_QUERY_TIMEOUT', 10),

'allowed_tables' => null,

'include_views' => env('DATABASE_MCP_INCLUDE_VIEWS', false),

'masked_columns' => [],

'mask_key' => env('DATABASE_MCP_MASK_KEY'),

'audit_log' => [
    'enabled' => env('DATABASE_MCP_AUDIT_LOG', false),
    'channel' => env('DATABASE_MCP_AUDIT_LOG_CHANNEL'),
],
```

### 3. Behaviour changes (medium impact)

**More columns are hidden by default.** The new `*_token` and `*_secret` patterns hide every column
ending that way, on every table (e.g. `stripe_token`, `webhook_secret`). If the assistant needs
one of them, override `denied_columns` without the wildcard and list the exact columns to hide
instead.

**More tables are hidden by default.** `notifications`, `webhook_calls`, `telescope_*` and
`pulse_*` are now denied. If the assistant relied on one of them, override `denied_tables` and
remove it.

**Queries time out after 10 seconds** on MySQL, MariaDB and PostgreSQL. Queries that legitimately
take longer now fail. Raise the limit with `DATABASE_MCP_QUERY_TIMEOUT`, or set it to `0` to
disable it. SQLite and SQL Server are not affected.

**Table names must match exactly.** A table is accepted only when its name matches, case included,
a table in the connection's schema. Schema-qualified names (`main.users`, `otherdb.users`) and
differently-cased names (`Users`) are now rejected. Assistants use the names returned by
`describe_database`, so normally nothing changes. This also fixes a deny-list bypass through
schema-qualified names (see [CHANGELOG](CHANGELOG.md)).

**PostgreSQL and SQLite: only the connection's own schema is exposed.** Tables outside the
`search_path` (PostgreSQL) or attached databases (SQLite) are no longer listed. MySQL and MariaDB
already behaved this way.

### 4. New features (no action needed)

Available as soon as you upgrade. MCP clients pick up the new tool parameters the next time they
connect; reconnect the server in your client (e.g. `/mcp` in Claude Code) if it was already running.

- Filter operators `not_like`, `starts_with`, `ends_with`, `in`, `not_in`, `between`, `is_null`,
  `is_not_null`. List operators take a `values` array.
- Filters and `order_by` on joined table columns (`users.email`).
- `sort` for multi-column ordering.
- `allowed_tables` allowlist mode.
- `include_views` to expose database views.
- `audit_log` to log every tool call.
- `masked_columns` to anonymise sensitive columns instead of hiding them. See
  [Masking columns](README.md#masking-columns).

### 5. Custom code (low impact)

Only relevant if you extended `QueryDatabaseTool`, `DescribeDatabaseTool` or used the
`InteractsWithDatabaseSchema` trait directly:

- `handle()` now wraps the work in the audit log and delegates to the private `query()` /
  `describe()` methods.
- `isTableAllowed()` now checks the name against `allowedTables()` instead of
  `Schema::hasTable()`, and `allowedColumns()` applies wildcard and `table.column` patterns.

### Checklist

- [ ] Laravel 12.41.1+ or 13, `laravel/mcp` ^1.0
- [ ] `composer require datomatic/laravel-database-mcp:^1.0 -W`
- [ ] Published config: `denied_tables` and `denied_columns` merged with the new defaults
- [ ] Check that no column the assistant needs ends in `_token` or `_secret`
- [ ] Decide on `query_timeout` for your workload
- [ ] Reconnect the MCP server in your clients
