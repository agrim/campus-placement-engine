<?php

declare(strict_types=1);

// Safe router for PHP's development server. Production Apache/Nginx should
// serve existing public files directly and send every other path to index.php.
$publicRoot = realpath(__DIR__);
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : null;
if (is_string($publicRoot)
    && is_string($path)
    && $path !== ''
    && !str_contains($path, "\0")
    && !str_contains(rawurldecode($path), "\0")) {
    $decodedPath = rawurldecode($path);
    $segments = explode('/', trim($decodedPath, '/'));
    $safeSegments = array_filter(
        $segments,
        static fn (string $segment): bool => $segment === '..' || str_starts_with($segment, '.'),
    ) === [];
    if ($safeSegments) {
        $candidate = realpath($publicRoot . '/' . ltrim($decodedPath, '/'));
        if (is_string($candidate)
            && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
            && is_file($candidate)) {
            return false;
        }
    }
}

require __DIR__ . '/index.php';
