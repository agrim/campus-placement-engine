<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Security\AuthorizationUnavailable;
use Throwable;

/** Central exact-version boundary for bundled module code and durable state. */
final class ModuleVersionIntegrity
{
    public const KEY_CONSTANT = 'CPE_MODULE_KEY';
    public const VERSION_CONSTANT = 'CPE_MODULE_VERSION';

    public static function assertDurableMatchesDefinition(
        string $moduleKey,
        string $installedVersion,
        array $definition,
    ): void {
        [, $configuredVersion] = self::implementationMetadata($moduleKey, $definition);
        if (!hash_equals($configuredVersion, $installedVersion)) {
            throw AuthorizationUnavailable::moduleState();
        }
    }

    /** @return array{0: string, 1: string} */
    public static function implementationMetadata(string $moduleKey, array $definition): array
    {
        $configuredVersion = self::configuredVersion($moduleKey, $definition);
        $class = $definition['class'] ?? null;
        if (!is_string($class) || $class === '') {
            throw AuthorizationUnavailable::moduleState();
        }
        try {
            $keyConstant = $class . '::' . self::KEY_CONSTANT;
            $versionConstant = $class . '::' . self::VERSION_CONSTANT;
            if (!class_exists($class)
                || !is_subclass_of($class, Module::class)
                || !defined($keyConstant)
                || !defined($versionConstant)) {
                throw AuthorizationUnavailable::moduleState();
            }
            $implementationKey = constant($keyConstant);
            $implementationVersion = constant($versionConstant);
        } catch (Throwable) {
            throw AuthorizationUnavailable::moduleState();
        }
        if (!is_string($implementationKey)
            || !is_string($implementationVersion)
            || !hash_equals($moduleKey, $implementationKey)
            || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $implementationVersion) !== 1
            || !hash_equals($configuredVersion, $implementationVersion)) {
            throw AuthorizationUnavailable::moduleState();
        }
        return [$implementationKey, $implementationVersion];
    }

    public static function implementationManifest(
        string $moduleKey,
        Module $module,
        array $definition,
    ): ModuleManifest {
        [$implementationKey, $implementationVersion] = self::implementationMetadata(
            $moduleKey,
            $definition,
        );
        try {
            $manifest = $module->manifest();
        } catch (Throwable) {
            throw AuthorizationUnavailable::moduleState();
        }
        if ($module->key() !== $implementationKey
            || $manifest->key() !== $implementationKey
            || !hash_equals($implementationVersion, $manifest->version())) {
            throw AuthorizationUnavailable::moduleState();
        }
        return $manifest;
    }

    private static function configuredVersion(string $moduleKey, array $definition): string
    {
        $version = $definition['version'] ?? null;
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $moduleKey) !== 1
            || !is_string($version)
            || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw AuthorizationUnavailable::moduleState();
        }
        return $version;
    }
}
