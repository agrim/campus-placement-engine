<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use RuntimeException;

/**
 * Strict Engine runtime policy for PostgreSQL connections.
 *
 * PostgresConnectionProvider::fromUrl() remains the compatibility entry point
 * for pinned Cloud releases. New Engine runtime connections must pass through
 * this policy instead.
 */
final class PostgresConnectionPolicy
{
    private const QUERY_PARAMETERS = ['sslmode', 'sslrootcert', 'connect_timeout'];
    private const COMPONENTS = [
        'host',
        'port',
        'database',
        'username',
        'password',
        'sslmode',
        'sslrootcert',
        'connect_timeout',
    ];
    private const MAX_CONNECT_TIMEOUT = 30;

    public static function fromEnvironment(): PostgresConnectionProvider
    {
        $configuredPoolMode = getenv('CPE_POSTGRES_POOL_MODE');
        $poolMode = self::poolMode(
            $configuredPoolMode === false || $configuredPoolMode === '' ? 'direct' : (string) $configuredPoolMode
        );
        $allowInsecureLoopback = self::explicitOptIn(
            (string) (getenv('CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK') ?: '')
        );
        $url = self::environmentValue('CPE_DATABASE_URL');
        if ($url !== '') {
            $sslRootCert = self::optionalEnvironmentValue('CPE_PG_SSLROOTCERT');
            $connectTimeout = self::optionalEnvironmentValue('CPE_PG_CONNECT_TIMEOUT');
            return self::providerFromUrl(
                $url,
                $poolMode,
                $allowInsecureLoopback,
                'CPE_DATABASE_URL',
                $sslRootCert,
                $connectTimeout,
            );
        }

        return self::fromComponents([
            'host' => self::environmentValue('CPE_PG_HOST', '127.0.0.1'),
            'port' => self::environmentValue('CPE_PG_PORT', '5432'),
            'database' => self::environmentValue('CPE_PG_DATABASE'),
            'username' => self::environmentValue('CPE_PG_USER'),
            'password' => self::environmentValue('CPE_PG_PASSWORD'),
            'sslmode' => self::environmentValue('CPE_PG_SSLMODE'),
            'sslrootcert' => self::environmentValue('CPE_PG_SSLROOTCERT'),
            'connect_timeout' => self::environmentValue('CPE_PG_CONNECT_TIMEOUT'),
        ], $poolMode, $allowInsecureLoopback, 'PostgreSQL component environment');
    }

    public static function fromUrl(
        string $url,
        string $poolMode,
        bool $allowInsecureLoopback = false,
        string $label = 'PostgreSQL URL'
    ): PostgresConnectionProvider {
        return self::providerFromUrl($url, $poolMode, $allowInsecureLoopback, $label, null, null);
    }

    /** @param array<string, int|string> $components */
    public static function fromComponents(
        array $components,
        string $poolMode,
        bool $allowInsecureLoopback = false,
        string $label = 'PostgreSQL components'
    ): PostgresConnectionProvider {
        $unknown = array_diff(array_keys($components), self::COMPONENTS);
        if ($unknown !== []) {
            throw new RuntimeException($label . ' contains an unknown component.');
        }
        $poolMode = self::poolMode($poolMode);
        $host = self::host((string) ($components['host'] ?? ''), $label);
        $port = self::port((string) ($components['port'] ?? ''), $label);
        $database = self::dsnValue((string) ($components['database'] ?? ''), 'database', $label);
        $username = self::credential((string) ($components['username'] ?? ''), 'username', $label, false);
        $password = self::credential((string) ($components['password'] ?? ''), 'password', $label, true);
        $sslMode = strtolower((string) ($components['sslmode'] ?? ''));
        $sslRootCert = self::optionalRootCert((string) ($components['sslrootcert'] ?? ''), $label);
        $connectTimeout = self::optionalConnectTimeout((string) ($components['connect_timeout'] ?? ''), $label);

        return self::createProvider(
            $host,
            $port,
            $database,
            $username,
            $password,
            $sslMode,
            $sslRootCert,
            $connectTimeout,
            $poolMode,
            $allowInsecureLoopback,
            $label,
        );
    }

    private static function providerFromUrl(
        string $url,
        string $poolMode,
        bool $allowInsecureLoopback,
        string $label,
        ?string $environmentRootCert,
        ?string $environmentConnectTimeout,
    ): PostgresConnectionProvider {
        self::withoutControlCharacters($url, $label);
        if ($url !== trim($url)) {
            throw new RuntimeException($label . ' must not contain surrounding whitespace.');
        }
        try {
            $parts = parse_url($url);
        } catch (\ValueError $e) {
            throw new RuntimeException($label . ' is invalid.', 0, $e);
        }
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
            throw new RuntimeException($label . ' must be a postgresql:// URL.');
        }
        if (isset($parts['fragment'])) {
            throw new RuntimeException($label . ' must not contain a fragment.');
        }
        $query = self::query((string) ($parts['query'] ?? ''), $label);
        if (array_key_exists('sslrootcert', $query) && $environmentRootCert !== null) {
            throw new RuntimeException($label . ' configures sslrootcert more than once.');
        }
        if (array_key_exists('connect_timeout', $query) && $environmentConnectTimeout !== null) {
            throw new RuntimeException($label . ' configures connect_timeout more than once.');
        }

