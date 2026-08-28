<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

/**
 * Password-safe libpq command configuration derived from a validated provider.
 *
 * @internal Create through PostgresConnectionPolicy or PostgresConnectionProvider.
 */
final class PostgresCommandConnectionSpec
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $sslMode,
        private readonly ?string $sslRootCert,
        private readonly ?int $connectTimeout,
        /** @var array<string, string> */
        private readonly array $uriOptions = [],
    ) {
    }

    public function safeUri(): string
    {
        $host = trim($this->host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        $uri = sprintf(
            'postgresql://%s@%s:%d/%s',
            rawurlencode($this->username),
            $host,
            $this->port,
            rawurlencode($this->database),
        );
        if ($this->uriOptions === []) {
            return $uri;
        }
        $query = [];
        foreach ($this->uriOptions as $name => $value) {
            $query[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        return $uri . '?' . implode('&', $query);
    }

    /**
     * libpq environment for a child command. Connection-affecting ambient PG*
     * values are removed before the validated settings are installed.
     *
     * @param array<string, string> $ambient
     * @return array<string, string>
     */
    public function childEnvironment(array $ambient): array
    {
        unset($ambient['CPE_DATABASE_URL'], $ambient['CPE_PG_PASSWORD']);
        foreach (array_keys($ambient) as $name) {
            if (str_starts_with(strtoupper($name), 'PG')) {
                unset($ambient[$name]);
            }
        }
        $ambient['PGPASSWORD'] = $this->password;
        $ambient['PGSSLMODE'] = $this->sslMode;
        if ($this->sslRootCert !== null) {
            $ambient['PGSSLROOTCERT'] = $this->sslRootCert;
        }
        if ($this->connectTimeout !== null) {
            $ambient['PGCONNECT_TIMEOUT'] = (string) $this->connectTimeout;
        }
        return $ambient;
    }
}
