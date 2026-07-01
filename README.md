# Laravel Database MCP

A read-only [MCP](https://modelcontextprotocol.io) server that lets an AI assistant
(Claude Code, Cursor, …) explore and query a Laravel application's database through safe,
structured parameters — never raw SQL.

It exposes two tools:

| Tool | Purpose |
|------|---------|
| `describe_database` | Discover tables, columns, types and relationships |
| `query_database` | Read rows with optional joins, filters and ordering |

## Installation

```bash
composer require datomatic/laravel-database-mcp
```

The service provider is auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=database-mcp-config
```

## Security model

Defence is layered. From outermost to innermost:

0. **Authentication + authorization.** The route is protected by the configured middleware and an
   authorization gate (see [Authorization](#authorization)).
1. **Read-only database connection.** All reads run through `config('database-mcp.connection')`.
   Point it at a database user with `SELECT`-only grants and the assistant physically cannot
   write — the only guarantee that does not rely on application logic.
2. **No raw SQL.** Tools accept structured parameters only; nothing is interpolated into SQL.
3. **Identifiers validated against the live schema.** Every table and column must exist and
   survive the deny lists, or the request is rejected before a query runs.
4. **Table deny list.** Auth tokens, sessions, jobs, cache and migrations are never exposed
   (configurable via `denied_tables`).
5. **Column deny list.** `password`, `remember_token`, `two_factor_*`, `api_token` are stripped
   from every result and description, even on a wildcard select (configurable via `denied_columns`).
6. **Row cap.** Results are limited (`max_limit`, default 100).

On MySQL the tools only expose tables belonging to the connection's own database, even if the
user can see other schemas.

### Setting up a read-only database user

```sql
CREATE USER 'app_readonly'@'%' IDENTIFIED BY 'a-strong-password';
GRANT SELECT ON your_database.* TO 'app_readonly'@'%';
FLUSH PRIVILEGES;
```

Define a dedicated connection in `config/database.php`:

```php
'mysql_readonly' => [
    ...config('database.connections.mysql'),
    'username' => env('DB_READONLY_USERNAME'),
    'password' => env('DB_READONLY_PASSWORD'),
],
```

Then point the package at it:

```dotenv
DATABASE_MCP_CONNECTION=mysql_readonly
DB_READONLY_USERNAME=app_readonly
DB_READONLY_PASSWORD=a-strong-password
```

When `connection` is `null` the application's default connection is used — which is **not**
read-only. Always configure the dedicated user in any shared or production environment.

## Configuration

`config/database-mcp.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `connection` | `env('DATABASE_MCP_CONNECTION')` | Connection to read from (null = default) |
| `register_route` | `true` | Auto-register the HTTP route |
| `path` | `database-mcp` | URL path of the server |
| `middleware` | `['auth:sanctum']` | Middleware applied to the route |
| `gate` | `access-database-mcp` | Ability checked as `can:` middleware (null disables) |
| `name` | `"{APP_NAME} Database"` | Name advertised to MCP clients |
| `instructions` | (workflow text) | Guidance the assistant reads on connect |
| `max_limit` | `100` | Maximum rows per query |
| `denied_tables` | auth/infra tables | Tables never exposed |
| `denied_columns` | secrets | Columns stripped from every result |

Set a project-specific name so the same package reused across projects stays distinguishable:

```dotenv
MCP_DATABASE_NAME="Acme Database"
```

### Authentication guard

The route is authenticated with **Laravel Sanctum** (`auth:sanctum`) by default. If your API uses a
different guard — for example **Laravel Passport** (`auth:api`) — override `middleware` in your own
`config/database-mcp.php`. Only the keys you set override the package defaults:

```php
// config/database-mcp.php
return [
    'middleware' => ['auth:api'], // Passport guard
];
```

## Authorization

The route is guarded by a gate named in `config('database-mcp.gate')` (default
`access-database-mcp`), applied as `can:` middleware. Define it in your own service provider to
decide who may access the server:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('access-database-mcp', fn ($user) => $user->isSuperAdmin());
```

If you never define the gate, the package falls back to allowing **local environments only**
(everyone else gets a `403`). Set `gate` to `null` in the config to disable the check entirely.

## Registration

By default the package registers the server over HTTP at `config('database-mcp.path')` with the
configured middleware. To register it yourself, set `register_route` to `false` and add it to
`routes/ai.php`:

```php
use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('database-mcp', DatabaseServer::class)
    ->middleware(['auth:sanctum', 'can:access-database-mcp']);
```

Register it with your MCP client using a project-specific connector name:

```bash
claude mcp add acme-db --transport http https://acme.test/database-mcp
```

## Usage

### `describe_database`

Call with no arguments to list allowed tables and their outgoing foreign keys:

```json
{
  "tables": [
    { "table": "orders", "references": [ { "column": "user_id", "references": "users.id" } ] }
  ]
}
```

Call with a `table` to get its columns and relationships in both directions:

```json
{
  "table": "orders",
  "columns": [
    { "name": "id", "type": "bigint", "nullable": false, "default": null }
  ],
  "references":   [ { "column": "user_id", "references": "users.id" } ],
  "referenced_by": [ { "table": "order_product", "column": "order_id" } ]
}
```

Relationships come from the database foreign keys, not Eloquent — they reflect the actual
constraints. Foreign keys pointing at denied tables are filtered out.

### `query_database`

| Parameter | Type | Description |
|-----------|------|-------------|
| `table` | string (required) | Base table |
| `columns` | string[] | Columns to select; omit for all allowed columns |
| `joins` | object[] | Related tables to join |
| `filters` | object[] | `WHERE` conditions, ANDed together |
| `order_by` | string | Column to sort by |
| `order_direction` | `asc` \| `desc` | Sort direction |
| `limit` | integer | Max rows (1 to `max_limit`, default 50) |

A filter is `{ "column", "operator", "value" }`. Operators: `=`, `!=`, `>`, `>=`, `<`, `<=`,
`like` (the `like` value is wrapped in `%…%` automatically).

#### Simple query

```json
{
  "table": "orders",
  "columns": ["code", "total"],
  "filters": [ { "column": "status", "operator": "=", "value": "completed" } ],
  "order_by": "created_at",
  "order_direction": "desc",
  "limit": 20
}
```

#### Query with a join — "orders with their user"

```json
{
  "table": "orders",
  "columns": ["code", "total"],
  "joins": [
    { "table": "users", "on": "user_id", "columns": ["email", "firstname"] }
  ]
}
```

A join object accepts:

- `table` (required) — related table; must share a foreign key with the base table.
- `on` — the foreign key column to join on. Required only when the two tables are linked by more
  than one relationship.
- `type` — `left` (default) or `inner`.
- `columns` — columns from the related table; omit for all allowed columns.

The join condition is derived automatically from the foreign key. When a join is present, result
keys are prefixed with the table name so columns never collide (`orders.code`, `users.email`).

#### Disambiguating relationships

When two tables are linked by several foreign keys (e.g. `orders.user_id` and `orders.created_by`
both point at `users`), joining without `on` returns an error listing the choices. Pass
`"on": "user_id"` to pick the intended relationship.

### Typical AI workflow

1. `describe_database` (no arguments) → see available tables and relationships.
2. `describe_database` with a `table` → see columns and foreign keys.
3. `query_database` with the correct table, column and `on` names.

## Testing

```bash
composer install
vendor/bin/pest
```

The suite runs against an in-memory SQLite database via Orchestra Testbench.

## License

MIT.
