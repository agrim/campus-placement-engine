<?php

declare(strict_types=1);

namespace App\Domain;

use App\Security\OutboundHttpPolicy;
use App\Support\Database;
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
                (notification_id, channel, target, status, payload_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
        );
        $now = cpe_now();
        $queued = 0;
        foreach ($channels as $channel) {
            $target = $this->targetForChannel($channel, $notification);
            $stmt->execute([$notificationId, $channel, $target, 'queued', $payload, $now, $now]);
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
                WHERE nd.status IN ('queued', 'failed')";
        $params = [];
        if ($channel !== '') {
            $sql .= ' AND nd.channel = ?';
            $params[] = $channel;
        }
        $sql .= ' ORDER BY nd.status = \'failed\', nd.id LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function deliverPending(string $channel = '', int $limit = 100, bool $dryRun = false): array
    {
        $rows = $this->pendingDeliveries($channel, $limit);
        $result = ['checked' => count($rows), 'delivered' => 0, 'failed' => 0, 'dry_run' => $dryRun ? 1 : 0, 'rows' => []];
        foreach ($rows as $row) {
            $detail = [
                'id' => (int) $row['id'],
                'channel' => (string) $row['channel'],
                'status' => 'dry_run',
                'target' => $this->targetLabel((string) $row['channel'], (string) $row['target']),
                'error' => '',
            ];
            if ($dryRun) {
                $result['rows'][] = $detail;
                continue;
            }
            try {
                $this->deliver($row);
                $this->markDelivered((int) $row['id']);
                $result['delivered']++;
                $detail['status'] = 'delivered';
            } catch (\Throwable $e) {
                $this->markFailed((int) $row['id'], $e->getMessage());
                $result['failed']++;
                $detail['status'] = 'failed';
                $detail['error'] = $e->getMessage();
            }
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
        $status = ['queued' => 0, 'failed' => 0, 'delivered' => 0];
        foreach ($rows as $row) {
            $status[(string) $row['status']] = (int) $row['count'];
        }
        return $status;
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

        $queuedTarget = $this->targetForChannel($channel);
        $target = $this->messageGatewayRecipient($channel, $queuedTarget);
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
            } catch (\Throwable $e) {
                $add('error', 'authorization_header', $e->getMessage());
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
        } catch (\Throwable $e) {
            $add('error', 'payload_json', $e->getMessage());
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
            : $this->safeDataOutboxPath((string) $row['target']);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create notification outbox directory.');
        }
        if (is_link($target)) {
            throw new RuntimeException('Notification outbox cannot be a symbolic link.');
        }
        if (file_put_contents($target, (string) $row['payload_json'] . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write notification outbox file.');
        }
    }

    private function deliverWebhook(array $row): void
    {
        $target = getenv('CPE_NOTIFICATION_WEBHOOK_URL') ?: (string) $row['target'];
        if ($target === '') {
            throw new RuntimeException('Webhook URL is not configured.');
        }
        OutboundHttpPolicy::postJson(
            $target,
            (string) $row['payload_json'],
            [
                'Content-Type: application/json',
                'User-Agent: CareerServicesPortal/' . (string) cpe_config('app.version', '0.0.0'),
            ],
            5,
            'Notification webhook',
            'CPE_NOTIFICATION_ALLOW_HTTP',
        );
    }

    private function deliverEmail(array $row): void
    {
        $target = $this->configuredTarget('CPE_NOTIFICATION_EMAIL_TO', (string) $row['target']);
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

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $from = getenv('CPE_NOTIFICATION_EMAIL_FROM') ?: $this->setting('notification_email_from', '');
        if ($from !== '') {
            $headers[] = 'From: ' . $this->safeMailHeader($from);
        }

        $outbox = getenv('CPE_NOTIFICATION_EMAIL_OUTBOX_PATH') ?: '';
        if ($outbox !== '') {
            $this->writeEmailOutbox($outbox, [
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
        $target = $this->messageGatewayRecipient($channel, (string) $row['target']);
        if ($target === '') {
            throw new RuntimeException(strtoupper($channel) . ' recipient is not configured.');
        }

        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException(strtoupper($channel) . ' payload is not valid JSON.');
        }
        $text = $this->notificationMessageText($payload, $channel, $target);
        $message = [
            'channel' => $channel,
            'to' => $target,
            'text' => $text,
            'notification' => $payload,
        ];
        $payloadTemplate = $this->messagePayloadTemplate($channel);
        if ($payloadTemplate !== '') {
            $message = $this->renderJsonPayloadTemplate($payloadTemplate, $this->templateValues($payload, $channel, $target, $text), $channel);
        }

        $outbox = getenv('CPE_NOTIFICATION_' . strtoupper($channel) . '_OUTBOX_PATH') ?: getenv('CPE_NOTIFICATION_MESSAGE_OUTBOX_PATH') ?: '';
        if ($outbox !== '') {
            $this->writeMessageOutbox($outbox, $message);
            return;
        }

        if ($gatewayUrl === '') {
            throw new RuntimeException(strtoupper($channel) . ' gateway URL is not configured.');
        }
        $headers = ['Content-Type: application/json'];
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

    private function markDelivered(int $deliveryId): void
    {
        $now = cpe_now();
        $stmt = $this->pdo->prepare(
            'UPDATE notification_deliveries
             SET status = ?, attempt_count = attempt_count + 1, last_error = ?, updated_at = ?, delivered_at = ?
             WHERE id = ?'
        );
        $stmt->execute(['delivered', '', $now, $now, $deliveryId]);
    }

    private function markFailed(int $deliveryId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notification_deliveries
             SET status = ?, attempt_count = attempt_count + 1, last_error = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute(['failed', substr($error, 0, 500), cpe_now(), $deliveryId]);
    }

    private function targetForChannel(string $channel, array $notification = []): string
    {
        if ($channel === 'file') {
            if (getenv('CPE_NOTIFICATION_FILE_OUTBOX_PATH')) {
                return '[env:CPE_NOTIFICATION_FILE_OUTBOX_PATH]';
            }
            return $this->safeDataOutboxPath($this->setting('notification_file_outbox_path', ''));
        }
        if ($channel === 'webhook') {
            return getenv('CPE_NOTIFICATION_WEBHOOK_URL') ? '[env:CPE_NOTIFICATION_WEBHOOK_URL]' : $this->setting('notification_webhook_url', '');
        }
        if ($channel === 'email') {
            return getenv('CPE_NOTIFICATION_EMAIL_TO') ? '[env:CPE_NOTIFICATION_EMAIL_TO]' : $this->setting('notification_email_to', '');
        }
        if ($channel === 'sms') {
            return getenv('CPE_NOTIFICATION_SMS_TO') ? '[env:CPE_NOTIFICATION_SMS_TO]' : $this->setting('notification_sms_to', '');
        }
        if ($channel === 'whatsapp') {
            return getenv('CPE_NOTIFICATION_WHATSAPP_TO') ? '[env:CPE_NOTIFICATION_WHATSAPP_TO]' : $this->setting('notification_whatsapp_to', '');
        }
        return '';
    }

    private function targetLabel(string $channel, string $target): string
    {
        if ($channel === 'webhook' && getenv('CPE_NOTIFICATION_WEBHOOK_URL')) {
            return '[env:CPE_NOTIFICATION_WEBHOOK_URL]';
        }
        if ($channel === 'email' && getenv('CPE_NOTIFICATION_EMAIL_TO')) {
            return '[env:CPE_NOTIFICATION_EMAIL_TO]';
        }
        if ($channel === 'sms' && getenv('CPE_NOTIFICATION_SMS_TO')) {
            return '[env:CPE_NOTIFICATION_SMS_TO]';
        }
        if ($channel === 'whatsapp' && getenv('CPE_NOTIFICATION_WHATSAPP_TO')) {
            return '[env:CPE_NOTIFICATION_WHATSAPP_TO]';
        }
        return $target;
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

    private function messageGatewayRecipient(string $channel, string $queuedTarget): string
    {
        $envKey = 'CPE_NOTIFICATION_' . strtoupper($channel) . '_TO';
        return $this->configuredTarget($envKey, $queuedTarget);
    }

    private function configuredTarget(string $envKey, string $queuedTarget): string
    {
        $envValue = getenv($envKey);
        if ($envValue !== false && $envValue !== '') {
            return (string) $envValue;
        }
        if (str_starts_with($queuedTarget, '[env:')) {
            return '';
        }
        return $queuedTarget;
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
            throw new RuntimeException(strtoupper($channel) . ' payload template could not be rendered.');
        }
        $decoded = json_decode($rendered, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(strtoupper($channel) . ' payload template must render valid JSON.');
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
            throw new RuntimeException('HTTP header values cannot contain line breaks.');
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
