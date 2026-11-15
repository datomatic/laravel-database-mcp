<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Support;

use RuntimeException;

use function hash_hmac;
use function in_array;
use function intdiv;
use function is_array;
use function is_scalar;
use function is_string;
use function max;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;
use function min;
use function str_repeat;
use function substr;

/**
 * How a sensitive column is anonymised in tool output, and whether the
 * assistant may look rows up by its exact value.
 */
final readonly class ColumnMask
{
    /**
     * @var list<string>
     */
    public const array STRATEGIES = ['redact', 'partial', 'email', 'hash'];

    public function __construct(
        public string $strategy,
        public bool $searchable = false,
        public int $visible = 4,
    ) {}

    /**
     * Build a mask from its config entry: a strategy name, or an array with
     * "strategy", "searchable" and "visible" keys. An unknown strategy falls
     * back to "redact" so a typo never exposes the value.
     *
     * @param  string|array{strategy?: string, searchable?: bool, visible?: int}  $config
     */
    public static function fromConfig(string|array $config): self
    {
        $config = is_array($config) ? $config : ['strategy' => $config];
        $strategy = $config['strategy'] ?? 'redact';

        return new self(
            is_string($strategy) && in_array($strategy, self::STRATEGIES, true) ? $strategy : 'redact',
            (bool) ($config['searchable'] ?? false),
            max(0, (int) ($config['visible'] ?? 4)),
        );
    }

    public function apply(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return '***';
        }

        $value = (string) $value;

        return match ($this->strategy) {
            'partial' => $this->partial($value),
            'email' => $this->email($value),
            'hash' => $this->hash($value),
            default => '***',
        };
    }

    /**
     * Keep only the last characters, never more than a third of the value so
     * short values are not given away almost entirely.
     */
    private function partial(string $value): string
    {
        $length = mb_strlen($value);
        $visible = min($this->visible, intdiv($length, 3));

        return str_repeat('*', $length - $visible).mb_substr($value, $length - $visible);
    }

    private function email(string $value): string
    {
        $at = mb_strrpos($value, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($value, 0, 1).'***'.mb_substr($value, $at);
    }

    /**
     * A keyed hash (HMAC) gives a stable pseudonym: the same value always maps
     * to the same token, so rows can be counted and correlated. Without the key
     * low-entropy values (phone numbers, tax codes) could be brute-forced.
     */
    private function hash(string $value): string
    {
        $key = config('database-mcp.mask_key') ?: config('app.key');

        throw_if(! is_string($key) || $key === '', RuntimeException::class, 'The "hash" mask needs a key: set DATABASE_MCP_MASK_KEY or APP_KEY.');

        return substr(hash_hmac('sha256', $value, $key), 0, 16);
    }
}