        $host = self::host((string) ($parts['host'] ?? ''), $label);
        $port = self::port((string) ($parts['port'] ?? '5432'), $label);
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/')) {
            throw new RuntimeException($label . ' must include a database.');
        }
        $database = self::dsnValue(self::decode(substr($path, 1), $label), 'database', $label);
        $username = self::credential(self::decode((string) ($parts['user'] ?? ''), $label), 'username', $label, false);
        $password = self::credential(self::decode((string) ($parts['pass'] ?? ''), $label), 'password', $label, true);
        $sslMode = strtolower((string) ($query['sslmode'] ?? ''));
        $rootCertValue = $query['sslrootcert'] ?? $environmentRootCert ?? '';
        $timeoutValue = $query['connect_timeout'] ?? $environmentConnectTimeout ?? '';

        return self::createProvider(
            $host,
            $port,
            $database,
            $username,
            $password,
            $sslMode,
            self::optionalRootCert($rootCertValue, $label),
            self::optionalConnectTimeout($timeoutValue, $label),
            self::poolMode($poolMode),
            $allowInsecureLoopback,
            $label,
        );
    }

    private static function createProvider(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        string $sslMode,
        ?string $sslRootCert,
        ?int $connectTimeout,
        string $poolMode,
        bool $allowInsecureLoopback,
        string $label,
    ): PostgresConnectionProvider {
        if ($sslMode === 'disable') {
            if (!$allowInsecureLoopback || !self::isLoopback($host)) {
                throw new RuntimeException($label . ' may disable TLS only for loopback with explicit local-test opt-in.');
            }
            $connectTimeout ??= 5;
            $sslRootCert = null;
        } elseif ($sslMode === 'verify-full') {
            if ($sslRootCert === null) {
                throw new RuntimeException($label . ' requires an explicit sslrootcert for production TLS.');
            }
            if ($connectTimeout === null) {
                throw new RuntimeException($label . ' requires an explicit bounded connect_timeout for production TLS.');
            }
        } else {
            throw new RuntimeException($label . ' requires sslmode=verify-full in production.');
        }

        return PostgresConnectionProvider::fromStrictPolicy(
            $host,
            $port,
            $database,
            $username,
            $password,
            $sslMode,
            $sslRootCert,
            $connectTimeout,
            $poolMode,
            $allowInsecureLoopback,
        );
    }

    /** @return array<string, string> */
    private static function query(string $query, string $label): array
    {
        if ($query === '') {
            return [];
        }
        $values = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                throw new RuntimeException($label . ' contains an empty query parameter.');
            }
            [$encodedKey, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = strtolower(self::decode($encodedKey, $label));
            if (!in_array($key, self::QUERY_PARAMETERS, true)) {
                throw new RuntimeException($label . ' contains an unknown query parameter.');
            }
            if (array_key_exists($key, $values)) {
                throw new RuntimeException($label . ' contains a duplicate query parameter.');
            }
            $values[$key] = self::decode($encodedValue, $label);
        }
        return $values;
    }

    private static function decode(string $value, string $label): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            throw new RuntimeException($label . ' contains invalid percent encoding.');
        }
        $decoded = rawurldecode($value);
        self::withoutControlCharacters($decoded, $label);
        return $decoded;
    }

    private static function host(string $host, string $label): string
    {
        self::withoutControlCharacters($host, $label);
        if ($host === '' || str_contains($host, ';')) {
            throw new RuntimeException($label . ' contains an invalid host.');
        }
        $unbracketed = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (strlen($host) > 253
            || preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host) !== 1) {
            throw new RuntimeException($label . ' contains an invalid host.');
        }
        return $host;
    }

    private static function port(string $port, string $label): int
    {
        if ($port === '' || !ctype_digit($port)) {
            throw new RuntimeException($label . ' contains an invalid port.');
        }
        $value = (int) $port;
        if ($value < 1 || $value > 65535) {
            throw new RuntimeException($label . ' contains an invalid port.');
        }
        return $value;
    }

    private static function dsnValue(string $value, string $name, string $label): string
    {
        self::withoutControlCharacters($value, $label);
        if ($value === '' || str_contains($value, ';')) {
            throw new RuntimeException($label . ' contains an invalid ' . $name . '.');
        }
        return $value;
    }

    private static function credential(string $value, string $name, string $label, bool $emptyAllowed): string
    {
        self::withoutControlCharacters($value, $label);
        if (!$emptyAllowed && $value === '') {
            throw new RuntimeException($label . ' must include a ' . $name . '.');
        }
        return $value;
    }

    private static function optionalRootCert(string $value, string $label): ?string
    {
        if ($value === '') {
            return null;
        }
        self::withoutControlCharacters($value, $label);
        if (str_contains($value, ';')
            || (!str_starts_with($value, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $value) !== 1)) {
            throw new RuntimeException($label . ' contains an invalid sslrootcert source.');
        }
        return $value;
    }

    private static function optionalConnectTimeout(string $value, string $label): ?int
    {
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new RuntimeException($label . ' contains an invalid connect_timeout.');
        }
        $timeout = (int) $value;
        if ($timeout < 1 || $timeout > self::MAX_CONNECT_TIMEOUT) {
            throw new RuntimeException($label . ' connect_timeout must be between 1 and ' . self::MAX_CONNECT_TIMEOUT . ' seconds.');
        }
        return $timeout;
    }

    private static function poolMode(string $poolMode): string
    {
        $poolMode = strtolower(trim($poolMode));
        if (!in_array($poolMode, ['direct', 'session'], true)) {
            throw new RuntimeException('CPE_POSTGRES_POOL_MODE must be direct or session.');
        }
        return $poolMode;
    }

    private static function explicitOptIn(string $value): bool
    {
        if ($value === '' || $value === '0') {
            return false;
        }
        if ($value !== '1') {
            throw new RuntimeException('CPE_POSTGRES_ALLOW_INSECURE_LOOPBACK must be 1 when explicitly enabled.');
        }
        return true;
    }

    private static function optionalEnvironmentValue(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? null : (string) $value;
    }

    private static function environmentValue(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : (string) $value;
    }

    private static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }

    private static function withoutControlCharacters(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException($label . ' contains a control character.');
        }
    }
}
