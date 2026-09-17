<?php

use Illuminate\Support\Facades\DB;

test('automated tests run only against the isolated test database', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(DB::connection()->getDatabaseName())->toBe('hospitality_test');
});
