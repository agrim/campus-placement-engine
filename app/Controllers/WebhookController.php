<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Integrations\Webhooks\WebhookDeliveryReplayService;
use App\Integrations\Webhooks\WebhookHealthService;
use App\Integrations\Webhooks\WebhookSecretCipher;
use App\Integrations\Webhooks\WebhookSubscriptionService;
use App\Security\Csrf;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Database;
use App\Support\Flash;

final class WebhookController
{
    public function show(): void
    {
        $this->user();
        $this->render();
    }

    public function create(): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $result = $this->service()->create(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['endpoint_url'] ?? ''),
                isset($_POST['application_status_changed']),
                isset($_POST['allow_private_network']),
                (int) $user['id'],
            );
            Flash::add(
                'success',
                $result['signing_secret'] === null
                    ? 'Integration created. Configure the external encryption key, then generate its signing secret.'
                    : 'Integration created. Copy the signing secret now; it will not be shown again.',
            );
            $this->render($result['signing_secret'], $result['subscription_id']);
            return;
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_WEBHOOK_CREATE_FAILED', 'webhook.create');
        }
        redirect(url('integrations'));
    }

    public function generateSecret(): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $subscription = (string) ($_POST['subscription_id'] ?? '');
            $secret = $this->service()->generateSecret($subscription, (int) $user['id']);
            Flash::add('success', 'Signing secret generated. Copy it now; it will not be shown again.');
            $this->render($secret, $subscription);
            return;
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_WEBHOOK_SECRET_GENERATE_FAILED', 'webhook.secret.generate');
        }
        redirect(url('integrations'));
    }

    public function rotateSecret(): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $subscription = (string) ($_POST['subscription_id'] ?? '');
            $secret = $this->service()->rotateSecret($subscription, (int) $user['id']);
            Flash::add('success', 'Signing secret rotated. Both signatures are sent for 24 hours. Copy the new secret now.');
            $this->render($secret, $subscription);
            return;
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_WEBHOOK_SECRET_ROTATE_FAILED', 'webhook.secret.rotate');
        }
        redirect(url('integrations'));
    }

    public function validate(): void
    {
        $this->mutate('validate', 'Endpoint validated. Activate the integration when ready.');
    }

    public function activate(): void
    {
        $this->mutate('activate', 'Integration activated for future selected events.');
    }

    public function disable(): void
    {
        $this->mutate('disable', 'Integration disabled. Its retained backlog will not be delivered until reactivated.');
    }

    public function revoke(): void
    {
        $this->mutate('revoke', 'Integration and signing secrets revoked. Unresolved deliveries were dead-lettered.');
    }

    public function replay(): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $result = (new WebhookDeliveryReplayService(Database::connection()))->replay(
                (string) ($_POST['delivery_id'] ?? ''),
                (int) $user['id'],
            );
            Flash::add('success', 'Exact dead-letter delivery ' . $result['status'] . '.');
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_WEBHOOK_REPLAY_FAILED', 'webhook.replay');
        }
        redirect(url('integrations'));
    }

    private function mutate(string $action, string $success): void
    {
        $user = $this->user();
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $subscription = (string) ($_POST['subscription_id'] ?? '');
            $service = $this->service();
            match ($action) {
                'validate' => $service->validate($subscription, (int) $user['id']),
                'activate' => $service->activate($subscription, (int) $user['id']),
                'disable' => $service->disable($subscription, (int) $user['id']),
                'revoke' => $service->revoke($subscription, (int) $user['id']),
            };
            Flash::add('success', $success);
        } catch (\Throwable $failure) {
            ControllerFailure::flash($failure, 'CPE_WEBHOOK_LIFECYCLE_FAILED', 'webhook.' . $action);
        }
        redirect(url('integrations'));
    }

    private function render(?string $revealedSecret = null, string $revealedFor = ''): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }
        $service = $this->service();
        $health = (new WebhookHealthService(Database::connection()))->snapshot();
        $heartbeat = Database::connection()->query(
            'SELECT worker_public_id, started_at, finished_at, status,
                    claimed_count, succeeded_count, failed_count
             FROM webhook_worker_heartbeat WHERE singleton_id = 1',
        )->fetch();
        view('webhooks', [
            'subscriptions' => $service->listForAdministrator(),
            'deadLetters' => $service->deadLettersForAdministrator(),
            'keyring' => WebhookSecretCipher::environmentStatus(),
            'health' => $health,
            'heartbeat' => is_array($heartbeat) ? $heartbeat : null,
            'revealedSecret' => $revealedSecret,
            'revealedFor' => $revealedFor,
        ]);
    }

    private function service(): WebhookSubscriptionService
    {
        return new WebhookSubscriptionService(Database::connection());
    }

    private function user(): array
    {
        return Auth::requireCapability(
            'portal.integrations.manage',
            'Only administrators can manage integrations.',
        );
    }
}
