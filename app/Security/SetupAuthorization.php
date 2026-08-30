<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Database;
use Closure;
use JsonException;
use Throwable;

/**
 * Versioned first-run setup authorization core.
 *
 * This class intentionally owns no HTTP rendering or installation behavior.
 * Callers establish a grant with one of the explicit authorization methods and
 * execute the installation boundary through runAuthorized().
 */
final class SetupAuthorization
{
    public const CONTRACT_VERSION = 1;
    public const GRANT_TTL_SECONDS = 1200;
    public const MODE_ENVIRONMENT_TOKEN = 'environment-token';
    public const MODE_LOCAL = 'local';
    public const ACCESS_LOCKED = 'locked';
    public const ACCESS_AUTHORIZED = 'authorized';
    public const ACCESS_EXPIRED = 'expired';
    public const ACCESS_CONSUMED = 'consumed';

    private const SESSION_KEY = 'cpe_setup_authorization_v1';
    private const TOKEN_MIN_LENGTH = 43;
    private const TOKEN_MAX_LENGTH = 128;
    private const TOKEN_MIN_BYTES = 32;
    private const STATE_MAX_BYTES = 8192;
    private const TOKEN_COMPARE_DOMAIN = "cpe.setup.token.compare.v1";
    private const SOURCE_PROOF_DOMAIN = "cpe.setup.source-proof.v1";
    private const GRANT_HASH_DOMAIN = "cpe.setup.grant-hash.v1";
    private const FINGERPRINT_DOMAIN = "cpe.setup.fingerprint.v1";
    private const LEASE_ACTIVE = 'active';

    /** @var array<string, mixed> */
    private array $session;

    /** @var array<string, mixed> */
    private array $server;

    private ?string $environmentDigest = null;
    private ?string $environmentKey = null;
    private ?string $localDigest = null;
    private ?string $localKey = null;
    private string $stateDirectory;
    private string $targetKey;
    private Closure $clock;
    private Closure $randomBytes;
    private Closure $sessionIdProvider;
    private Closure $sessionRegenerator;
    private Closure $csrfRotator;

    /**
     * Secrets are accepted as mixed so array-shaped request/config values are
     * rejected explicitly instead of being coerced into strings.
     *
     * @param array<string, mixed>|null $session
     * @param array<string, mixed>|null $server
     */
    public function __construct(
        mixed $environmentToken = null,
        mixed $localCapability = null,
        ?string $stateDirectory = null,
        ?string $targetIdentity = null,
        ?array &$session = null,
        ?array $server = null,
        ?callable $clock = null,
        ?callable $randomBytes = null,
        ?callable $sessionIdProvider = null,
        ?callable $sessionRegenerator = null,
        ?callable $csrfRotator = null,
    ) {
        if ($environmentToken === null) {
            $configured = getenv('CPE_SETUP_TOKEN');
            $environmentToken = $configured === false ? null : $configured;
        }

        if ($environmentToken !== null) {
            [$this->environmentDigest, $this->environmentKey] = $this->prepareCredential(
                $environmentToken,
            );
        }
        if ($localCapability !== null) {
            [$this->localDigest, $this->localKey] = $this->prepareCredential(
                $localCapability,
            );
        }

        $this->stateDirectory = $stateDirectory ?? self::defaultStateDirectory();
        if ($this->stateDirectory === '' || !str_starts_with($this->stateDirectory, DIRECTORY_SEPARATOR)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_CONFIGURATION);
        }

