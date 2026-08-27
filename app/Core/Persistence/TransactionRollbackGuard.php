<?php

declare(strict_types=1);

namespace App\Core\Persistence;

use App\Core\Http\UserVisibleException;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use Throwable;

final class TransactionRollbackGuard
{
    public const ERROR_ROLLBACK_UNCERTAIN = 'RECOVERY_ROLLBACK_UNCERTAIN';

    private const BOUNDARIES = [
        'configuration.import' => ['CONFIGURATION_IMPORT_ROLLED_BACK', 'CPE_CONFIGURATION_ROLLBACK_FAILED'],
        'installation' => ['INSTALLATION_ROLLED_BACK', 'CPE_INSTALLATION_ROLLBACK_FAILED'],
        'portability.import' => ['PORTABILITY_IMPORT_ROLLED_BACK', 'CPE_PORTABILITY_ROLLBACK_FAILED'],
        'privacy.erasure' => ['PRIVACY_ERASURE_ROLLED_BACK', 'CPE_PRIVACY_ROLLBACK_FAILED'],
    ];

    public static function rethrow(?PDO &$pdo, Throwable $primary, string $boundary, bool $safetyAvailable): never
    {
        self::complete($pdo, $primary, $boundary, $safetyAvailable, false, true);
    }

    /**
     * Confirms rollback while retaining the original failure when cleanup is
     * known to have completed. Callers that hold a connection-scoped lock may
     * defer disconnection until that lock has had a chance to release.
     */
    public static function rollbackOrRethrow(
        ?PDO &$pdo,
        Throwable $primary,
        string $boundary,
        bool $discardImmediately = true,
    ): never {
        self::complete($pdo, $primary, $boundary, false, true, $discardImmediately);
    }

    private static function complete(
        ?PDO &$pdo,
        Throwable $primary,
        string $boundary,
        bool $safetyAvailable,
        bool $preservePrimary,
        bool $discardImmediately,
    ): never
    {
        [$publicCode, $diagnosticCode] = self::BOUNDARIES[$boundary]
            ?? ['OPERATION_ROLLED_BACK', 'CPE_OPERATION_ROLLBACK_FAILED'];
        try {
            if (!$pdo instanceof PDO || !$pdo->inTransaction() || $pdo->rollBack() !== true || $pdo->inTransaction()) {
                throw new \RuntimeException('Rollback confirmation failed.');
            }
        } catch (Throwable $cleanupFailure) {
            $incidentId = IncidentReporter::report(
                $cleanupFailure,
                $diagnosticCode,
                'persistence',
                ['operation' => $boundary, 'phase' => 'rollback'],
            );
            if ($discardImmediately) {
                Database::reset();
                $pdo = null;
            }
            $message = $safetyAvailable
                ? 'Rollback could not be confirmed. Discard the database connection and restore from the safety backup in configured backup storage before retrying.'
                : 'Rollback could not be confirmed. Discard the database connection and verify database state before retrying.';
            throw new UserVisibleException(
                self::ERROR_ROLLBACK_UNCERTAIN,
                $message . ' Reference: ' . $incidentId,
                $primary,
            );
        }

        if ($preservePrimary) {
            throw $primary;
        }

        throw new UserVisibleException(
            $publicCode,
            $safetyAvailable
                ? 'The operation failed and database changes were rolled back. A safety backup remains in configured backup storage.'
                : 'The operation failed and database changes were rolled back.',
            $primary,
        );
    }
}
