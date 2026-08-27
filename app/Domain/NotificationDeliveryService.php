<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Http\UserVisibleException;
use App\Security\OutboundHttpPolicy;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use RuntimeException;

final class NotificationDeliveryService
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function queueForNotification(int $notificationId): int
    {
        $channels = $this->enabledChannels();
        if ($channels === []) {
            return 0;
        }
        $notification = $this->notification($notificationId);
        if (!$notification) {
            throw new RuntimeException('Notification not found.');
        }
        $payload = $this->payload($notification);
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_deliveries
                (notification_id, channel, target, status, payload_json, created_at, updated_at,
                 available_at, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
        );
        $now = cpe_now();
        $queued = 0;
        foreach ($channels as $channel) {
            $target = $this->targetReference($channel);
            $stmt->execute([
                $notificationId,
                $channel,
                $target,
                'queued',
                $payload,
                $now,
                $now,
                $now,
                'ndk_' . bin2hex(random_bytes(16)),
            ]);
            $queued += $stmt->rowCount();
        }
        return $queued;
    }

    public function pendingDeliveries(string $channel = '', int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $channel = strtolower(trim($channel));
        $sql = "SELECT nd.*, n.subject, n.body
                FROM notification_deliveries nd
                JOIN notifications n ON n.id = nd.notification_id
                WHERE nd.status IN ('queued', 'failed')
                  AND nd.delivered_at IS NULL AND nd.available_at <= ?
                  AND (nd.locked_at IS NULL OR nd.locked_at < ?)";
        $params = [cpe_now(), $this->staleBefore()];
        if ($channel !== '') {
            $sql .= ' AND nd.channel = ?';
            $params[] = $channel;
        }
        $sql .= ' ORDER BY nd.status = \'failed\', nd.id LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['target'] = $this->targetReference((string) $row['channel']);
            $row['delivered_to'] = (string) $row['delivered_to'] === ''
                ? ''
                : $this->destinationKind((string) $row['channel']);
            unset($row['lock_token'], $row['locked_at']);
        }
        unset($row);
        return $rows;
    }

    public function deliverPending(string $channel = '', int $limit = 100, bool $dryRun = false): array
    {
        $rows = $dryRun
            ? $this->pendingDeliveries($channel, $limit)
            : $this->claimDeliveries($channel, $limit);
        $result = [
            'checked' => count($rows),
            'claimed' => $dryRun ? 0 : count($rows),
            'delivered' => 0,
            'failed' => 0,
            'retrying' => 0,
            'dead_lettered' => 0,
            'outcome_unknown' => 0,
            'claim_lost' => 0,
            'dry_run' => $dryRun ? 1 : 0,
            'rows' => [],
        ];
        foreach ($rows as $row) {
            $detail = [
                'id' => (int) $row['id'],
                'channel' => (string) $row['channel'],
                'status' => 'dry_run',
                'target' => $this->targetReference((string) $row['channel']),
                'error' => '',
            ];
            if ($dryRun) {
                $result['rows'][] = $detail;
                continue;
            }
            try {
                $this->deliver($row);
            } catch (\Throwable $e) {
                $incidentId = IncidentReporter::report(
                    $e,
                    'CPE_NOTIFICATION_DELIVERY_FAILED',
                    'worker',
                    ['operation' => 'notification.delivery', 'status' => 'failed'],
                );
                $failureReference = IncidentReporter::reference('CPE_NOTIFICATION_DELIVERY_FAILED', $incidentId);
                try {
                    $failureState = $this->markFailed($row, $failureReference);
                } catch (\Throwable $stateFailure) {
                    $detail['status'] = 'outcome-unknown';
                    $detail['error'] = $this->reportStateFailure(
                        $stateFailure,
                        'CPE_NOTIFICATION_FAILURE_STATE_UNKNOWN',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['rows'][] = $detail;
                    continue;
                }
                if (!$failureState['updated']) {
                    $detail['status'] = 'claim-lost';
                    $detail['error'] = $this->reportStateFailure(
                        new RuntimeException('Notification failure state claim was lost.'),
                        'CPE_NOTIFICATION_FAILURE_CLAIM_LOST',
                        'failure_state',
                    );
                    $result['outcome_unknown']++;
                    $result['claim_lost']++;
                    $result['rows'][] = $detail;
                    continue;
                }
                $result['failed']++;
                $result[$failureState['dead_lettered'] ? 'dead_lettered' : 'retrying']++;
                $detail['status'] = $failureState['dead_lettered'] ? 'dead-lettered' : 'retrying';
                $detail['error'] = $failureReference;
                $result['rows'][] = $detail;
                continue;
            }

            try {
                $acknowledged = $this->markDelivered($row);
            } catch (\Throwable $ackFailure) {
                $detail['status'] = 'outcome-unknown';
                $detail['error'] = $this->reportStateFailure(
                    $ackFailure,
                    'CPE_NOTIFICATION_ACK_STATE_UNKNOWN',
                    'acknowledgment',
                );
                $result['outcome_unknown']++;
                $result['rows'][] = $detail;
                continue;
            }
            if (!$acknowledged) {
                $detail['status'] = 'claim-lost';
                $detail['error'] = $this->reportStateFailure(
                    new RuntimeException('Notification acknowledgement claim was lost.'),
                    'CPE_NOTIFICATION_ACK_CLAIM_LOST',
                    'acknowledgment',
                );
                $result['outcome_unknown']++;
                $result['claim_lost']++;
                $result['rows'][] = $detail;
                continue;
            }
            $result['delivered']++;
            $detail['status'] = 'delivered';
            $result['rows'][] = $detail;
        }
        return $result;
    }

    public function deliveryStatus(): array
    {
        $rows = $this->pdo->query(
            'SELECT status, COUNT(*) AS count
             FROM notification_deliveries
             GROUP BY status'
        )->fetchAll();
        $status = ['queued' => 0, 'failed' => 0, 'dead-lettered' => 0, 'delivered' => 0];
        foreach ($rows as $row) {
            $status[(string) $row['status']] = (int) $row['count'];
        }
        return $status;
    }

    private function claimDeliveries(string $channel, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $channel = strtolower(trim($channel));
        $now = cpe_now();
        $stale = $this->staleBefore();
        $token = 'claim_' . bin2hex(random_bytes(16));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqliteImmediate = $driver === 'sqlite';
        $started = false;
        try {
            if ($sqliteImmediate) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
            $started = true;
            $sql = "SELECT id FROM notification_deliveries
                    WHERE status IN ('queued', 'failed')
                      AND delivered_at IS NULL AND available_at <= ?
                      AND (locked_at IS NULL OR locked_at < ?)";
            $params = [$now, $stale];
            if ($channel !== '') {
                $sql .= ' AND channel = ?';
                $params[] = $channel;
            }
            $sql .= ' ORDER BY id LIMIT ' . $limit;
            if ($driver === 'pgsql') {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }
            $select = $this->pdo->prepare($sql);
            $select->execute($params);
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE notification_deliveries
                     SET locked_at = ?, lock_token = ?, attempt_count = attempt_count + 1
                     WHERE id IN ({$placeholders}) AND status IN ('queued', 'failed')
                       AND delivered_at IS NULL AND available_at <= ?
                       AND (locked_at IS NULL OR locked_at < ?)"
                );
                $update->execute([$now, $token, ...$ids, $now, $stale]);
            }
            if ($sqliteImmediate) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            $started = false;
        } catch (\Throwable $failure) {
            if ($started) {
                try {
                    if ($sqliteImmediate) {
                        $this->pdo->exec('ROLLBACK');
                    } elseif ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } catch (\Throwable) {
                    Database::reset();
                }
            }
            throw $failure;
        }
        if ($ids === []) {
            return [];
        }
        $claimed = $this->pdo->prepare(
            'SELECT nd.*, n.subject, n.body
             FROM notification_deliveries nd
             JOIN notifications n ON n.id = nd.notification_id
             WHERE nd.lock_token = ? ORDER BY nd.id'
        );
        $claimed->execute([$token]);
        return $claimed->fetchAll();
    }

    private function staleBefore(): string
    {
        $seconds = max(30, min(3600, (int) (getenv('CPE_NOTIFICATION_LOCK_SECONDS') ?: 300)));
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    public function certificationReport(string $channel, bool $requireLiveGateway = false): array
    {
        $channel = strtolower(trim($channel));
        $checks = [];
        $add = function (string $status, string $key, string $message) use (&$checks): void {
            $checks[] = ['status' => $status, 'key' => $key, 'message' => $message];
        };

        if (!in_array($channel, ['sms', 'whatsapp'], true)) {
            $add('error', 'channel', 'Only sms and whatsapp gateway certification are supported.');
            return ['channel' => $channel, 'ok' => false, 'checks' => $checks];
        }

        $enabled = in_array($channel, $this->enabledChannels(), true);
        if ($enabled) {
            $add('ok', 'channel_enabled', "{$channel} is enabled in notification_delivery_channels.");
        } elseif ($requireLiveGateway) {
            $add('error', 'channel_enabled', "{$channel} must be enabled in notification_delivery_channels before live certification.");
        } else {
            $add('warn', 'channel_enabled', "{$channel} is not currently enabled; enable it before live operations.");
        }

        $target = $this->messageGatewayRecipient($channel);
        if ($target === '') {
            $add('error', 'recipient', strtoupper($channel) . ' recipient or gateway route is not configured.');
        } else {
            $add('ok', 'recipient', strtoupper($channel) . ' recipient or gateway route is configured.');
        }

        $gatewayUrl = $this->messageGatewayUrl($channel);
        $outbox = getenv('CPE_NOTIFICATION_' . strtoupper($channel) . '_OUTBOX_PATH') ?: getenv('CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH') ?: '';
        if ($gatewayUrl === '' && $outbox === '') {
            $add('error', 'handoff_target', strtoupper($channel) . ' needs either a gateway URL or a local message outbox for certification.');
        }
        if ($gatewayUrl !== '') {
            if (!filter_var($gatewayUrl, FILTER_VALIDATE_URL)) {
                $add('error', 'gateway_url', strtoupper($channel) . ' gateway URL is invalid.');
            } else {
                $scheme = parse_url($gatewayUrl, PHP_URL_SCHEME);
                $add(strtolower((string) $scheme) === 'https' ? 'ok' : 'warn', 'gateway_url', strtoupper($channel) . ' gateway URL is configured' . (strtolower((string) $scheme) === 'https' ? '.' : '; prefer HTTPS for live operations.'));
            }
            $add(function_exists('curl_init') ? 'ok' : 'error', 'outbound_http_runtime', function_exists('curl_init')
                ? 'PHP curl extension is available for pinned outbound delivery.'
                : 'PHP curl extension is required for outbound gateway delivery.');
        } elseif ($requireLiveGateway) {
            $add('error', 'gateway_url', strtoupper($channel) . ' gateway URL is required for live certification.');
        }
        if ($outbox !== '') {
            $dir = dirname((string) $outbox);
            $add((is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir))) ? 'ok' : 'error', 'local_outbox', strtoupper($channel) . ' local message outbox path is configured for dry-run verification.');
        } elseif (!$requireLiveGateway) {
            $add('warn', 'local_outbox', 'No local message outbox is configured; set CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH for safest dry-run verification.');
        }

        $authorization = getenv('CPE_NOTIFICATION_' . strtoupper($channel) . '_AUTHORIZATION') ?: '';
        if ($authorization !== '') {
            try {
                $this->safeHttpHeader((string) $authorization);
                $add('ok', 'authorization_header', strtoupper($channel) . ' authorization header is syntactically safe.');
            } catch (UserVisibleException $e) {
                $add('error', 'authorization_header', $e->publicMessage());
            } catch (\Throwable $e) {
                $incidentId = IncidentReporter::report(
                    $e,
                    'CPE_NOTIFICATION_CERTIFICATION_FAILED',
                    'worker',
                    ['operation' => 'notification.certification', 'phase' => 'authorization_header'],
                );
                $add('error', 'authorization_header', 'Certification check failed. ' . IncidentReporter::reference('CPE_NOTIFICATION_CERTIFICATION_FAILED', $incidentId));
            }
        } elseif ($requireLiveGateway) {
            $add('warn', 'authorization_header', strtoupper($channel) . ' authorization header is not set; confirm the gateway does not require one.');
        }

        $payload = $this->certificationPayload();
        $text = trim($this->notificationMessageText($payload, $channel, $target));
        if ($text === '') {
            $add('error', 'message_template', strtoupper($channel) . ' message template rendered empty text.');
        } else {
            $add(strlen($text) <= 320 ? 'ok' : 'warn', 'message_template', strtoupper($channel) . ' message template rendered ' . strlen($text) . ' byte(s) of text.');
        }

        $payloadTemplate = $this->messagePayloadTemplate($channel);
        try {
            $message = [
                'channel' => $channel,
                'to' => $target,
                'text' => $text,
                'notification' => $payload,
            ];
            if ($payloadTemplate !== '') {
                $message = $this->renderJsonPayloadTemplate($payloadTemplate, $this->templateValues($payload, $channel, $target, $text), $channel);
            }
            $json = json_encode($message, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Could not encode rendered gateway payload.');
            }
            $add('ok', 'payload_json', strtoupper($channel) . ' gateway payload renders valid JSON (' . strlen($json) . ' bytes).');
        } catch (UserVisibleException $e) {
            $add('error', 'payload_json', $e->publicMessage());
        } catch (\Throwable $e) {
            $incidentId = IncidentReporter::report(
                $e,
                'CPE_NOTIFICATION_CERTIFICATION_FAILED',
                'worker',
                ['operation' => 'notification.certification', 'phase' => 'payload_json'],
            );
            $add('error', 'payload_json', 'Certification check failed. ' . IncidentReporter::reference('CPE_NOTIFICATION_CERTIFICATION_FAILED', $incidentId));
        }

        foreach ($this->manualCertificationChecks($channel) as $key => $message) {
            $add('manual', $key, $message);
        }

        $hasErrors = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $hasErrors = true;
                break;
            }
        }
        return ['channel' => $channel, 'ok' => !$hasErrors, 'checks' => $checks];
    }

    private function certificationPayload(): array
    {
        return [
            'notification_id' => 0,
            'recipient_role' => 'control',
            'recipient_scope_type' => '',
            'recipient_scope_value' => '',
            'template_key' => 'certification',
            'subject' => 'Gateway certification test',
            'body' => 'This synthetic payload validates local notification handoff without real candidate data.',
            'source_type' => 'certification',
            'source_id' => null,
            'created_at' => cpe_now(),
        ];
    }

    private function manualCertificationChecks(string $channel): array
    {
        $label = strtoupper($channel);
        return [
            'provider_approval' => "{$label} provider, sender id, templates, and account ownership are approved by the institution.",
            'recipient_consent' => "{$label} recipients, opt-outs, quiet hours, and local legal/compliance requirements have been reviewed.",
            'live_probe' => "{$label} first live probe must be sent to a controlled test recipient group before placement-day broadcast.",
        ];
    }

    private function enabledChannels(): array
    {
        $value = $this->setting('notification_delivery_channels', '');
        $channels = array_values(array_unique(array_filter(array_map(
            fn (string $channel): string => strtolower(trim($channel)),
            explode(',', $value)
        ))));
        return array_values(array_filter($channels, fn (string $channel): bool => in_array($channel, ['file', 'webhook', 'email', 'sms', 'whatsapp'], true)));
    }

    private function notification(int $notificationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE id = ?');
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function payload(array $notification): string
    {
        $payload = $this->payloadArray($notification);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not encode notification payload.');
        }
        return $json;
    }

    private function payloadArray(array $notification): array
    {
        return [
            'notification_id' => (int) $notification['id'],
            'recipient_role' => (string) $notification['recipient_role'],
            'recipient_scope_type' => (string) $notification['recipient_scope_type'],
            'recipient_scope_value' => (string) $notification['recipient_scope_value'],
            'template_key' => (string) $notification['template_key'],
            'subject' => (string) $notification['subject'],
            'body' => (string) $notification['body'],
            'source_type' => (string) $notification['source_type'],
            'source_id' => $notification['source_id'] === null ? null : (int) $notification['source_id'],
            'created_at' => (string) $notification['created_at'],
        ];
    }

    private function deliver(array $row): void
    {
        $channel = (string) $row['channel'];
        if ($channel === 'file') {
            $this->deliverFile($row);
            return;
        }
        if ($channel === 'webhook') {
            $this->deliverWebhook($row);
            return;
        }
        if ($channel === 'email') {
            $this->deliverEmail($row);
            return;
        }
        if ($channel === 'sms' || $channel === 'whatsapp') {
            $this->deliverMessageGateway($row, $channel);
            return;
        }
        throw new RuntimeException('Unsupported notification delivery channel: ' . $channel);
    }

    private function deliverFile(array $row): void
    {
        $environmentTarget = trim((string) (getenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH') ?: ''));
        $target = $environmentTarget !== ''
            ? $environmentTarget
            : $this->safeDataOutboxPath($this->setting('notification_file_outbox_path', ''));
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create notification outbox directory.');
        }
        if (is_link($target)) {
            throw new RuntimeException('Notification outbox cannot be a symbolic link.');
        }
        $json = json_encode($this->deliveryEnvelope($row), JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($target, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write notification outbox file.');
        }
    }

    private function deliverWebhook(array $row): void
    {
        $target = $this->configuredTarget('CPE_NOTIFICATION_WEBHOOK_URL', 'notification_webhook_url');
        if ($target === '') {
            throw new RuntimeException('Webhook URL is not configured.');
        }
        $body = json_encode($this->deliveryEnvelope($row), JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Could not encode notification webhook payload.');
        }
        OutboundHttpPolicy::postJson(
            $target,
            $body,
            [
                'Content-Type: application/json',
                'User-Agent: CareerServicesPortal/' . (string) cpe_config('app.version', '0.0.0'),
                'X-CPE-Idempotency-Key: ' . $this->idempotencyKey($row),
            ],
            5,
            'Notification webhook',
            'CPE_NOTIFICATION_ALLOW_HTTP',
        );
    }

    private function deliverEmail(array $row): void
    {
        $target = $this->configuredTarget('CPE_NOTIFICATION_EMAIL_TO', 'notification_email_to');
        if ($target === '') {
            throw new RuntimeException('Email recipient is not configured.');
        }
        $target = $this->safeMailHeader($target);

        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException('Email payload is not valid JSON.');
        }

        $values = $this->templateValues($payload, 'email', $target);
        $subjectTemplate = $this->templateSetting('CPE_NOTIFICATION_EMAIL_SUBJECT_TEMPLATE', 'notification_email_subject_template');
        $bodyTemplate = $this->templateSetting('CPE_NOTIFICATION_EMAIL_BODY_TEMPLATE', 'notification_email_body_template');
        $subject = $this->safeMailHeader($subjectTemplate !== ''
            ? $this->renderTextTemplate($subjectTemplate, $values)
            : (string) ($payload['subject'] ?? $row['subject'] ?? 'Placement notification'));
        if ($bodyTemplate !== '') {
            $body = $this->renderTextTemplate($bodyTemplate, $values);
        } else {
            $body = trim((string) ($payload['body'] ?? $row['body'] ?? ''));
            $body .= "\n\nRecipient role: " . (string) ($payload['recipient_role'] ?? '');
            if (!empty($payload['recipient_scope_value'])) {
                $body .= "\nRecipient scope: " . (string) ($payload['recipient_scope_value'] ?? '');
            }
            if (!empty($payload['source_type'])) {
                $body .= "\nSource: " . (string) ($payload['source_type'] ?? '') . ' #' . (string) ($payload['source_id'] ?? '');
            }
            $body .= "\nCreated at: " . (string) ($payload['created_at'] ?? '');
        }

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'X-CPE-Idempotency-Key: ' . $this->idempotencyKey($row),
        ];
        $from = getenv('CPE_NOTIFICATION_EMAIL_FROM') ?: $this->setting('notification_email_from', '');
        if ($from !== '') {
            $headers[] = 'From: ' . $this->safeMailHeader($from);
        }

        $outbox = getenv('CPE_NOTIFICATION_EMAIL_OUTBOX_PATH') ?: '';
        if ($outbox !== '') {
            $this->writeEmailOutbox($outbox, [
                'idempotency_key' => $this->idempotencyKey($row),
                'to' => $target,
                'subject' => $subject,
                'body' => $body,
                'headers' => $headers,
            ]);
            return;
        }

        if (!function_exists('mail')) {
            throw new RuntimeException('PHP mail() is not available.');
        }
        if (!@mail($target, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('Email delivery failed.');
        }
    }

    private function deliverMessageGateway(array $row, string $channel): void
    {
        $gatewayUrl = $this->messageGatewayUrl($channel);
        $target = $this->messageGatewayRecipient($channel);
        if ($target === '') {
            throw new RuntimeException(strtoupper($channel) . ' recipient is not configured.');
        }

        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException(strtoupper($channel) . ' payload is not valid JSON.');
        }
        $text = $this->notificationMessageText($payload, $channel, $target);
        $message = [
            'idempotency_key' => $this->idempotencyKey($row),
            'channel' => $channel,
            'to' => $target,
            'text' => $text,
            'notification' => $payload,
        ];
        $payloadTemplate = $this->messagePayloadTemplate($channel);
        if ($payloadTemplate !== '') {
            $message = $this->renderJsonPayloadTemplate($payloadTemplate, $this->templateValues($payload, $channel, $target, $text), $channel);
            $message['idempotency_key'] = $this->idempotencyKey($row);
        }

        $outbox = getenv('CPE_NOTIFICATION_' . strtoupper($channel) . '_OUTBOX_PATH') ?: getenv('CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH') ?: '';
        if ($outbox !== '') {
            $this->writeMessageOutbox($outbox, $message);
            return;
        }

        if ($gatewayUrl === '') {
            throw new RuntimeException(strtoupper($channel) . ' gateway URL is not configured.');
        }
        $headers = [
            'Content-Type: application/json',
            'X-CPE-Idempotency-Key: ' . $this->idempotencyKey($row),
        ];
        $authorization = getenv('CPE_NOTIFICATION_' . strtoupper($channel) . '_AUTHORIZATION') ?: '';
        if ($authorization !== '') {
            $headers[] = 'Authorization: ' . $this->safeHttpHeader($authorization);
        }
        $json = json_encode($message, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not encode ' . strtoupper($channel) . ' gateway payload.');
        }
        $headers[] = 'User-Agent: CareerServicesPortal/' . (string) cpe_config('app.version', '0.0.0');
        OutboundHttpPolicy::postJson(
            $gatewayUrl,
            $json,
            $headers,
            5,
            strtoupper($channel) . ' gateway',
            'CPE_NOTIFICATION_ALLOW_HTTP',
        );
    }

    private function markDelivered(array $row): bool
    {
        $now = cpe_now();
        $destination = $this->destinationKind((string) $row['channel']);
        $stmt = $this->pdo->prepare(
            'UPDATE notification_deliveries
             SET status = ?, last_error = ?, updated_at = ?, delivered_at = ?, delivered_to = ?,
                 locked_at = NULL, lock_token = NULL
             WHERE id = ? AND lock_token = ? AND status IN (\'queued\', \'failed\') AND delivered_at IS NULL'
        );
        $stmt->execute([
            'delivered',
            '',
            $now,
            $now,
            $destination,
            (int) $row['id'],
            (string) $row['lock_token'],
        ]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{updated: bool, dead_lettered: bool} */
    private function markFailed(array $row, string $failureReference): array
    {
        $attempts = (int) $row['attempt_count'];
        $maxAttempts = max(1, min(100, (int) (getenv('CPE_NOTIFICATION_MAX_ATTEMPTS') ?: 5)));
        $deadLettered = $attempts >= $maxAttempts;
        $retryAt = gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, max(0, $attempts - 1)))));
        $stmt = $this->pdo->prepare(
            'UPDATE notification_deliveries
             SET status = ?, last_error = ?, updated_at = ?, available_at = ?,
                 locked_at = NULL, lock_token = NULL
             WHERE id = ? AND lock_token = ? AND status IN (\'queued\', \'failed\') AND delivered_at IS NULL'
        );
        $incidentId = preg_match('/\b(inc_[a-f0-9]{32})\z/D', $failureReference, $match) === 1
            ? $match[1]
            : 'inc_unavailable';
        $stmt->execute([
            $deadLettered ? 'dead-lettered' : 'failed',
            IncidentReporter::reference('CPE_NOTIFICATION_DELIVERY_FAILED', $incidentId),
            cpe_now(),
            $retryAt,
            (int) $row['id'],
            (string) $row['lock_token'],
        ]);
        return ['updated' => $stmt->rowCount() === 1, 'dead_lettered' => $deadLettered];
    }

    private function reportStateFailure(\Throwable $failure, string $code, string $phase): string
    {
        $incidentId = IncidentReporter::report(
            $failure,
            $code,
            'worker',
            ['operation' => 'notification.delivery', 'phase' => $phase],
        );
        return IncidentReporter::reference($code, $incidentId);
    }

    private function targetReference(string $channel): string
    {
        return in_array($channel, ['file', 'webhook', 'email', 'sms', 'whatsapp'], true)
            ? '[config:notification_' . $channel . ']'
            : '[config:notification_unknown]';
    }

    private function destinationKind(string $channel): string
    {
        return in_array($channel, ['file', 'webhook', 'email', 'sms', 'whatsapp'], true)
            ? $channel
            : 'unknown';
    }

    private function idempotencyKey(array $row): string
    {
        $key = (string) ($row['idempotency_key'] ?? '');
        if (preg_match('/\Andk_[a-f0-9]{32}\z/D', $key) !== 1) {
            throw new RuntimeException('Notification delivery idempotency key is invalid.');
        }
        return $key;
    }

    private function deliveryEnvelope(array $row): array
    {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException('Notification delivery payload is not valid JSON.');
        }
        return [
            'schema' => 'career_services.notification_delivery.v1',
            'idempotency_key' => $this->idempotencyKey($row),
            'channel' => $this->destinationKind((string) $row['channel']),
            'notification' => $payload,
        ];
    }

    private function safeMailHeader(string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new RuntimeException('Email header values cannot contain line breaks.');
        }
        return $value;
    }

    private function writeEmailOutbox(string $path, array $message): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $json = json_encode($message, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write email outbox file.');
        }
    }

    private function messageGatewayUrl(string $channel): string
    {
        $envKey = 'CPE_NOTIFICATION_' . strtoupper($channel) . '_GATEWAY_URL';
        if (getenv($envKey)) {
            return (string) getenv($envKey);
        }
        return $this->setting("notification_{$channel}_gateway_url", '');
    }

    private function messageGatewayRecipient(string $channel): string
    {
        $envKey = 'CPE_NOTIFICATION_' . strtoupper($channel) . '_TO';
        return $this->configuredTarget($envKey, 'notification_' . $channel . '_to');
    }

    private function configuredTarget(string $envKey, string $settingKey): string
    {
        $envValue = getenv($envKey);
        if ($envValue !== false && $envValue !== '') {
            return (string) $envValue;
        }
        return $this->setting($settingKey, '');
    }

    private function safeDataOutboxPath(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === '' || str_starts_with($configured, '[env:')) {
            $configured = 'notification-outbox.jsonl';
        }
        if (str_contains($configured, "\0")
            || strtolower(pathinfo($configured, PATHINFO_EXTENSION)) !== 'jsonl') {
            throw new RuntimeException('Notification file outbox must be a .jsonl path inside data/.');
        }
        $segments = preg_split('#[\\\\/]+#', $configured) ?: [];
        if (in_array('..', $segments, true)) {
            throw new RuntimeException('Notification file outbox must stay inside data/.');
        }

        $root = realpath(cpe_data_path()) ?: cpe_data_path();
        $absolute = str_starts_with($configured, '/') ? $configured : $root . '/' . $configured;
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedAbsolute = str_replace('\\', '/', $absolute);
        if (!str_starts_with($normalizedAbsolute, $normalizedRoot . '/')) {
            throw new RuntimeException('Notification file outbox must stay inside data/.');
        }
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('Could not create notification outbox directory.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false
            || ($resolvedDirectory !== $root && !str_starts_with($resolvedDirectory, rtrim($root, '/') . '/'))) {
            throw new RuntimeException('Notification file outbox must stay inside data/.');
        }
        return rtrim($resolvedDirectory, '/') . '/' . basename($absolute);
    }

    private function notificationMessageText(array $payload, string $channel = '', string $target = ''): string
    {
        $default = $this->defaultNotificationMessageText($payload);
        $template = $this->messageTextTemplate($channel);
        if ($template === '') {
            return $default;
        }
        $text = trim($this->renderTextTemplate($template, $this->templateValues($payload, $channel, $target, $default)));
        return $text !== '' ? $text : $default;
    }

    private function defaultNotificationMessageText(array $payload): string
    {
        $subject = trim((string) ($payload['subject'] ?? 'Placement notification'));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($subject === '') {
            return $body;
        }
        if ($body === '') {
            return $subject;
        }
        return $subject . ' - ' . preg_replace('/\s+/', ' ', $body);
    }

    private function messageTextTemplate(string $channel): string
    {
        $channel = strtolower($channel);
        $specific = $channel !== ''
            ? $this->templateSetting('CPE_NOTIFICATION_' . strtoupper($channel) . '_MESSAGE_TEMPLATE', 'notification_' . $channel . '_message_template')
            : '';
        if ($specific !== '') {
            return $specific;
        }
        return $this->templateSetting('CPE_NOTIFICATION_MESSAGE_TEMPLATE', 'notification_message_template');
    }

    private function messagePayloadTemplate(string $channel): string
    {
        $channel = strtolower($channel);
        if (!in_array($channel, ['sms', 'whatsapp'], true)) {
            return '';
        }
        return $this->templateSetting('CPE_NOTIFICATION_' . strtoupper($channel) . '_PAYLOAD_TEMPLATE', 'notification_' . $channel . '_payload_template');
    }

    private function templateSetting(string $envKey, string $settingKey): string
    {
        $envValue = getenv($envKey);
        if ($envValue !== false && $envValue !== '') {
            return (string) $envValue;
        }
        return $this->setting($settingKey, '');
    }

    private function templateValues(array $payload, string $channel, string $target, ?string $text = null): array
    {
        $values = $payload;
        $values['channel'] = $channel;
        $values['to'] = $target;
        $values['text'] = $text ?? $this->defaultNotificationMessageText($payload);
        $values['notification_json'] = $payload;
        $values['college_name'] = $this->setting('college_name', '');
        $values['timezone'] = $this->setting('timezone', '');
        return $values;
    }

    private function renderTextTemplate(string $template, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $matches) use ($values): string {
                $value = $values[$matches[1]] ?? '';
                if (is_array($value)) {
                    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
                    return $json === false ? '' : $json;
                }
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }
                return (string) $value;
            },
            $template
        ) ?? '';
    }

    private function renderJsonPayloadTemplate(string $template, array $values, string $channel): array
    {
        $rendered = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $matches) use ($values): string {
                $value = $values[$matches[1]] ?? '';
                $json = json_encode($value, JSON_UNESCAPED_SLASHES);
                return $json === false ? '""' : $json;
            },
            $template
        );
        if ($rendered === null) {
            throw new UserVisibleException(
                'NOTIFICATION_PAYLOAD_TEMPLATE_INVALID',
                'The notification payload template could not be rendered.',
            );
        }
        $decoded = json_decode($rendered, true);
        if (!is_array($decoded)) {
            throw new UserVisibleException(
                'NOTIFICATION_PAYLOAD_TEMPLATE_INVALID',
                'The notification payload template must render valid JSON.',
            );
        }
        return $decoded;
    }

    private function writeMessageOutbox(string $path, array $message): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $json = json_encode($message, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write message gateway outbox file.');
        }
    }

    private function safeHttpHeader(string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new UserVisibleException(
                'NOTIFICATION_AUTHORIZATION_HEADER_INVALID',
                'The notification authorization header cannot contain line breaks.',
            );
        }
        return $value;
    }

    private function setting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }
}
