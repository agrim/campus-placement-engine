<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Http\UserVisibleException;
use LogicException;
use PDO;

/** Exact attribution rule shared by every local dead-letter replay path. */
final class ReplayOperatorAuthorization
{
    public const PUBLIC_EVENT = 'public_event';
    public const INTERNAL_DELIVERY = 'internal_delivery';
    public const INTERNAL_FANOUT = 'internal_fanout';

    public static function requireActiveAdministrator(PDO $pdo, int $actorUserId, string $context): void
    {
        if ($actorUserId < 1) {
            throw self::invalid($context);
        }
        $lock = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? ' FOR UPDATE'
            : '';
        $actor = $pdo->prepare(
            "SELECT id FROM users WHERE id = ? AND active = 1 AND role = 'admin'" . $lock,
        );
        $actor->execute([$actorUserId]);
        if ($actor->fetchColumn() === false) {
            throw self::invalid($context);
        }
    }

    public static function invalid(string $context): UserVisibleException
    {
        $code = match ($context) {
            self::PUBLIC_EVENT => 'PUBLIC_EVENT_REPLAY_ACTOR_INVALID',
            self::INTERNAL_DELIVERY => 'INTERNAL_EVENT_REPLAY_ACTOR_INVALID',
            self::INTERNAL_FANOUT => 'INTERNAL_EVENT_FANOUT_REPLAY_ACTOR_INVALID',
            default => throw new LogicException('Unknown replay authorization context.'),
        };
        return new UserVisibleException(
            $code,
            'An active administrator user ID is required.',
        );
    }
}
