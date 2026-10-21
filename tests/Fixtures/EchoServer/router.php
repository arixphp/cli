<?php

declare(strict_types=1);

/*
 * Loopback fixture for the Arix HTTP client tests.
 *
 * It is a plain PHP script served by the built-in server: it never boots
 * Arix and never loads the CLI autoloader.
 */

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$query = parse_url($uri, PHP_URL_QUERY);
$body = (string) file_get_contents('php://input');

$headers = [];

foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = (string) $value;
    }
}

if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}

if (isset($_SERVER['CONTENT_LENGTH'])) {
    $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
}

if ($path === '/echo') {
    header('Content-Type: application/json');

    echo json_encode([
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'uri' => $uri,
        'query' => (string) $query,
        'headers' => $headers,
        'body' => $body,
    ], JSON_THROW_ON_ERROR);

    return;
}

if ($path === '/redirect') {
    header('Location: /echo', true, 302);

    return;
}

if ($path === '/redirect-host') {
    header('Location: http://localhost:' . (string) ($_SERVER['SERVER_PORT'] ?? '') . '/echo', true, 302);

    return;
}

if ($path === '/redirect-target') {
    parse_str((string) $query, $parameters);
    header('Location: ' . $parameters['url'], true, 302);

    return;
}

if ($path === '/slow') {
    sleep(3);

    header('Content-Type: text/plain');

    echo 'late';

    return;
}

if ($path === '/json') {
    header('Content-Type: application/json');

    echo '{"ok":true}';

    return;
}

if ($path === '/invalid-json') {
    header('Content-Type: application/json');

    echo 'not json';

    return;
}

if ($path === '/gzip') {
    header('Content-Type: text/plain');
    header('Content-Encoding: gzip');

    echo gzencode('compressed');

    return;
}

if ($path === '/large') {
    parse_str((string) $query, $parameters);
    $bytes = (int) ($parameters['bytes'] ?? 0);

    if ($bytes < 1 || $bytes > 1048576) {
        header('Content-Type: text/plain', true, 400);

        echo 'invalid size';

        return;
    }

    header('Content-Type: application/octet-stream');

    echo str_repeat('x', $bytes);

    return;
}

if (preg_match('#^/status/(\d{3})$#', (string) $path, $matches) === 1) {
    header('Content-Type: text/plain', true, (int) $matches[1]);

    echo 'status';

    return;
}

header('Content-Type: text/plain', true, 404);

echo 'not found';
