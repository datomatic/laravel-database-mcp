<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

/**
 * Caps how long the assistant's queries may run, so a heavy query cannot tie
 * up the database. The limit is set on the session before the queries and
 * restored afterwards, because the connection may be shared with the app.
 */
final class QueryTimeout
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Connection $connection, ?int $seconds, Closure $callback): mixed
    {
        if ($seconds === null || $seconds <= 0) {
            return $callback();
        }

        $statements = self::statements(
            $connection->getDriverName(),
            $connection instanceof MySqlConnection && $connection->isMaria(),
            $seconds,
        );

        if ($statements === null) {
            return $callback();
        }

        [$set, $reset] = $statements;

        $connection->statement($set);

        try {
            return $callback();
        } finally {
            $connection->statement($reset);
        }
    }

    /**
     * The statements that set and reset the timeout for a driver, or null when
     * the driver has no per-session statement timeout (SQLite, SQL Server).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function statements(string $driver, bool $isMaria, int $seconds): ?array
    {
        return match (true) {
            $driver === 'mariadb', $driver === 'mysql' && $isMaria => [
                'SET SESSION max_statement_time = '.$seconds,
                'SET SESSION max_statement_time = DEFAULT',
            ],
            $driver === 'mysql' => [
                'SET SESSION max_execution_time = '.($seconds * 1000),
                'SET SESSION max_execution_time = DEFAULT',
            ],
            $driver === 'pgsql' => [
                'SET statement_timeout = '.($seconds * 1000),
                'RESET statement_timeout',
            ],
            default => null,
        };
    }
}
