<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

it('registers the configured gate', function (): void {
    expect(Gate::has('access-database-mcp'))->toBeTrue();
});

it('denies access outside the local environment by default', function (): void {
    expect(Gate::allows('access-database-mcp'))->toBeFalse();
});

it('lets the host application override the gate', function (): void {
    Gate::define('access-database-mcp', static fn (mixed $user = null): bool => true);

    expect(Gate::allows('access-database-mcp'))->toBeTrue();
});
