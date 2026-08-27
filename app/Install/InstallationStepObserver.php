<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Testability seam for observing reviewed installation transaction stages.
 *
 * Implementations receive only a fixed stage name. They must not be given
 * installation input, credentials, database details, or persisted state.
 */
interface InstallationStepObserver
{
    public const AFTER_SETTINGS = 'after_settings';
    public const AFTER_IDENTITY = 'after_identity';
    public const AFTER_ADMIN = 'after_admin';
    public const AFTER_DEMO_SEED = 'after_demo_seed';
    public const AFTER_SYNCHRONIZERS = 'after_synchronizers';
    public const AFTER_INSTALLED_MARKER = 'after_installed_marker';
    public const AFTER_INSTALL_AUDIT = 'after_install_audit';

    public const STAGES = [
        self::AFTER_SETTINGS,
        self::AFTER_IDENTITY,
        self::AFTER_ADMIN,
        self::AFTER_DEMO_SEED,
        self::AFTER_SYNCHRONIZERS,
        self::AFTER_INSTALLED_MARKER,
        self::AFTER_INSTALL_AUDIT,
    ];

    public function observe(string $stage): void;
}
