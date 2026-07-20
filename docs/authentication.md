# Authentication guide

The database MCP server is exposed over HTTP and must be authenticated. This package applies the
middleware from `config('database-mcp.middleware')` (default `['auth:sanctum']`) plus the
authorization gate. You choose the guard by overriding that config.

There are two supported approaches, mirroring
[Laravel MCP's authentication docs](https://laravel.com/docs/13.x/mcp#authentication):

- **Sanctum** — simple bearer-token authentication. Easiest to add; recommended unless you need OAuth.
- **Passport (OAuth 2.1)** — the mechanism documented in the MCP specification and the most widely
  supported among MCP clients. Recommended when a client only speaks OAuth.

> This package auto-registers the `Mcp::web(...)` route. For **OAuth** you still have to add
> `Mcp::oauthRoutes()` yourself (see below) — the package does not register the OAuth discovery
> routes for you.

---

## Sanctum

Clients authenticate by sending an `Authorization: Bearer <token>` header.

### New application

1. Install the API scaffolding (adds Sanctum, the `auth:sanctum` guard and `routes/api.php`):

   ```bash
   php artisan install:api
   ```

2. Add the `HasApiTokens` trait to your authenticatable model:

   ```php
   use Laravel\Sanctum\HasApiTokens;

   class User extends Authenticatable
   {
       use HasApiTokens;
   }
   ```

3. Keep the package default (nothing to do) — `config('database-mcp.middleware')` is already
   `['auth:sanctum']`.

4. Issue a token to test:

   ```php
   $token = $user->createToken('mcp')->plainTextToken;
   ```

### Existing application already using Sanctum

Nothing to configure — the default `['auth:sanctum']` middleware already applies. Point your MCP
client at the server URL with an `Authorization: Bearer <token>` header.

---

## Passport (OAuth 2.1)

### New application

1. Follow Passport's
   [installation guide](https://laravel.com/docs/13.x/passport#installation). You should end up
   with an `OAuthenticatable` model, a new `api` guard using the `passport` driver, and Passport
   keys:

   ```bash
   php artisan install:api --passport
   ```

2. Register the OAuth discovery/registration routes and switch the guard to `auth:api` in
   `routes/ai.php`:

   ```php
   use Laravel\Mcp\Facades\Mcp;

   Mcp::oauthRoutes();
   ```

   The package registers the `database-mcp` server route itself, so you only add `oauthRoutes()`
   here — not the `Mcp::web(...)` line.

3. Point the package at the Passport guard in `config/database-mcp.php`:

   ```php
   return [
       'middleware' => ['auth:api'],
   ];
   ```

4. Publish the MCP authorization view and tell Passport to use it from your
   `AppServiceProvider::boot()`:

   ```bash
   php artisan vendor:publish --tag=mcp-views
   ```

   ```php
   use Laravel\Passport\Passport;

   public function boot(): void
   {
       Passport::authorizationView(fn ($parameters) => view('mcp.authorize', $parameters));
   }
   ```

   This screen is shown to the user to approve or reject the AI agent's authentication attempt.

`Mcp::oauthRoutes()` advertises and uses a single `mcp:use` scope; OAuth here acts as a translation
layer to the underlying authenticatable model (custom scopes are not currently supported).

MCP clients re-register on every OAuth connection with the same `client_name` but a different
(ephemeral, loopback) redirect URI, which by default leaves behind a new Passport client per login.
This package reuses the existing client for a given name instead (`database-mcp.dedupe_oauth_clients`,
default `true`) — set `DATABASE_MCP_DEDUPE_OAUTH_CLIENTS=false` to restore the plain
always-create-a-new-client behaviour.

### Existing application already using Passport

1. Add the OAuth routes in `routes/ai.php`:

   ```php
   use Laravel\Mcp\Facades\Mcp;

   Mcp::oauthRoutes();
   ```

2. Set the middleware to the Passport guard in `config/database-mcp.php`:

   ```php
   return [
       'middleware' => ['auth:api'],
   ];
   ```

Everything else works within your existing Passport installation.

---

## Passport vs. Sanctum

OAuth 2.1 is the authentication mechanism in the Model Context Protocol specification and is the
most widely supported among MCP clients, so prefer Passport when possible. If your application
already uses Sanctum, adding Passport can be cumbersome — stay on Sanctum until you have a concrete
need for a client that only supports OAuth.

## Authorization

Whichever guard you use, access is still gated by the `access-database-mcp` ability. See the
[Authorization](../README.md#authorization) section of the README to control who may connect.
