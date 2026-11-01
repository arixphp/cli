<?php

declare(strict_types=1);

test('application fixture', function (): void {
    $response = $this->get('/')->assertOk()->assertSee('Arix test application');
    expect($response->response->headers->get('X-Fixture-Request'))->toBe('1');
});