        $identity = $targetIdentity ?? self::defaultTargetIdentity();
        if ($identity === '' || strlen($identity) > 4096) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_CONFIGURATION);
        }
        $this->targetKey = hash('sha256', $identity);

        if ($session === null) {
            $this->session =& $_SESSION;
        } else {
            $this->session =& $session;
        }
        $this->server = $server ?? $_SERVER;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->randomBytes = $randomBytes === null
            ? static fn (int $length): string => random_bytes($length)
            : Closure::fromCallable($randomBytes);
        $this->sessionIdProvider = $sessionIdProvider === null
            ? static fn (): string => session_id()
            : Closure::fromCallable($sessionIdProvider);
        $this->sessionRegenerator = $sessionRegenerator === null
            ? static function (): void {
                // No setup authorization grant is assigned until rotation has
                // completed, so the retained pre-rotation ID stays unauthorized.
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    throw new SetupAuthorizationDenied(
                        SetupAuthorizationDenied::STATE_UNAVAILABLE,
                        SetupSessionRotationFailure::sessionNotActive(),
                    );
                }
                if (headers_sent()) {
                    throw new SetupAuthorizationDenied(
                        SetupAuthorizationDenied::STATE_UNAVAILABLE,
                        SetupSessionRotationFailure::responseStarted(),
                    );
                }
                $warningFailure = null;
                set_error_handler(static function (int $_severity, string $message) use (&$warningFailure): bool {
                    $candidate = SetupSessionRotationFailure::fromPhpWarning($message);
                    if ($warningFailure === null
                        || ($warningFailure->phase() === SetupSessionRotationFailure::WARNING_OTHER
                            && $candidate->phase() !== SetupSessionRotationFailure::WARNING_OTHER)) {
                        $warningFailure = $candidate;
                    }
                    return true;
                }, E_WARNING);
                try {
                    try {
                        $rotated = session_regenerate_id(false);
                    } catch (Throwable) {
                        throw new SetupAuthorizationDenied(
                            SetupAuthorizationDenied::STATE_UNAVAILABLE,
                            SetupSessionRotationFailure::threw(),
                        );
                    }
                } finally {
                    restore_error_handler();
                }
                if (!$rotated || $warningFailure instanceof SetupSessionRotationFailure) {
                    throw new SetupAuthorizationDenied(
                        SetupAuthorizationDenied::STATE_UNAVAILABLE,
                        $warningFailure ?? SetupSessionRotationFailure::returnedFalse(),
                    );
                }
            }
            : Closure::fromCallable($sessionRegenerator);
        $this->csrfRotator = $csrfRotator === null
            ? static function (): void {
                Csrf::rotate();
            }
            : Closure::fromCallable($csrfRotator);
    }

    /**
     * Returns only non-secret grant metadata.
     *
     * @return array{state: string, mode?: string, issued?: int, expires?: int}
     */
    public function accessState(): array
    {
        $grant = $this->sessionGrant();
        if ($grant === null) {
            return ['state' => self::ACCESS_LOCKED];
        }

        $now = $this->now();
        if ($now < $grant['issued'] || $now >= $grant['expires']) {
            $this->clear();
            return ['state' => self::ACCESS_EXPIRED];
        }
        if (!$this->fingerprintMatches($grant)) {
            $this->clear();
            return ['state' => self::ACCESS_LOCKED];
        }
        if (!$this->stateFileExists()) {
            $this->clear();
            return ['state' => self::ACCESS_LOCKED];
        }

        return $this->withStateLock(function ($handle, bool $created) use ($grant): array {
            $lease = $this->readState($handle, $created);
            $now = $this->now();
            if ($lease['state'] === self::ACCESS_CONSUMED) {
                $this->clear();
                return ['state' => self::ACCESS_CONSUMED];
            }
            if ($now < $lease['issued'] || $now >= $lease['expires']) {
                $this->clear();
                return ['state' => self::ACCESS_EXPIRED];
            }
            if (!$this->leaseMatchesGrant($lease, $grant)) {
                $this->clear();
                return ['state' => self::ACCESS_LOCKED];
            }
            return $this->publicGrantState(self::ACCESS_AUTHORIZED, $grant);
        }, false);
    }

    /**
     * Exchanges the deployer-provisioned environment token for a browser grant.
     *
     * @return array{state: string, mode: string, issued: int, expires: int}
     */
    public function unlockWithEnvironmentToken(mixed $provided): array
    {
        $key = $this->matchCredential($provided, $this->environmentDigest, $this->environmentKey);
        return $this->authorizeSource(self::MODE_ENVIRONMENT_TOKEN, $key);
    }

    /**
     * Authorizes a local setup flow whose caller possesses an internal CLI
     * capability. A4.2 owns how that capability reaches the trusted seam.
     *
     * @return array{state: string, mode: string, issued: int, expires: int}
     */
    public function authorizeLocalCapability(mixed $provided): array
    {
        $key = $this->matchCredential($provided, $this->localDigest, $this->localKey);
        return $this->authorizeSource(self::MODE_LOCAL, $key);
    }

    /**
     * Runs the installation boundary while holding the target lease. An
     * exception leaves the grant active for an explicit retry; a successful
     * return consumes it before releasing the lock.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function runAuthorized(callable $callback): mixed
    {
        $grant = $this->requireCurrentGrant();
        if (!$this->stateFileExists()) {
            $this->clear();
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }

        return $this->withStateLock(function ($handle, bool $created) use ($grant, $callback): mixed {
            $lease = $this->readState($handle, $created);
            $now = $this->now();
            if ($lease['state'] !== self::LEASE_ACTIVE
                || $now < $lease['issued']
                || $now >= $lease['expires']
                || !$this->leaseMatchesGrant($lease, $grant)) {
                $this->clear();
                throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
            }

            $result = $callback();
            $this->writeState($handle, [
                'version' => self::CONTRACT_VERSION,
                'state' => self::ACCESS_CONSUMED,
            ]);
            $this->clear();
            return $result;
        }, false);
    }

    public function consume(): void
    {
        $this->runAuthorized(static fn (): null => null);
    }

    public function issueRecoveryAuthority(): SetupRecoveryAuthority
    {
        return SetupRecoveryAuthority::afterSetupAuthorization($this);
    }

    /** @internal Used only to mint a target-bound SetupRecoveryAuthority. */
    public function recoveryAuthorityTargetKey(): string
    {
        if (($this->accessState()['state'] ?? '') !== self::ACCESS_AUTHORIZED) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }
        return $this->targetKey;
    }

    /** Clears only the caller's session grant. The global lease is untouched. */
    public function clear(): void
    {
        unset($this->session[self::SESSION_KEY]);
    }

    private function authorizeSource(string $mode, string $sourceKey): array
    {
        $sourceProof = $this->sourceProof($mode, $sourceKey);
        return $this->withStateLock(function ($handle, bool $created) use ($mode, $sourceProof): array {
            $lease = $this->readState($handle, $created);
            $now = $this->now();
            if ($lease !== null && $lease['state'] === self::ACCESS_CONSUMED) {
                $this->clear();
                throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
            }
            if ($lease !== null && $lease['state'] === self::LEASE_ACTIVE && $now < $lease['issued']) {
                $this->clear();
                throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
            }
            if ($lease !== null
                && $lease['state'] === self::LEASE_ACTIVE
                && $now >= $lease['issued']
                && $now < $lease['expires']) {
                if (hash_equals($lease['source_proof'], $sourceProof)) {
                    $grant = $this->sessionGrant();
                    if ($grant !== null
                        && $this->fingerprintMatches($grant)
                        && $this->leaseMatchesGrant($lease, $grant)) {
                        return $this->publicGrantState(self::ACCESS_AUTHORIZED, $grant);
                    }
                    $this->clear();
                    throw new SetupAuthorizationDenied(SetupAuthorizationDenied::ACTIVE_LEASE);
                }
                // A current, valid but rotated deployer secret supersedes the
                // lease created with its predecessor.
            }

            $sessionIdBefore = ($this->sessionIdProvider)();
            if (!$this->isValidSessionId($sessionIdBefore)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::sessionFingerprint(),
                );
            }
            ($this->sessionRegenerator)();
            $sessionIdAfter = ($this->sessionIdProvider)();
            if (!$this->isValidSessionId($sessionIdAfter) || hash_equals($sessionIdBefore, $sessionIdAfter)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::sessionFingerprint(),
                );
            }
            ($this->csrfRotator)();
            $grantId = self::base64UrlEncode($this->secureRandom(self::TOKEN_MIN_BYTES));
            $issued = $now;
            $expires = $issued + self::GRANT_TTL_SECONDS;
            $fingerprint = $this->fingerprint($grantId);
            $grant = [
                'version' => self::CONTRACT_VERSION,
                'mode' => $mode,
                'issued' => $issued,
                'expires' => $expires,
                'fingerprint' => $fingerprint,
                // The opaque random grant identifier occupies the versioned
                // session state's only secret-bearing field.
                'state' => $grantId,
            ];
            $lease = [
                'version' => self::CONTRACT_VERSION,
                'state' => self::LEASE_ACTIVE,
                'mode' => $mode,
                'grant_hash' => $this->grantHash($grantId),
                'source_proof' => $sourceProof,
                'fingerprint' => $fingerprint,
                'issued' => $issued,
                'expires' => $expires,
            ];
            $this->writeState($handle, $lease);
            $this->session[self::SESSION_KEY] = $grant;
            return $this->publicGrantState(self::ACCESS_AUTHORIZED, $grant);
        }, true);
    }

    /**
     * @return array{version: int, mode: string, issued: int, expires: int, fingerprint: string, state: string}|null
     */
    private function sessionGrant(): ?array
    {
        if (!array_key_exists(self::SESSION_KEY, $this->session)) {
            return null;
        }
        $grant = $this->session[self::SESSION_KEY];
        if (!is_array($grant)
            || !$this->hasExactKeys($grant, ['version', 'mode', 'issued', 'expires', 'fingerprint', 'state'])
            || ($grant['version'] ?? null) !== self::CONTRACT_VERSION
            || !in_array($grant['mode'] ?? null, [self::MODE_ENVIRONMENT_TOKEN, self::MODE_LOCAL], true)
            || !is_int($grant['issued'] ?? null)
            || !is_int($grant['expires'] ?? null)
            || $grant['issued'] < 0
            || $grant['expires'] !== $grant['issued'] + self::GRANT_TTL_SECONDS
            || !is_string($grant['fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $grant['fingerprint']) !== 1
            || !$this->isCanonicalCredential($grant['state'] ?? null)) {
            $this->clear();
            return null;
        }
        return $grant;
    }

    /**
     * @return array{version: int, mode: string, issued: int, expires: int, fingerprint: string, state: string}
     */
    private function requireCurrentGrant(): array
    {
        $grant = $this->sessionGrant();
        if ($grant === null) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }
        $now = $this->now();
        if ($now < $grant['issued'] || $now >= $grant['expires']) {
            $this->clear();
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }
        if (!$this->fingerprintMatches($grant)) {
            $this->clear();
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::CALLER_MISMATCH);
        }
        return $grant;
    }

    /**
     * @param array<string, mixed> $lease
     * @param array{version: int, mode: string, issued: int, expires: int, fingerprint: string, state: string} $grant
     */
    private function leaseMatchesGrant(array $lease, array $grant): bool
    {
        $sourceProof = $this->currentSourceProof($grant['mode']);
        return $lease['state'] === self::LEASE_ACTIVE
            && $lease['version'] === $grant['version']
            && $lease['mode'] === $grant['mode']
            && $lease['issued'] === $grant['issued']
            && $lease['expires'] === $grant['expires']
            && hash_equals($lease['grant_hash'], $this->grantHash($grant['state']))
            && hash_equals($lease['fingerprint'], $grant['fingerprint'])
            && $sourceProof !== null
            && hash_equals($lease['source_proof'], $sourceProof);
    }

    /** @param array{fingerprint: string, state: string} $grant */
    private function fingerprintMatches(array $grant): bool
    {
        try {
            return hash_equals($grant['fingerprint'], $this->fingerprint($grant['state']));
        } catch (SetupAuthorizationDenied) {
            return false;
        }
    }

    private function currentSourceProof(string $mode): ?string
    {
        return match ($mode) {
            self::MODE_ENVIRONMENT_TOKEN => $this->environmentKey === null
                ? null
                : $this->sourceProof($mode, $this->environmentKey),
            self::MODE_LOCAL => $this->localKey === null
                ? null
                : $this->sourceProof($mode, $this->localKey),
            default => null,
        };
    }

    /** @return array{0: string, 1: string} */
    private function prepareCredential(mixed $credential): array
    {
        $decoded = $this->decodeCredential($credential, SetupAuthorizationDenied::INVALID_CONFIGURATION);
        return [$this->credentialDigest($credential), $decoded];
    }

    private function matchCredential(mixed $provided, ?string $expectedDigest, ?string $key): string
    {
        $decoded = $this->decodeCredential($provided, SetupAuthorizationDenied::INVALID_CREDENTIAL);
        $providedDigest = $this->credentialDigest($provided);
        $comparisonDigest = $expectedDigest ?? str_repeat("\0", 32);
        $matches = hash_equals($comparisonDigest, $providedDigest);
        if (!$matches || $expectedDigest === null || $key === null) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_CREDENTIAL);
        }
        // The decoded provided value must also match the configured key. This
        // fixed-length comparison makes textual and decoded validation agree.
        if (!hash_equals(hash('sha256', $key, true), hash('sha256', $decoded, true))) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_CREDENTIAL);
        }
        return $key;
    }

    private function decodeCredential(mixed $credential, string $failureReason): string
    {
        if (!$this->isCanonicalCredential($credential)) {
            throw new SetupAuthorizationDenied($failureReason);
        }
        $padding = (4 - (strlen($credential) % 4)) % 4;
        $decoded = base64_decode(strtr($credential, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)
            || strlen($decoded) < self::TOKEN_MIN_BYTES
            || !hash_equals($credential, self::base64UrlEncode($decoded))) {
            throw new SetupAuthorizationDenied($failureReason);
        }
        return $decoded;
    }

    private function isCanonicalCredential(mixed $credential): bool
    {
        return is_string($credential)
            && strlen($credential) >= self::TOKEN_MIN_LENGTH
            && strlen($credential) <= self::TOKEN_MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_-]+$/D', $credential) === 1;
    }

    private function credentialDigest(string $credential): string
    {
        return hash('sha256', self::TOKEN_COMPARE_DOMAIN . "\0" . $credential, true);
    }

    private function sourceProof(string $mode, string $key): string
    {
        return hash_hmac(
            'sha256',
            self::SOURCE_PROOF_DOMAIN . "\0" . $mode . "\0" . $this->targetKey,
            $key,
        );
    }

    private function grantHash(string $grantId): string
    {
        return hash('sha256', self::GRANT_HASH_DOMAIN . "\0" . $grantId);
    }

    private function fingerprint(string $grantId): string
    {
        $sessionId = ($this->sessionIdProvider)();
        if (!$this->isValidSessionId($sessionId)) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::sessionFingerprint(),
            );
        }
        $remoteAddress = $this->server['REMOTE_ADDR'] ?? '';
        if (!is_string($remoteAddress)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::CALLER_MISMATCH);
        }
        $addressBytes = @inet_pton($remoteAddress);
        if (!is_string($addressBytes)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::CALLER_MISMATCH);
        }
        $userAgent = $this->server['HTTP_USER_AGENT'] ?? '';
        if (!is_string($userAgent)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::CALLER_MISMATCH);
        }
        $userAgent = preg_replace('/[\x00-\x20\x7F]+/', ' ', $userAgent);
        if (!is_string($userAgent)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::CALLER_MISMATCH);
        }
        $userAgent = substr(trim($userAgent), 0, 256);

        $material = self::FINGERPRINT_DOMAIN . "\0"
            . self::lengthPrefix((string) self::CONTRACT_VERSION)
            . self::lengthPrefix($sessionId)
            . self::lengthPrefix($grantId)
            . self::lengthPrefix($addressBytes)
            . self::lengthPrefix($userAgent);
        return hash('sha256', $material);
    }

    private function isValidSessionId(mixed $sessionId): bool
    {
        return is_string($sessionId) && $sessionId !== '' && strlen($sessionId) <= 256;
    }

    private static function lengthPrefix(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function secureRandom(int $length): string
    {
        $bytes = ($this->randomBytes)($length);
        if (!is_string($bytes) || strlen($bytes) !== $length) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::STATE_UNAVAILABLE);
        }
        return $bytes;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - self::GRANT_TTL_SECONDS) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::STATE_UNAVAILABLE);
        }
        return $now;
    }

    /**
     * @param array<string, mixed> $grant
     * @return array{state: string, mode: string, issued: int, expires: int}
     */
    private function publicGrantState(string $state, array $grant): array
    {
        return [
            'state' => $state,
            'mode' => $grant['mode'],
            'issued' => $grant['issued'],
            'expires' => $grant['expires'],
        ];
    }

    private function stateFileExists(): bool
    {
        $stat = @lstat($this->statePath());
        if ($stat === false) {
            return false;
        }
        $this->assertRegularStateStat($stat);
        return true;
    }

    /**
     * @template T
     * @param callable(resource, bool): T $callback
     * @return T
     */
    private function withStateLock(callable $callback, bool $allowCreate): mixed
    {
        $this->ensureStateDirectory();
        $path = $this->statePath();
        $before = @lstat($path);
        $created = $before === false;
        if ($created && !$allowCreate) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::NOT_AUTHORIZED);
        }
        if (is_array($before)) {
            $this->assertRegularStateStat($before);
        }

        $priorUmask = umask(0077);
        try {
            $handle = @fopen($path, 'c+b');
        } finally {
            umask($priorUmask);
        }
        if (!is_resource($handle)) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::statePrepare(),
            );
        }
        $locked = false;
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($opened) || !is_array($after)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::statePrepare(),
                );
            }
            $this->assertRegularStateStat($opened);
            $this->assertRegularStateStat($after);
            if (!$this->sameFile($opened, $after)
                || (is_array($before) && !$this->sameFile($before, $opened))) {
                throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
            }
            if (!@flock($handle, LOCK_EX)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::statePrepare(),
                );
            }
            $locked = true;
            $lockedStat = fstat($handle);
            if (!is_array($lockedStat)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::statePrepare(),
                );
            }
            $this->assertRegularStateStat($lockedStat);
            if (!@chmod($path, 0600)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::statePermissions(),
                );
            }
            $permissions = fstat($handle);
            clearstatcache(true, $path);
            $permissionPath = @lstat($path);
            if (!is_array($permissions)
                || !is_array($permissionPath)
                || !$this->sameFile($permissions, $permissionPath)
                || (($permissions['mode'] ?? 0) & 0777) !== 0600
                || (($permissionPath['mode'] ?? 0) & 0777) !== 0600) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::statePermissions(),
                );
            }
            return $callback($handle, $created);
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function ensureStateDirectory(): void
    {
        $stat = @lstat($this->stateDirectory);
        if ($stat === false) {
            if (!@mkdir($this->stateDirectory, 0700, true) && !is_dir($this->stateDirectory)) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::statePrepare(),
                );
            }
            $stat = @lstat($this->stateDirectory);
        }
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
        if (!@chmod($this->stateDirectory, 0700)) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::statePermissions(),
            );
        }
        clearstatcache(true, $this->stateDirectory);
        $permissions = @lstat($this->stateDirectory);
        if (!is_array($permissions) || (($permissions['mode'] ?? 0) & 0777) !== 0700) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::statePermissions(),
            );
        }
    }

    /**
     * @param resource $handle
     * @return array<string, mixed>|null
     */
    private function readState($handle, bool $created): ?array
    {
        if (!rewind($handle)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::STATE_UNAVAILABLE);
        }
        $contents = stream_get_contents($handle, self::STATE_MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::STATE_UNAVAILABLE);
        }
        if (strlen($contents) > self::STATE_MAX_BYTES) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
        if ($contents === '') {
            if ($created) {
                return null;
            }
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
        try {
            $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE, $e);
        }
        if (!is_array($state)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
        return $this->validateState($state);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function validateState(array $state): array
    {
        if (($state['version'] ?? null) !== self::CONTRACT_VERSION
            || !is_string($state['state'] ?? null)) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
        if ($state['state'] === self::ACCESS_CONSUMED) {
            if (!$this->hasExactKeys($state, ['version', 'state'])) {
                throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
            }
            return $state;
        }
        if ($state['state'] !== self::LEASE_ACTIVE
            || !$this->hasExactKeys($state, [
                'version',
                'state',
                'mode',
                'grant_hash',
                'source_proof',
                'fingerprint',
                'issued',
                'expires',
            ])
            || !in_array($state['mode'] ?? null, [self::MODE_ENVIRONMENT_TOKEN, self::MODE_LOCAL], true)
            || !is_string($state['grant_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $state['grant_hash']) !== 1
            || !is_string($state['source_proof'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $state['source_proof']) !== 1
            || !is_string($state['fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $state['fingerprint']) !== 1
            || !is_int($state['issued'] ?? null)
            || !is_int($state['expires'] ?? null)
            || $state['issued'] < 0
            || $state['expires'] !== $state['issued'] + self::GRANT_TTL_SECONDS) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
        return $state;
    }

    /** @param resource $handle @param array<string, mixed> $state */
    private function writeState($handle, array $state): void
    {
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::stateWritePrepare(),
            );
        }
        if (strlen($json) > self::STATE_MAX_BYTES || !rewind($handle) || !ftruncate($handle, 0)) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::stateWritePrepare(),
            );
        }
        $offset = 0;
        $length = strlen($json);
        while ($offset < $length) {
            $written = fwrite($handle, substr($json, $offset));
            if (!is_int($written) || $written < 1) {
                throw new SetupAuthorizationDenied(
                    SetupAuthorizationDenied::STATE_UNAVAILABLE,
                    SetupAuthorizationStageFailure::stateWriteIo(),
                );
            }
            $offset += $written;
        }
        if (!fflush($handle) || !fsync($handle)) {
            throw new SetupAuthorizationDenied(
                SetupAuthorizationDenied::STATE_UNAVAILABLE,
                SetupAuthorizationStageFailure::stateSync(),
            );
        }
    }

    /** @param array<string, mixed> $state @param list<string> $expected */
    private function hasExactKeys(array $state, array $expected): bool
    {
        $actual = array_keys($state);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    /** @param array<string|int, mixed> $stat */
    private function assertRegularStateStat(array $stat): void
    {
        if ((($stat['mode'] ?? 0) & 0170000) !== 0100000
            || !is_int($stat['size'] ?? null)
            || $stat['size'] < 0
            || $stat['size'] > self::STATE_MAX_BYTES) {
            throw new SetupAuthorizationDenied(SetupAuthorizationDenied::INVALID_STATE);
        }
    }

    /** @param array<string|int, mixed> $left @param array<string|int, mixed> $right */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }

    private function statePath(): string
    {
        return $this->stateDirectory . DIRECTORY_SEPARATOR . $this->targetKey . '.json';
    }

    private static function defaultStateDirectory(): string
    {
        return defined('CPE_DATA')
            ? CPE_DATA . '/setup'
            : dirname(__DIR__, 2) . '/data/setup';
    }

    private static function defaultTargetIdentity(): string
    {
        $provider = Database::provider();
        return $provider->driver() . "\0" . $provider->identifier();
    }
}
