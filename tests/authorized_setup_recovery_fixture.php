<?php

declare(strict_types=1);

use App\Security\SetupAuthorization;
use App\Security\SetupRecoveryAuthority;

/** Creates the same live authorization grant as an authorized browser setup. */
function test_authorized_setup_authorization(): SetupAuthorization
{
    $directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
        . '/cpe-authorized-recovery-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create setup recovery authority fixture directory.');
    }
    register_shutdown_function(static function () use ($directory): void {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    });

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $session = [];
    $sessionId = 'recovery-before-' . bin2hex(random_bytes(8));
    $authorization = new SetupAuthorization(
        environmentToken: $token,
        stateDirectory: $directory,
        session: $session,
        server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'CPE authorized recovery contract',
        ],
        sessionIdProvider: static function () use (&$sessionId): string {
            return $sessionId;
        },
        sessionRegenerator: static function () use (&$sessionId): void {
            $sessionId = 'recovery-after-' . bin2hex(random_bytes(8));
        },
        csrfRotator: static function (): void {
        },
    );
    $authorization->unlockWithEnvironmentToken($token);
    return $authorization;
}

/** Issues the same target-bound capability as an authorized browser recovery. */
function test_authorized_setup_recovery_authority(): SetupRecoveryAuthority
{
    return test_authorized_setup_authorization()->issueRecoveryAuthority();
}
