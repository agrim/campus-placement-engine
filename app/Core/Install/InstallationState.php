<?php

declare(strict_types=1);

namespace App\Core\Install;

/** Strict installation-state outcomes shared by runtime and authorized setup. */
final class InstallationState
{
    public const FRESH = 'fresh';
    public const RECOVERABLE = 'recoverable';
    public const INSTALLED = 'installed';

    private function __construct()
    {
    }
}
