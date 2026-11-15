<?php

declare(strict_types=1);

use Datomatic\LaravelDatabaseMcp\Support\ColumnMask;

it('redacts a value', function (): void {
    expect(ColumnMask::fromConfig('redact')->apply('secret'))->toBe('***');
});

it('keeps only the last characters with partial', function (): void {
    expect(ColumnMask::fromConfig('partial')->apply('3331234567'))->toBe('*******567');
});

it('never reveals more than a third of a short value with partial', function (): void {
    expect(ColumnMask::fromConfig('partial')->apply('12345'))->toBe('****5')
        ->and(ColumnMask::fromConfig('partial')->apply('ab'))->toBe('**');
});

it('honours a custom number of visible characters', function (): void {
    expect(ColumnMask::fromConfig(['strategy' => 'partial', 'visible' => 2])->apply('IT60X0542811101000000123456'))
        ->toBe('*************************56');
});

it('masks the local part of an email', function (): void {
    expect(ColumnMask::fromConfig('email')->apply('mario.rossi@acme.it'))->toBe('m***@acme.it');
});

it('redacts a value that is not an email with the email strategy', function (string $value): void {
    expect(ColumnMask::fromConfig('email')->apply($value))->toBe('***');
})->with(['not-an-email', '@acme.it']);

it('hashes to a stable keyed pseudonym', function (): void {
    config()->set('database-mcp.mask_key', 'key-one');
    $mask = ColumnMask::fromConfig('hash');

    $token = $mask->apply('RSSMRA80A01H501U');

    expect($token)->toHaveLength(16)
        ->and($mask->apply('RSSMRA80A01H501U'))->toBe($token)
        ->and($mask->apply('VRDLGU85B02F205X'))->not->toBe($token);

    config()->set('database-mcp.mask_key', 'key-two');

    expect($mask->apply('RSSMRA80A01H501U'))->not->toBe($token);
});

it('falls back to the app key for hashing', function (): void {
    config()->set('database-mcp.mask_key');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

    expect(ColumnMask::fromConfig('hash')->apply('value'))->toHaveLength(16);
});

it('refuses to hash without a key', function (): void {
    config()->set('database-mcp.mask_key');
    config()->set('app.key', '');

    ColumnMask::fromConfig('hash')->apply('value');
})->throws(RuntimeException::class, 'The "hash" mask needs a key');

it('keeps null values null', function (string $strategy): void {
    expect(ColumnMask::fromConfig($strategy)->apply(null))->toBeNull();
})->with(ColumnMask::STRATEGIES);

it('masks numeric values', function (): void {
    expect(ColumnMask::fromConfig('partial')->apply(3331234567))->toBe('*******567');
});

it('falls back to redact for an unknown strategy', function (): void {
    $mask = ColumnMask::fromConfig(['strategy' => 'typo', 'searchable' => true]);

    expect($mask->strategy)->toBe('redact')
        ->and($mask->searchable)->toBeTrue()
        ->and($mask->apply('secret'))->toBe('***');
});

it('is not searchable unless enabled', function (): void {
    expect(ColumnMask::fromConfig('email')->searchable)->toBeFalse();
});
