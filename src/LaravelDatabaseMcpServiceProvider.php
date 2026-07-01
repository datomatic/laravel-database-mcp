<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp;

use Datomatic\LaravelDatabaseMcp\Servers\DatabaseServer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

use function is_string;

class LaravelDatabaseMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/database-mcp.php', 'database-mcp');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/database-mcp.php' => config_path('database-mcp.php'),
            ], 'database-mcp-config');
        }

        $this->registerFallbackGate();
        $this->registerRoute();
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
            $middleware[] = 'can:' . $gate;
        }

        Mcp::web((string) config('database-mcp.path', 'database-mcp'), DatabaseServer::class)
            ->middleware($middleware);
    }
}
