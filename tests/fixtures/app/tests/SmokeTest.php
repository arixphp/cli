<?php

declare(strict_types=1);
use Arix\HttpFoundation\Request;

test('application fixture', function (): void {
    $kernel = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $response = $kernel->handle(Request::create('http://localhost/'));
    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('Arix test application');
});
