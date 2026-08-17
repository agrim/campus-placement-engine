<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class OutboundHttpPolicy
{
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
    private static function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (self::isLoopbackHost($host)) {
            return ['127.0.0.1'];
        }
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            throw new RuntimeException('Outbound URL host is invalid.');
        }

        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($address !== '') {
                        $addresses[$address] = true;
                    }
                }
            }
        }
        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                foreach ($ipv4 as $address) {
                    $addresses[(string) $address] = true;
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
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            throw new RuntimeException('Outbound URL cannot target localhost or a private network.');
        }
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
