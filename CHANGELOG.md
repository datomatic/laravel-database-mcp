# Changelog

All notable changes to this package are documented in this file.

## 1.0.0 - 2026-11-15

### Security

- Fixed a deny-list bypass: a schema-qualified table name (e.g. `main.password_resets`, or
  `otherdb.table` on MySQL) passed the table checks and could be queried. Tables are now matched
  by their exact name against the listing of allowed tables.
- PostgreSQL and SQLite now only expose tables in the connection's own schema (`search_path` /
  `main`), as MySQL already did.

### Added

- Wildcard patterns in `denied_tables` and `denied_columns` (`telescope_*`, `*_token`).
- Table-scoped denied columns (`users.email`, `customers.*_iban`).
- `allowed_tables` allowlist mode.
- `include_views` option to expose database views.
- Filter operators: `not_like`, `starts_with`, `ends_with`, `in`, `not_in`, `between`, `is_null`,
  `is_not_null` (list operators take a new `values` key).
- Filters and `order_by` may target joined table columns (`table.column`).
- `sort` parameter for multi-column ordering.
- `query_timeout` option (default 10 seconds) on MySQL, MariaDB and PostgreSQL.
- `audit_log` option to log every tool call with user, arguments, outcome and duration.
- `masked_columns` option to anonymise sensitive columns instead of hiding them, with the
  `redact`, `partial`, `email` and `hash` (keyed pseudonym) strategies. Masked columns cannot be
  sorted, grouped, pattern-matched or aggregated except with `COUNT`; `searchable` ones accept `=`
  with the full exact value. `describe_database` flags them. New `mask_key` option for `hash`.

### Changed

- Default `denied_tables` now also cover `notifications`, `webhook_calls`, `telescope_*`,
  `pulse_*` and all `oauth_*` tables.
- Default `denied_columns` are now `password`, `two_factor_recovery_codes`, `*_token`, `*_secret`.
- Table names must match exactly (case included) the name in the database.
- `query_timeout` is on by default (10 seconds).
- Dropped Laravel 11 support (end of life); requires Laravel 12.41.1+ or 13 (same floor as `laravel/mcp` 1.x).
- Requires `laravel/mcp` ^1.0 (dropped 0.9).

See [UPGRADE.md](UPGRADE.md) for upgrade steps.

## Earlier releases

See the [GitHub releases](https://github.com/datomatic/laravel-database-mcp/releases) for
`v0.0.1` – `v0.2.0`.
