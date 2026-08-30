<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Security\OutboundHttpPolicy;
use RuntimeException;

final class CurlWebhookHttpTransport implements WebhookHttpTransport
{
    /** @param null|callable(string): array<int, string> $resolver */
    public function __construct(
        private readonly mixed $resolver = null,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $totalTimeoutSeconds = 15,
    ) {
        if ($resolver !== null && !is_callable($resolver)) {
            throw new RuntimeException('Webhook resolver must be callable.');
        }
    }

    public function send(
        string $endpointUrl,
        string $body,
        array $headers,
        bool $allowPrivateNetwork,
    ): WebhookHttpResult {
        try {
            $target = OutboundHttpPolicy::assertWebhookAllowed(
                $endpointUrl,
                $allowPrivateNetwork,
                $this->resolver,
            );
        } catch (\Throwable $failure) {
            throw new WebhookTransportException(WebhookTransportException::POLICY, false, $failure);
        }
        if (!function_exists('curl_init')) {
            throw new WebhookTransportException(WebhookTransportException::POLICY, false);
        }
        if (strlen($body) > 1048576) {
            throw new WebhookTransportException(WebhookTransportException::POLICY, false);
        }
        if (count($headers) > 20) {
            throw new WebhookTransportException(WebhookTransportException::POLICY, false);
        }
        $headerBytes = 0;
        foreach ($headers as $header) {
            $headerBytes += strlen($header);
            if (strlen($header) > 2048 || str_contains($header, "\r") || str_contains($header, "\n")) {
                throw new WebhookTransportException(WebhookTransportException::POLICY, false);
            }
        }
        if ($headerBytes > 16384) {
            throw new WebhookTransportException(WebhookTransportException::POLICY, false);
        }

        $handle = curl_init((string) $target['request_url']);
        if ($handle === false) {
            throw new WebhookTransportException(WebhookTransportException::NETWORK, true);
        }
        $responseBytes = 0;
        $responseHeaderBytes = 0;
        $responseTotalBytes = 0;
        $responseTooLarge = false;
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => max(1, min(15, $this->connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, min(30, $this->totalTimeoutSeconds)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => ((string) $target['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP),
            CURLOPT_REDIR_PROTOCOLS => ((string) $target['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP),
            CURLOPT_PROXY => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use (&$responseBytes, &$responseTotalBytes, &$responseTooLarge): int {
                $responseBytes += strlen($chunk);
                $responseTotalBytes += strlen($chunk);
                if ($responseBytes > 1048576 || $responseTotalBytes > 1048576) {
                    $responseTooLarge = true;
                    return 0;
                }
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$responseHeaderBytes, &$responseTotalBytes, &$responseTooLarge): int {
                $responseHeaderBytes += strlen($line);
                $responseTotalBytes += strlen($line);
                if ($responseHeaderBytes > 65536 || $responseTotalBytes > 1048576) {
                    $responseTooLarge = true;
                    return 0;
                }
                return strlen($line);
            },
        ];
        $host = (string) $target['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $address = (string) $target['addresses'][0];
            if (str_contains($address, ':')) {
                $address = '[' . $address . ']';
            }
            $options[CURLOPT_RESOLVE] = [$host . ':' . (int) $target['port'] . ':' . $address];
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($handle);
        unset($handle);
        if ($responseTooLarge) {
            throw new WebhookTransportException(WebhookTransportException::RESPONSE_TOO_LARGE, false);
        }
        if ($ok === false) {
            if ($errorCode === CURLE_OPERATION_TIMEDOUT) {
                throw new WebhookTransportException(WebhookTransportException::TIMEOUT, true);
            }
            if (in_array($errorCode, self::tlsErrorCodes(), true)) {
                throw new WebhookTransportException(WebhookTransportException::TLS, false);
            }
            throw new WebhookTransportException(WebhookTransportException::NETWORK, true);
        }
        return new WebhookHttpResult($status);
    }

    /** @return list<int> */
    private static function tlsErrorCodes(): array
    {
        $names = [
            'CURLE_SSL_CONNECT_ERROR', 'CURLE_PEER_FAILED_VERIFICATION', 'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER', 'CURLE_SSL_CACERT_BADFILE', 'CURLE_SSL_PINNEDPUBKEYNOTMATCH',
            'CURLE_SSL_INVALIDCERTSTATUS',
        ];
        $codes = [];
        foreach ($names as $name) {
            if (defined($name)) {
                $codes[] = (int) constant($name);
            }
        }
        return $codes;
    }
}
