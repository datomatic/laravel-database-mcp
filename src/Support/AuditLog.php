<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Support;

use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

use function hrtime;
use function round;

final class AuditLog
{
    /**
     * Run a tool call and, when auditing is enabled, record who made it, with
     * which arguments and how it ended.
     *
     * @param  Closure(): Response  $callback
     */
    public static function record(string $tool, Request $request, Closure $callback): Response
    {
        if (! (bool) config('database-mcp.audit_log.enabled', false)) {
            return $callback();
        }

        $startedAt = hrtime(true);

        try {
            $response = $callback();
        } catch (ValidationException $validationException) {
            self::write($tool, $request, $startedAt, 'invalid', $validationException->getMessage());

            throw $validationException;
        }

        self::write(
            $tool,
            $request,
            $startedAt,
            $response->isError() ? 'error' : 'ok',
            $response->isError() ? (string) $response->content() : null,
        );

        return $response;
    }

    private static function write(string $tool, Request $request, int|float $startedAt, string $outcome, ?string $error): void
    {
        logger()->channel(config('database-mcp.audit_log.channel'))->info('database-mcp tool call', [
            'tool' => $tool,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'arguments' => $request->all(),
            'outcome' => $outcome,
            'error' => $error,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ]);
    }
}
