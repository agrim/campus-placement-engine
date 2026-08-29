<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class OutboundHttpPolicy
{
    /**
     * Resolve and validate an institution-configured webhook endpoint. Every
     * returned address has been checked and one must be pinned by the caller.
     *
     * @param null|callable(string): array<int, string> $resolver
     * @param null|array<int, int> $allowedPorts
     * @return array<string, mixed>
     */
    public static function assertWebhookAllowed(
        string $url,
        bool $allowPrivateNetwork,
        ?callable $resolver = null,
        ?bool $managedMode = null,
        ?bool $allowHttp = null,
        ?array $allowedPorts = null,
    ): array {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 2048 || !is_array($parts)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new RuntimeException('Webhook endpoint is invalid.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = trim((string) ($parts['host'] ?? ''), '[]');
        $host = strtolower($rawHost);
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || str_ends_with($host, '.')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['path']) && strlen((string) $parts['path']) > 1024
            || isset($parts['query']) && strlen((string) $parts['query']) > 1024) {
            throw new RuntimeException('Webhook endpoint must be HTTP(S) without credentials or fragments.');
        }
        $managedMode ??= self::truthy(getenv('CPE_HOSTED_MODE'));
        if ($managedMode && $allowPrivateNetwork) {
            throw new RuntimeException('Managed webhook delivery is public-egress only.');
        }
        $allowHttp ??= self::truthy(getenv('CPE_WEBHOOK_ALLOW_HTTP'));
        if ($scheme !== 'https' && (!$allowHttp || !$allowPrivateNetwork || $managedMode)) {
            throw new RuntimeException('Webhook endpoints require HTTPS; self-hosted HTTP requires both explicit private-network and HTTP policy.');
        }
        $allowedPorts ??= self::webhookAllowedPorts();
        $allowedPorts = array_values(array_unique(array_map('intval', $allowedPorts)));
        if ($port < 1 || $port > 65535 || !in_array($port, $allowedPorts, true)) {
            throw new RuntimeException('Webhook endpoint port is not approved by outbound policy.');
        }
        $addresses = self::resolveAddresses($host, $resolver);
        foreach ($addresses as $address) {
            if ($allowPrivateNetwork) {
                if (!self::isPublicAddress($address) && !self::isApprovedPrivateAddress($address)) {
                    throw new RuntimeException('Webhook endpoint resolves to a forbidden network range.');
                }
            } else {
                self::assertPublicAddress($address);
            }
        }
        $parts['scheme'] = $scheme;
        $parts['host'] = $host;
        $parts['port'] = $port;
        $parts['addresses'] = $addresses;
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $path = (string) ($parts['path'] ?? '/');
        $parts['request_url'] = $scheme . '://' . $authority . ':' . $port
            . ($path === '' ? '/' : $path)
            . (isset($parts['query']) ? '?' . (string) $parts['query'] : '');
        return $parts;
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, host: string}
     */
    public static function postJson(
        string $url,
        string $body,
        array $headers,
        int $timeoutSeconds,
        string $label,
        string $allowHttpEnvironment,
    ): array {
        $parts = self::assertAllowed($url, $allowHttpEnvironment);
        if (!function_exists('curl_init')) {
            throw new RuntimeException($label . ' delivery requires the PHP curl extension.');
        }
        if (strlen($body) > 1048576) {
            throw new RuntimeException($label . ' payload exceeds 1 MiB.');
        }
        foreach ($headers as $header) {
            if (str_contains($header, "\r") || str_contains($header, "\n")) {
                throw new RuntimeException($label . ' contains an invalid HTTP header.');
            }
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize ' . strtolower($label) . ' delivery.');
        }
        $timeoutSeconds = max(1, min(30, $timeoutSeconds));
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static fn (\CurlHandle $curl, string $chunk): int => strlen($chunk),
        ];
        $host = (string) $parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $address = (string) $parts['addresses'][0];
            if (str_contains($address, ':')) {
                $address = '[' . $address . ']';
            }
            $options[CURLOPT_RESOLVE] = [$host . ':' . (int) $parts['port'] . ':' . $address];
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($handle);
        unset($handle);
        if ($ok === false) {
            throw new RuntimeException($label . ' request failed (cURL error ' . $errorCode . ').');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($label . ' returned HTTP ' . $status . '.');
        }
        return ['status' => $status, 'host' => $host];
    }

    /** @return array<string, mixed> */
    public static function assertAllowed(string $url, string $allowHttpEnvironment): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 2048 || !is_array($parts)) {
            throw new RuntimeException('Outbound URL is invalid.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || $port < 1
            || $port > 65535) {
            throw new RuntimeException('Outbound URL must be a valid HTTP(S) URL without credentials or fragments.');
        }

        $allowHttp = self::truthy(getenv($allowHttpEnvironment));
        $allowPrivateNetwork = self::truthy(getenv('CPE_OUTBOUND_ALLOW_PRIVATE_NETWORK'));
        if ($scheme !== 'https' && !$allowHttp) {
            throw new RuntimeException('Outbound HTTP requires ' . $allowHttpEnvironment . '=1; use HTTPS in production.');
        }
        if ($scheme !== 'https' && !self::isLoopbackHost($host) && !$allowPrivateNetwork) {
            throw new RuntimeException('Outbound HTTP is limited to localhost testing unless private-network delivery is explicitly trusted.');
        }

        $addresses = self::resolveAddresses($host);
        $loopbackDevelopment = $scheme === 'http' && $allowHttp && self::isLoopbackHost($host);
        if (!$allowPrivateNetwork && !$loopbackDevelopment) {
            foreach ($addresses as $address) {
                self::assertPublicAddress($address);
            }
        }

        $parts['scheme'] = $scheme;
        $parts['host'] = $host;
        $parts['port'] = $port;
        $parts['addresses'] = $addresses;
        return $parts;
    }

    /** @return array<int, string> */
    private static function resolveAddresses(string $host, ?callable $resolver = null): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [self::canonicalAddress($host)];
        }
        if (self::isLoopbackHost($host)) {
            return ['127.0.0.1'];
        }
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            throw new RuntimeException('Outbound URL host is invalid.');
        }

        $addresses = [];
        if ($resolver !== null) {
            $resolved = $resolver($host);
            if (!is_array($resolved)) {
                throw new RuntimeException('Outbound URL resolver returned an invalid result.');
            }
            foreach ($resolved as $address) {
                if (!is_string($address)) {
                    throw new RuntimeException('Outbound URL resolver returned an invalid address.');
                }
                $addresses[self::canonicalAddress($address)] = true;
                if (count($addresses) > 32) {
                    throw new RuntimeException('Outbound URL resolved to too many addresses.');
                }
            }
        } elseif (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($address !== '') {
                        $addresses[self::canonicalAddress($address)] = true;
                    }
                }
            }
        }
        if ($addresses === [] && $resolver === null) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                foreach ($ipv4 as $address) {
                    $addresses[self::canonicalAddress((string) $address)] = true;
                }
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Outbound URL host could not be resolved.');
        }
        $addresses = array_keys($addresses);
        sort($addresses, SORT_STRING);
        return $addresses;
    }

    private static function assertPublicAddress(string $address): void
    {
        if (!self::isPublicAddress($address)) {
            throw new RuntimeException('Outbound URL cannot target localhost or a private network.');
        }
    }

    private static function isPublicAddress(string $address): bool
    {
        $address = self::canonicalAddress($address);
        $mapped = self::mappedIpv4($address);
        if ($mapped !== null) {
            return self::isPublicAddress($mapped);
        }
        if (self::isAlwaysForbiddenAddress($address)) {
            return false;
        }
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private static function isAlwaysForbiddenAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $value = ip2long($address);
            if ($value === false) {
                return true;
            }
            $unsigned = (int) sprintf('%u', $value);
            return ($unsigned <= 0x00FFFFFF)
                || ($unsigned >= 0x0A000000 && $unsigned <= 0x0AFFFFFF)
                || ($unsigned >= 0x64400000 && $unsigned <= 0x647FFFFF)
                || ($unsigned >= 0x7F000000 && $unsigned <= 0x7FFFFFFF)
                || ($unsigned >= 0xA9FE0000 && $unsigned <= 0xA9FEFFFF)
                || ($unsigned >= 0xAC100000 && $unsigned <= 0xAC1FFFFF)
                || ($unsigned >= 0xC0000000 && $unsigned <= 0xC00000FF)
                || ($unsigned >= 0xC0000200 && $unsigned <= 0xC00002FF)
                || ($unsigned >= 0xC0A80000 && $unsigned <= 0xC0A8FFFF)
                || ($unsigned >= 0xC6120000 && $unsigned <= 0xC613FFFF)
                || ($unsigned >= 0xC6336400 && $unsigned <= 0xC63364FF)
                || ($unsigned >= 0xCB007100 && $unsigned <= 0xCB0071FF)
                || ($unsigned >= 0xE0000000);
        }
        $packed = @inet_pton($address);
        if (!is_string($packed) || strlen($packed) !== 16) {
            return true;
        }
        foreach ([
            ['::', 96],
            ['64:ff9b::', 96],
            ['64:ff9b:1::', 48],
            ['100::', 64],
            ['2001::', 23],
            ['2001:db8::', 32],
            ['2002::', 16],
            ['3fff::', 20],
            ['5f00::', 16],
            ['fc00::', 7],
            ['fe80::', 10],
            ['fec0::', 10],
            ['ff00::', 8],
        ] as [$network, $prefix]) {
            if (self::addressInPrefix($packed, (string) $network, (int) $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function isApprovedPrivateAddress(string $address): bool
    {
        $address = self::canonicalAddress($address);
        $mapped = self::mappedIpv4($address);
        if ($mapped !== null) {
            return self::isApprovedPrivateAddress($mapped);
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $value = ip2long($address);
            if ($value === false) {
                return false;
            }
            $unsigned = (int) sprintf('%u', $value);
            return ($unsigned >= 0x0A000000 && $unsigned <= 0x0AFFFFFF)
                || ($unsigned >= 0xAC100000 && $unsigned <= 0xAC1FFFFF)
                || ($unsigned >= 0xC0A80000 && $unsigned <= 0xC0A8FFFF);
        }
        $packed = @inet_pton($address);
        return is_string($packed)
            && strlen($packed) === 16
            && (ord($packed[0]) & 0xFE) === 0xFC;
    }

    private static function canonicalAddress(string $address): string
    {
        if ($address === '' || str_contains($address, '%')) {
            throw new RuntimeException('Outbound URL resolved to an invalid address.');
        }
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            throw new RuntimeException('Outbound URL resolved to an invalid address.');
        }
        $canonical = @inet_ntop($packed);
        if (!is_string($canonical)) {
            throw new RuntimeException('Outbound URL resolved to an invalid address.');
        }
        return strtolower($canonical);
    }

    private static function mappedIpv4(string $address): ?string
    {
        $packed = @inet_pton($address);
        if (!is_string($packed) || strlen($packed) !== 16
            || substr($packed, 0, 10) !== str_repeat("\0", 10)
            || substr($packed, 10, 2) !== "\xff\xff") {
            return null;
        }
        $mapped = @inet_ntop(substr($packed, 12, 4));
        return is_string($mapped) ? $mapped : null;
    }

    private static function addressInPrefix(string $packedAddress, string $network, int $prefixBits): bool
    {
        $packedNetwork = @inet_pton($network);
        if (!is_string($packedNetwork) || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }
        $wholeBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;
        if ($wholeBytes > 0
            && !hash_equals(substr($packedNetwork, 0, $wholeBytes), substr($packedAddress, 0, $wholeBytes))) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($packedNetwork[$wholeBytes]) & $mask) === (ord($packedAddress[$wholeBytes]) & $mask);
    }

    /** @return list<int> */
    private static function webhookAllowedPorts(): array
    {
        $raw = trim((string) (getenv('CPE_WEBHOOK_ALLOWED_PORTS') ?: '443'));
        if (preg_match('/^[0-9]{1,5}(?:,[0-9]{1,5}){0,15}$/D', $raw) !== 1) {
            throw new RuntimeException('CPE_WEBHOOK_ALLOWED_PORTS must be a comma-separated list of at most 16 ports.');
        }
        $ports = array_values(array_unique(array_map('intval', explode(',', $raw))));
        foreach ($ports as $port) {
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('CPE_WEBHOOK_ALLOWED_PORTS contains an invalid port.');
            }
        }
        return $ports;
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
