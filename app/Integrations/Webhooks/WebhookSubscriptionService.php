<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Core\Events\PublicEventProjection;
use App\Core\Events\ReplayOperatorAuthorization;
use App\Core\Http\UserVisibleException;
use App\Core\Persistence\WriteTransaction;
use App\Support\Auth;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use RuntimeException;

final class WebhookSubscriptionService
{
    public const SECRET_OVERLAP_SECONDS = 86400;

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?WebhookHttpTransport $transport = null,
        private readonly ?WebhookSecretCipher $cipher = null,
    ) {
    }

    /**
     * Create a future-event subscription. The returned secret is the only
     * plaintext reveal; null means the external encryption key is not ready.
     *
     * @return array{subscription_id: string, signing_secret: ?string, state: string}
     */
    public function create(
        string $name,
        string $endpointUrl,
        bool $selectApplicationStatusChanged,
        bool $allowPrivateNetwork,
        int $actorUserId,
    ): array {
        $pdo = $this->pdo();
        $name = trim($name);
        $endpointUrl = trim($endpointUrl);
        $this->assertName($name);
        $this->assertEndpointSyntax($endpointUrl);
        if (!$selectApplicationStatusChanged) {
            throw new UserVisibleException(
                'WEBHOOK_EVENT_SELECTION_REQUIRED',
                'Select at least one supported event before creating the integration.',
            );
        }
        if ($allowPrivateNetwork && $this->hostedMode()) {
            throw new UserVisibleException(
                'WEBHOOK_PRIVATE_NETWORK_MANAGED_FORBIDDEN',
                'Managed hosting allows public-egress webhook endpoints only.',
            );
        }
        $institution = $this->institution($pdo);
        $publicId = 'whsub_' . bin2hex(random_bytes(16));
        $secret = null;
        $encrypted = null;
        try {
            $cipher = $this->secretCipher();
            $secret = WebhookSecretCipher::generateSigningSecret();
            $encrypted = $cipher->encrypt($secret, (string) $institution['public_id'], $publicId);
        } catch (UserVisibleException) {
            // A missing key is an actionable setup state, not an Engine install failure.
        }

        WriteTransaction::run($pdo, function () use (
            $pdo,
            $institution,
            $publicId,
            $name,
            $endpointUrl,
            $allowPrivateNetwork,
            $actorUserId,
            $encrypted,
        ): void {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $now = cpe_now();
            $insert = $pdo->prepare(
                'INSERT INTO webhook_subscriptions
                 (public_id, institution_id, name, endpoint_url, endpoint_version, lifecycle_state,
                  allow_private_network, current_secret_ciphertext, current_secret_nonce,
                  current_secret_tag, current_secret_key_version, created_by_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([
                $publicId,
                (int) $institution['id'],
                $name,
                $endpointUrl,
                'setup_required',
                $allowPrivateNetwork ? 1 : 0,
                $encrypted['ciphertext'] ?? null,
                $encrypted['nonce'] ?? null,
                $encrypted['tag'] ?? null,
                $encrypted['key_version'] ?? null,
                $actorUserId,
                $now,
                $now,
            ]);
            $subscriptionId = Database::lastInsertId($pdo);
            $selection = $pdo->prepare(
                'INSERT INTO webhook_subscription_events
                 (subscription_id, event_type, schema_version, created_at) VALUES (?, ?, ?, ?)',
            );
            $selection->execute([
                $subscriptionId,
                PublicEventProjection::APPLICATION_STATUS_CHANGED,
                PublicEventProjection::APPLICATION_STATUS_CHANGED_SCHEMA,
                $now,
            ]);
            Auth::audit(
                $actorUserId,
                'webhook.subscription.create',
                'webhook_subscription',
                $subscriptionId,
                'Webhook integration created.',
                $pdo,
            );
        });
        return [
            'subscription_id' => $publicId,
            'signing_secret' => $secret,
            'state' => 'setup_required',
        ];
    }

    public function generateSecret(string $subscriptionPublicId, int $actorUserId): string
    {
        $pdo = $this->pdo();
        $secret = WebhookSecretCipher::generateSigningSecret();
        return WriteTransaction::run($pdo, function () use ($pdo, $subscriptionPublicId, $actorUserId, $secret): string {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $row = $this->lockedSubscription($pdo, $subscriptionPublicId);
            if (($row['current_secret_ciphertext'] ?? null) !== null) {
                throw new UserVisibleException(
                    'WEBHOOK_SECRET_ALREADY_PRESENT',
                    'This integration already has a signing secret. Rotate it instead.',
                );
            }
            $encrypted = $this->secretCipher()->encrypt(
                $secret,
                (string) $row['institution_public_id'],
                (string) $row['public_id'],
            );
            $update = $pdo->prepare(
                'UPDATE webhook_subscriptions
                 SET current_secret_ciphertext = ?, current_secret_nonce = ?, current_secret_tag = ?,
                     current_secret_key_version = ?, lifecycle_state = ?, revoked_at = NULL, updated_at = ?
                 WHERE id = ? AND current_secret_ciphertext IS NULL',
            );
            $update->execute([
                $encrypted['ciphertext'], $encrypted['nonce'], $encrypted['tag'], $encrypted['key_version'],
                'setup_required', cpe_now(), (int) $row['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Webhook secret state changed before generation completed.');
            }
            Auth::audit(
                $actorUserId,
                'webhook.secret.generate',
                'webhook_subscription',
                (int) $row['id'],
                'Webhook signing secret generated.',
                $pdo,
            );
            return $secret;
        });
    }

    public function rotateSecret(string $subscriptionPublicId, int $actorUserId): string
    {
        $pdo = $this->pdo();
        $newSecret = WebhookSecretCipher::generateSigningSecret();
        return WriteTransaction::run($pdo, function () use ($pdo, $subscriptionPublicId, $actorUserId, $newSecret): string {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $row = $this->lockedSubscription($pdo, $subscriptionPublicId);
            if (($row['current_secret_ciphertext'] ?? null) === null) {
                throw new UserVisibleException('WEBHOOK_SECRET_REQUIRED', 'Generate the first signing secret before rotating it.');
            }
            $previousExpiry = (string) ($row['previous_secret_expires_at'] ?? '');
            if ($previousExpiry !== '' && $previousExpiry > cpe_now()) {
                throw new UserVisibleException(
                    'WEBHOOK_SECRET_OVERLAP_ACTIVE',
                    'Wait for the current signing-secret overlap window to end before rotating again.',
                );
            }
            $encrypted = $this->secretCipher()->encrypt(
                $newSecret,
                (string) $row['institution_public_id'],
                (string) $row['public_id'],
            );
            $expiresAt = gmdate('Y-m-d H:i:s', time() + self::SECRET_OVERLAP_SECONDS);
            $update = $pdo->prepare(
                'UPDATE webhook_subscriptions
                 SET previous_secret_ciphertext = current_secret_ciphertext,
                     previous_secret_nonce = current_secret_nonce,
                     previous_secret_tag = current_secret_tag,
                     previous_secret_key_version = current_secret_key_version,
                     previous_secret_expires_at = ?,
                     current_secret_ciphertext = ?, current_secret_nonce = ?, current_secret_tag = ?,
                     current_secret_key_version = ?, updated_at = ?
                 WHERE id = ?',
            );
            $update->execute([
                $expiresAt,
                $encrypted['ciphertext'], $encrypted['nonce'], $encrypted['tag'], $encrypted['key_version'],
                cpe_now(), (int) $row['id'],
            ]);
            Auth::audit(
                $actorUserId,
                'webhook.secret.rotate',
                'webhook_subscription',
                (int) $row['id'],
                'Webhook signing secret rotated with a bounded overlap.',
                $pdo,
            );
            return $newSecret;
        });
    }

    /** Sends a separately typed synthetic challenge and leaves no placement data. */
    public function validate(string $subscriptionPublicId, int $actorUserId): void
    {
        $pdo = $this->pdo();
        $row = WriteTransaction::run($pdo, function () use ($pdo, $subscriptionPublicId, $actorUserId): array {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $row = $this->lockedSubscription($pdo, $subscriptionPublicId);
            if (($row['current_secret_ciphertext'] ?? null) === null) {
                throw new UserVisibleException('WEBHOOK_SECRET_REQUIRED', 'Generate a signing secret before validation.');
            }
            if (!in_array((string) $row['lifecycle_state'], ['disabled', 'setup_required', 'validating'], true)) {
                throw new UserVisibleException(
                    'WEBHOOK_VALIDATION_STATE_INVALID',
                    'Disable an active or degraded integration before validating it again.',
                );
            }
            $pdo->prepare(
                "UPDATE webhook_subscriptions
                 SET lifecycle_state = 'validating', updated_at = ? WHERE id = ?",
            )->execute([cpe_now(), (int) $row['id']]);
            Auth::audit(
                $actorUserId,
                'webhook.validation.start',
                'webhook_subscription',
                (int) $row['id'],
                'Webhook endpoint validation started.',
                $pdo,
            );
            return $row;
        });

        $challengeId = 'validation_' . bin2hex(random_bytes(16));
        $timestamp = time();
        $body = json_encode([
            'type' => 'webhook.validation',
            'version' => 1,
            'challenge' => $challengeId,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        try {
            $secret = $this->secretCipher()->decrypt(
                $this->encryptedFields($row, 'current'),
                (string) $row['institution_public_id'],
                (string) $row['public_id'],
            );
            $signature = WebhookSigner::signatureHeader($challengeId, $timestamp, $body, [$secret]);
            $response = $this->httpTransport()->send(
                (string) $row['endpoint_url'],
                $body,
                $this->headers($challengeId, $timestamp, $signature, 'webhook.validation;version=1'),
                (int) $row['allow_private_network'] === 1,
            );
            if ($response->statusCode < 200 || $response->statusCode >= 300) {
                throw new WebhookTransportException(WebhookTransportException::NETWORK, false);
            }
        } catch (\Throwable $failure) {
            $reference = $this->failureReference($failure, 'CPE_WEBHOOK_VALIDATION_FAILED', 'validation');
            $failed = $pdo->prepare(
                "UPDATE webhook_subscriptions
                 SET lifecycle_state = 'setup_required', last_failure_at = ?, last_failure_code = ?,
                     last_failure_reference = ?, updated_at = ?
                 WHERE id = ? AND lifecycle_state = 'validating'",
            );
            $failed->execute([cpe_now(), 'validation_failed', $reference, cpe_now(), (int) $row['id']]);
            throw new UserVisibleException(
                'WEBHOOK_VALIDATION_FAILED',
                'The endpoint could not be validated. Review its TLS and network policy, then retry. Reference: ' . $reference,
            );
        }

        WriteTransaction::run($pdo, function () use ($pdo, $row, $actorUserId): void {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $now = cpe_now();
            $updated = $pdo->prepare(
                "UPDATE webhook_subscriptions
                 SET last_validated_at = ?, last_failure_at = NULL, last_failure_code = '',
                     last_failure_reference = '', updated_at = ?
                 WHERE id = ? AND lifecycle_state = 'validating' AND endpoint_version = ?",
            );
            $updated->execute([$now, $now, (int) $row['id'], (int) $row['endpoint_version']]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Webhook endpoint changed while validation was in flight.');
            }
            Auth::audit(
                $actorUserId,
                'webhook.validation.success',
                'webhook_subscription',
                (int) $row['id'],
                'Webhook endpoint validation succeeded.',
                $pdo,
            );
        });
    }

    public function activate(string $subscriptionPublicId, int $actorUserId): void
    {
        $pdo = $this->pdo();
        WriteTransaction::run($pdo, function () use ($pdo, $subscriptionPublicId, $actorUserId): void {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $row = $this->lockedSubscription($pdo, $subscriptionPublicId);
            $validationCutoff = gmdate('Y-m-d H:i:s', time() - 86400);
            if ((string) $row['lifecycle_state'] !== 'validating'
                || ($row['current_secret_ciphertext'] ?? null) === null
                || ($row['last_validated_at'] ?? null) === null
                || (string) $row['last_validated_at'] < $validationCutoff) {
                throw new UserVisibleException(
                    'WEBHOOK_ACTIVATION_NOT_READY',
                    'Configure a secret and complete endpoint validation within 24 hours before activation.',
                );
            }
            $selected = $pdo->prepare(
                'SELECT COUNT(*) FROM webhook_subscription_events
                 WHERE subscription_id = ? AND event_type = ? AND schema_version = ?',
            );
            $selected->execute([
                (int) $row['id'],
                PublicEventProjection::APPLICATION_STATUS_CHANGED,
                PublicEventProjection::APPLICATION_STATUS_CHANGED_SCHEMA,
            ]);
            if ((int) $selected->fetchColumn() !== 1) {
                throw new UserVisibleException('WEBHOOK_EVENT_SELECTION_REQUIRED', 'Select a supported event before activation.');
            }
            $updated = $pdo->prepare(
                "UPDATE webhook_subscriptions
                 SET lifecycle_state = 'active', disabled_at = NULL, revoked_at = NULL,
                     consecutive_failures = 0, circuit_open_until = NULL, updated_at = ?
                 WHERE id = ? AND lifecycle_state = 'validating'",
            );
            $updated->execute([cpe_now(), (int) $row['id']]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Webhook activation state changed.');
            }
            Auth::audit($actorUserId, 'webhook.subscription.activate', 'webhook_subscription', (int) $row['id'], 'Webhook integration activated.', $pdo);
        });
    }

    public function disable(string $subscriptionPublicId, int $actorUserId): void
    {
        $this->setDisabled($subscriptionPublicId, $actorUserId, false);
    }

    public function revoke(string $subscriptionPublicId, int $actorUserId): void
    {
        $this->setDisabled($subscriptionPublicId, $actorUserId, true);
    }

    /** @return list<array<string, mixed>> */
    public function listForAdministrator(): array
    {
        $pdo = $this->pdo();
        $rows = $pdo->query(
            "SELECT subscription.*,
                    COUNT(delivery.id) AS delivery_count,
                    SUM(CASE WHEN delivery.status IN ('pending', 'processing', 'retrying') THEN 1 ELSE 0 END) AS backlog_count,
                    SUM(CASE WHEN delivery.status = 'dead_lettered' THEN 1 ELSE 0 END) AS dead_letter_count,
                    MIN(CASE WHEN delivery.status IN ('pending', 'processing', 'retrying') THEN delivery.created_at ELSE NULL END) AS oldest_pending_at
             FROM webhook_subscriptions subscription
             LEFT JOIN webhook_deliveries delivery ON delivery.subscription_id = subscription.id
             GROUP BY subscription.id
             ORDER BY subscription.id DESC",
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['has_secret'] = ($row['current_secret_ciphertext'] ?? null) !== null;
            $row['endpoint_display'] = $this->redactedEndpoint((string) $row['endpoint_url']);
            $row['endpoint_support_reference'] = 'origin_' . substr(hash('sha256', (string) $row['endpoint_url']), 0, 16);
            unset(
                $row['endpoint_url'],
                $row['current_secret_ciphertext'], $row['current_secret_nonce'],
                $row['current_secret_tag'], $row['current_secret_key_version'],
                $row['previous_secret_ciphertext'], $row['previous_secret_nonce'],
                $row['previous_secret_tag'], $row['previous_secret_key_version'],
            );
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function deadLettersForAdministrator(): array
    {
        $rows = $this->pdo()->query(
            "SELECT delivery.public_id, subscription.public_id AS subscription_public_id,
                    delivery.last_error_code, delivery.last_failure_reference,
                    delivery.attempt_count, delivery.dead_lettered_at
             FROM webhook_deliveries delivery
             JOIN webhook_subscriptions subscription ON subscription.id = delivery.subscription_id
             WHERE delivery.status = 'dead_lettered'
             ORDER BY delivery.id DESC LIMIT 50",
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['replayable'] = (string) $row['last_error_code'] !== 'subscription_revoked';
        }
        unset($row);
        return $rows;
    }

    private function setDisabled(string $subscriptionPublicId, int $actorUserId, bool $revoke): void
    {
        $pdo = $this->pdo();
        WriteTransaction::run($pdo, function () use ($pdo, $subscriptionPublicId, $actorUserId, $revoke): void {
            ReplayOperatorAuthorization::requireActiveAdministrator(
                $pdo,
                $actorUserId,
                ReplayOperatorAuthorization::PUBLIC_EVENT,
            );
            $row = $this->lockedSubscription($pdo, $subscriptionPublicId);
            $now = cpe_now();
            if ($revoke) {
                $pdo->prepare(
                    "UPDATE webhook_subscriptions
                     SET lifecycle_state = 'disabled', current_secret_ciphertext = NULL,
                         current_secret_nonce = NULL, current_secret_tag = NULL, current_secret_key_version = NULL,
                         previous_secret_ciphertext = NULL, previous_secret_nonce = NULL,
                         previous_secret_tag = NULL, previous_secret_key_version = NULL,
                         previous_secret_expires_at = NULL, circuit_open_until = NULL,
                         disabled_at = ?, revoked_at = ?, updated_at = ? WHERE id = ?",
                )->execute([$now, $now, $now, (int) $row['id']]);
                $pdo->prepare(
                    "UPDATE webhook_deliveries
                     SET status = 'dead_lettered', dead_lettered_at = ?, processed_at = NULL,
                         locked_at = NULL, lock_token = NULL, lease_generation = lease_generation + 1,
                         last_error_code = 'subscription_revoked', last_failure_reference = '', updated_at = ?
                     WHERE subscription_id = ? AND status IN ('pending', 'processing', 'retrying')",
                )->execute([$now, $now, (int) $row['id']]);
            } else {
                $pdo->prepare(
                    "UPDATE webhook_subscriptions
                     SET lifecycle_state = 'disabled', circuit_open_until = NULL, disabled_at = ?, updated_at = ?
                     WHERE id = ?",
                )->execute([$now, $now, (int) $row['id']]);
            }
            Auth::audit(
                $actorUserId,
                $revoke ? 'webhook.subscription.revoke' : 'webhook.subscription.disable',
                'webhook_subscription',
                (int) $row['id'],
                $revoke ? 'Webhook integration and signing secrets revoked.' : 'Webhook integration disabled.',
                $pdo,
            );
        });
    }

    private function lockedSubscription(PDO $pdo, string $publicId): array
    {
        if (preg_match('/^whsub_[a-f0-9]{32}$/D', $publicId) !== 1) {
            throw new UserVisibleException('WEBHOOK_SUBSCRIPTION_INVALID', 'Choose an exact webhook integration.');
        }
        $lock = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $query = $pdo->prepare(
            'SELECT subscription.*, institution.public_id AS institution_public_id
             FROM webhook_subscriptions subscription
             JOIN institutions institution ON institution.id = subscription.institution_id
             WHERE subscription.public_id = ?' . $lock,
        );
        $query->execute([$publicId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new UserVisibleException('WEBHOOK_SUBSCRIPTION_NOT_FOUND', 'Webhook integration was not found.');
        }
        return $rows[0];
    }

    private function institution(PDO $pdo): array
    {
        $institution = $pdo->query("SELECT id, public_id FROM institutions WHERE slug = 'default'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($institution)) {
            throw new RuntimeException('Webhook integration requires an institution context.');
        }
        return $institution;
    }

    private function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new UserVisibleException('WEBHOOK_NAME_INVALID', 'Integration name must be 1 to 120 printable characters.');
        }
    }

    private function assertEndpointSyntax(string $url): void
    {
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 2048 || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new UserVisibleException(
                'WEBHOOK_ENDPOINT_INVALID',
                'Enter an HTTP(S) endpoint without credentials or a fragment.',
            );
        }
    }

    /** @return array{ciphertext: mixed, nonce: mixed, tag: mixed, key_version: mixed} */
    private function encryptedFields(array $row, string $slot): array
    {
        return [
            'ciphertext' => $row[$slot . '_secret_ciphertext'] ?? null,
            'nonce' => $row[$slot . '_secret_nonce'] ?? null,
            'tag' => $row[$slot . '_secret_tag'] ?? null,
            'key_version' => $row[$slot . '_secret_key_version'] ?? null,
        ];
    }

    /** @return list<string> */
    private function headers(string $id, int $timestamp, string $signature, string $schema): array
    {
        return [
            'Content-Type: application/json',
            'User-Agent: CampusPlacementEngine/' . (string) cpe_config('app.version', '0.0.0'),
            'CPE-Webhook-Id: ' . $id,
            'CPE-Webhook-Timestamp: ' . $timestamp,
            'CPE-Webhook-Signature: ' . $signature,
            'CPE-Webhook-Schema: ' . $schema,
        ];
    }

    private function redactedEndpoint(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Endpoint configured';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? 'configured-host'));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port . '/…';
    }

    private function failureReference(\Throwable $failure, string $code, string $phase): string
    {
        $incidentId = IncidentReporter::report(
            $failure,
            $code,
            'worker',
            ['operation' => 'webhook.validation', 'phase' => $phase, 'status' => 'failed'],
        );
        return IncidentReporter::reference($code, $incidentId);
    }

    private function hostedMode(): bool
    {
        return in_array(strtolower(trim((string) (getenv('CPE_HOSTED_MODE') ?: ''))), ['1', 'true', 'yes', 'on'], true);
    }

    private function secretCipher(): WebhookSecretCipher
    {
        return $this->cipher ?? WebhookSecretCipher::fromEnvironment();
    }

    private function httpTransport(): WebhookHttpTransport
    {
        return $this->transport ?? new CurlWebhookHttpTransport();
    }

    private function pdo(): PDO
    {
        return $this->connection ?? Database::connection();
    }
}
