<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Tests;

use Datomatic\LaravelDatabaseMcp\LaravelDatabaseMcpServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/passport/database/migrations');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            PassportServiceProvider::class,
            LaravelDatabaseMcpServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('database-mcp.connection', null);
    }
}
