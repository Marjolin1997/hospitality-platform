<?php

test('protected API requests without authentication return JSON 401 even without an Accept header', function (): void {
    $response = $this->get('/api/v1/auth/me');

    $response
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json')
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});

test('protected API requests that explicitly accept JSON return the same unauthenticated contract', function (): void {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});
