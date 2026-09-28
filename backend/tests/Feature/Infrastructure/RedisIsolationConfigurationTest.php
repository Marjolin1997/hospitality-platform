<?php

test('redis responsibilities use isolated logical databases', function (): void {
    expect(config('session.driver'))->toBe('redis')
        ->and(config('session.connection'))->toBe('session')
        ->and(config('cache.default'))->toBe('redis')
        ->and(config('cache.stores.redis.connection'))->toBe('cache')
        ->and(config('database.redis.default.database'))->not->toBe(config('database.redis.cache.database'))
        ->and(config('database.redis.default.database'))->not->toBe(config('database.redis.session.database'))
        ->and(config('database.redis.cache.database'))->not->toBe(config('database.redis.session.database'));
});

test('session redis connection is explicitly defined', function (): void {
    expect(config('database.redis.session'))->toBeArray()
        ->and(config('database.redis.session.database'))->not->toBeNull();
});
