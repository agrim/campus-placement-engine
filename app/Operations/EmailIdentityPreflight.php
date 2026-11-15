<?php

declare(strict_types=1);

namespace App\Operations;

use App\Core\Http\UserVisibleException;
use App\Infrastructure\Persistence\SqliteConnectionProvider;
use App\Support\Database;
use PDO;
use RuntimeException;
use Throwable;

/** A point-in-time inspection; the unique-index migration remains authoritative. */
final class EmailIdentityPreflight
{
    /**
     * @return array{collision_groups: int, conflicting_users: int, user_id_groups?: list<list<int>>}
     */
    public function check(bool $includeUserIds = false): array
    {
        $pdo = null;
        $ownsTransaction = false;
        try {
            $provider = Database::provider();
            if ($provider instanceof SqliteConnectionProvider) {
                // The normal SQLite provider creates directories and missing databases.
                // Match the backup URI escaping, but allow live WAL contents (no immutable=1).
                $path = realpath($provider->identifier());
                if ($path === false || !is_file($path) || !is_readable($path)) {
                    throw new RuntimeException('Existing database required.');
                }
                $uriPath = str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path);
                $pdo = new PDO('sqlite:file:' . $uriPath . '?mode=ro', null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec('PRAGMA query_only = ON');
                $pdo->exec('PRAGMA busy_timeout = 5000');
            } elseif ($provider->driver() === 'pgsql') {
                // Preserve the existing connection policy, including TLS and pool validation.
                $pdo = $provider->connection();
            } else {
                throw new RuntimeException('Unsupported inspection database.');
            }
            if ($pdo->inTransaction()) {
                throw new RuntimeException('Inspection requires its own transaction.');
            }
            $pdo->beginTransaction();
            $ownsTransaction = true;
            if ($provider->driver() === 'pgsql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            // Use the database expression verbatim: PHP trim/lower has different semantics.
            // NULLs do not collide in either unique index; all other users count, of every role.
            $counts = $pdo->query(
                'SELECT COUNT(*) AS collision_groups, COALESCE(SUM(identity_count), 0) AS conflicting_users
                 FROM (
                     SELECT COUNT(*) AS identity_count FROM users
                     WHERE email IS NOT NULL
                     GROUP BY lower(trim(email)) HAVING COUNT(*) > 1
                 ) AS collisions'
            )->fetch();
            $result = [
                'collision_groups' => (int) $counts['collision_groups'],
                'conflicting_users' => (int) $counts['conflicting_users'],
            ];
            if ($includeUserIds) {
                // Return only numeric IDs; never select emails or other identity attributes.
                $rows = $pdo->query(
                    'SELECT id, collision_group FROM (
                         SELECT id,
                             MIN(id) OVER (PARTITION BY lower(trim(email))) AS collision_group,
                             COUNT(*) OVER (PARTITION BY lower(trim(email))) AS collision_size
                         FROM users WHERE email IS NOT NULL
                     ) AS identities WHERE collision_size > 1 ORDER BY collision_group, id'
                );
                $groups = [];
                while ($row = $rows->fetch()) {
                    $groups[(int) $row['collision_group']][] = (int) $row['id'];
                }
                $result['user_id_groups'] = array_values($groups);
            }
            $pdo->rollBack();
            $ownsTransaction = false;
            return $result;
        } catch (Throwable) {
            // SQL errors can contain values and connection details. Never log or echo them.
            throw new UserVisibleException(
                'CLI_EMAIL_PREFLIGHT_FAILED',
                'Email identity check unavailable. Verify the database configuration, access, and legacy users schema before upgrading.',
            );
        } finally {
            if ($ownsTransaction && $pdo !== null) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    Database::reset();
                }
            }
        }
    }
}
