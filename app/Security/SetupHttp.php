<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Http\UserVisibleException;

final class SetupHttp
{
    public const INTERNAL_CAPABILITY_ENV = 'CPE_INTERNAL_SETUP_CAPABILITY';
    public const INTERNAL_ADDRESS_ENV = 'CPE_INTERNAL_SETUP_ADDRESS';
    public const INTERNAL_EXPIRES_ENV = 'CPE_INTERNAL_SETUP_EXPIRES';

    /**
     * @return array<string, mixed>
     */
    public static function installInput(array $post): array
    {
        static $allowed = [
            'college_name' => true,
            'site_name' => true,
            'site_tagline' => true,
            'public_placements_title' => true,
            'candidate_status_title' => true,
            'timezone' => true,
            'cycle_name' => true,
            'cycle_type' => true,
            'cycle_start_date' => true,
            'cycle_end_date' => true,
            'calendar_non_operating_weekdays' => true,
            'calendar_non_operating_dates' => true,
            'audit_request_metadata' => true,
            'workflow' => true,
            'terminology_candidate_label' => true,
            'terminology_candidates_label' => true,
            'terminology_company_label' => true,
            'terminology_companies_label' => true,
            'admin_name' => true,
            'admin_email' => true,
            'admin_password' => true,
            'seed_demo' => true,
        ];
        $input = array_intersect_key($post, $allowed);
        foreach ($input as $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                throw new UserVisibleException(
                    'SETUP_FORM_INVALID',
                    'The setup form contains an invalid value. Please retry.',
                );
            }
        }
        return $input;
    }

    public static function environmentUnlockTransportAllowed(array $server): bool
    {
        if (self::directHttpsDetected($server)) {
            return true;
        }
        return strtolower((string) (getenv('CPE_SESSION_SECURE') ?: '')) === 'force';
    }

    public static function localCapabilityModeAvailable(array $server, ?int $now = null): bool
    {
        $capability = getenv(self::INTERNAL_CAPABILITY_ENV);
        $expires = getenv(self::INTERNAL_EXPIRES_ENV);
        if (!is_string($capability)
            || !is_string($expires)
            || preg_match('/\A[1-9][0-9]{0,10}\z/D', $expires) !== 1
            || (int) $expires <= ($now ?? time())) {
            return false;
        }
        return self::localTopologyAllowed($server);
    }

    public static function localTopologyAllowed(array $server): bool
    {
        $authority = getenv(self::INTERNAL_ADDRESS_ENV);
        if (!is_string($authority)
            || PHP_SAPI !== 'cli-server'
            || !self::isLoopbackAddress($server['REMOTE_ADDR'] ?? null)) {
            return false;
        }
        if (array_key_exists('SERVER_ADDR', $server)
            && !self::isLoopbackAddress($server['SERVER_ADDR'])) {
            return false;
        }
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($host) || !hash_equals($authority, $host)) {
            return false;
        }
        return true;
    }

    public static function normalizeSetupAddress(mixed $address): string
    {
        if (!is_string($address)
            || preg_match('/\A(127\.0\.0\.1|localhost):([0-9]{1,5})\z/D', $address, $matches) !== 1) {
            throw new UserVisibleException(
                'CLI_SETUP_ADDRESS_INVALID',
                'Setup address must be 127.0.0.1:PORT or localhost:PORT; proxy, LAN, wildcard, and public binds are unsupported.',
            );
        }
        $port = (int) $matches[2];
        if ($port < 1 || $port > 65535) {
            throw new UserVisibleException('CLI_SETUP_PORT_INVALID', 'Setup port must be between 1 and 65535.');
        }
        return '127.0.0.1:' . $port;
    }

    public static function generateInternalCapability(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function setInternalEnvironment(string $capability, string $address, int $expiresAt): void
    {
        putenv(self::INTERNAL_CAPABILITY_ENV . '=' . $capability);
        putenv(self::INTERNAL_ADDRESS_ENV . '=' . $address);
        putenv(self::INTERNAL_EXPIRES_ENV . '=' . $expiresAt);
        $_ENV[self::INTERNAL_CAPABILITY_ENV] = $capability;
        $_ENV[self::INTERNAL_ADDRESS_ENV] = $address;
        $_ENV[self::INTERNAL_EXPIRES_ENV] = (string) $expiresAt;
    }

    public static function scrubInternalEnvironment(): void
    {
        putenv(self::INTERNAL_CAPABILITY_ENV);
        putenv(self::INTERNAL_ADDRESS_ENV);
        putenv(self::INTERNAL_EXPIRES_ENV);
        unset(
            $_ENV[self::INTERNAL_CAPABILITY_ENV],
            $_ENV[self::INTERNAL_ADDRESS_ENV],
            $_ENV[self::INTERNAL_EXPIRES_ENV],
            $_SERVER[self::INTERNAL_CAPABILITY_ENV],
            $_SERVER[self::INTERNAL_ADDRESS_ENV],
            $_SERVER[self::INTERNAL_EXPIRES_ENV],
        );
    }

    public static function directHttpsDetected(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && in_array(strtolower($https), ['on', '1', 'true'], true)) {
            return true;
        }
        $port = $server['SERVER_PORT'] ?? '';
        return (is_string($port) || is_int($port)) && (string) $port === '443';
    }

    public static function isLoopbackAddress(mixed $address): bool
    {
        if (!is_string($address)) {
            return false;
        }
        $binary = @inet_pton($address);
        if (!is_string($binary)) {
            return false;
        }
        if (strlen($binary) === 4) {
            return ord($binary[0]) === 127;
        }
        if (strlen($binary) !== 16) {
            return false;
        }
        if (hash_equals(str_repeat("\0", 15) . "\1", $binary)) {
            return true;
        }
        return hash_equals(str_repeat("\0", 10) . "\xff\xff", substr($binary, 0, 12))
            && ord($binary[12]) === 127;
    }
}
