<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Passport\Client;

class DedupedOAuthRegisterController extends OAuthRegisterController
{
    /**
     * Register a new OAuth client, reusing an existing Passport client with
     * the same name instead of creating a fresh one every time.
     *
     * MCP clients doing OAuth re-register on every connection with the same
     * client_name but a different (ephemeral, loopback) redirect_uris value,
     * which would otherwise leave behind an orphaned Passport client per login.
     *
     * @throws BindingResolutionException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_name' => ['nullable', 'string', 'min:1', 'max:255'],
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', function (string $attribute, $value, $fail): void {
                if (! $this->isValidRedirectUri($value)) {
                    $fail($attribute.' is not a valid URL.');

                    return;
                }

                if (! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    return;
                }

                if (in_array('*', config('mcp.redirect_domains', []), true)) {
                    return;
                }

                if ($this->hasLocalhostDomain() && $this->isLocalhostUrl($value)) {
                    return;
                }

                if (! Str::startsWith($value, $this->allowedDomains())) {
                    $fail($attribute.' is not a permitted redirect domain.');
                }
            }],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            $isRedirectError = collect($errors->keys())->contains(
                fn (string $key): bool => str_starts_with($key, 'redirect_uris')
            );

            return response()->json([
                'error' => $isRedirectError ? 'invalid_redirect_uri' : 'invalid_client_metadata',
                'error_description' => $errors->first(),
            ], 400);
        }

        $validated = $validator->validated();

        if (class_exists('Laravel\Passport\ClientRepository') === false) {
            return response()->json([
                'error' => 'server_error',
                'error_description' => 'OAuth support (Passport) is not installed.',
            ], 500);
        }

        $name = $this->resolveClientName($validated);

        /** @var array<int, string> $redirectUris */
        $redirectUris = $validated['redirect_uris'];

        $client = Client::query()
            ->where('name', $name)
            ->where('revoked', false)
            ->first();

        if ($client instanceof Client) {
            $client->update(['redirect_uris' => $redirectUris]);
        } else {
            $clients = Container::getInstance()->make(
                'Laravel\Passport\ClientRepository'
            );

            $client = $clients->createAuthorizationCodeGrantClient(
                name: $name,
                redirectUris: $redirectUris,
                confidential: false,
                enableDeviceFlow: false,
            );
        }

        return response()->json([
            'client_id' => (string) $client->id,
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'redirect_uris' => $client->redirect_uris,
            'scope' => 'mcp:use',
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /**
     * Copied from the parent controller: it declares this `private`, so it
     * isn't inherited by this override.
     */
    private function hasLocalhostDomain(): bool
    {
        /** @var array<int, string> */
        $domains = config('mcp.redirect_domains', []);

        return collect($domains)->contains(fn (string $domain): bool => in_array(
            rtrim(Str::after($domain, '://'), '/'),
            ['localhost', '127.0.0.1', '[::1]'],
            true,
        ));
    }
}
