<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp;

use Datomatic\LaravelDatabaseMcp\Http\Controllers\DedupedOAuthRegisterController;
use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Override;

use function is_string;

class LaravelDatabaseMcpServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/database-mcp.php', 'database-mcp');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/database-mcp.php' => config_path('database-mcp.php'),
            ], 'database-mcp-config');
        }

        $this->registerFallbackGate();
        $this->registerRoute();
        $this->registerOAuthClientDeduplication();
    }

    private function registerFallbackGate(): void
    {
        $gate = config('database-mcp.gate');

        if (! is_string($gate) || $gate === '' || Gate::has($gate)) {
            return;
        }

        Gate::define($gate, static fn (mixed $user = null): bool => app()->environment('local'));
    }

    private function registerRoute(): void
    {
        if (config('database-mcp.register_route') !== true) {
            return;
        }

        $middleware = (array) config('database-mcp.middleware', []);
        $gate = config('database-mcp.gate');

        if (is_string($gate) && $gate !== '') {
            $middleware[] = 'can:'.$gate;
        }

        Mcp::web((string) config('database-mcp.path', 'database-mcp'), DatabaseServer::class)
            ->middleware($middleware);
    }

    private function registerOAuthClientDeduplication(): void
    {
        if (config('database-mcp.dedupe_oauth_clients') !== true) {
            return;
        }

        if (! class_exists('Laravel\Passport\ClientRepository')) {
            return;
        }

        $this->app->bind(OAuthRegisterController::class, DedupedOAuthRegisterController::class);
    }
}
