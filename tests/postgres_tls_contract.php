<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require __DIR__ . '/../app/bootstrap.php';

use App\Infrastructure\Persistence\PostgresConnectionPolicy;

$configuredUrl = getenv('CPE_POSTGRES_TLS_TEST_URL');
if ($configuredUrl === false || $configuredUrl === '') {
    echo "SKIP negotiated PostgreSQL TLS contract: CPE_POSTGRES_TLS_TEST_URL is not configured.\n";
    exit(0);
}
$url = (string) $configuredUrl;

$poolMode = (string) (getenv('CPE_POSTGRES_TLS_TEST_POOL_MODE') ?: 'direct');
$provider = PostgresConnectionPolicy::fromUrl($url, $poolMode, false, 'CPE_POSTGRES_TLS_TEST_URL');
$provider->connection()->query('SELECT 1')->fetchColumn();
$diagnostics = $provider->diagnostics();
if (($diagnostics['strict_policy'] ?? null) !== true
    || ($diagnostics['ssl_mode'] ?? null) !== 'verify-full'
    || ($diagnostics['persistent'] ?? null) !== false
    || ($diagnostics['negotiated_tls_verified'] ?? null) !== true) {
    throw new RuntimeException('Negotiated PostgreSQL TLS evidence is incomplete.');
}
$provider->disconnect();

echo "PASS negotiated PostgreSQL TLS contract\n";
