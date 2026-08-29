<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function boundary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$moduleConfig = (string) file_get_contents($root . '/config/modules.php');
$managerSource = (string) file_get_contents($root . '/app/Core/Modules/ModuleManager.php');
$registrySource = (string) file_get_contents($root . '/app/Core/Modules/ModuleRegistry.php');
$moduleControllerSource = (string) file_get_contents($root . '/app/Controllers/ModuleController.php');
$frontControllerSource = (string) file_get_contents($root . '/public/index.php');
$extensionGuide = (string) file_get_contents($root . '/docs/architecture/extensions.md');

boundary_assert(str_contains($moduleConfig, 'App\\Modules\\Placement\\PlacementModule::class'), 'Placement must be declared by the shipped module catalog.');
boundary_assert(str_contains($moduleConfig, 'App\\Modules\\Advising\\AdvisingModule::class'), 'Advising must be declared by the shipped module catalog.');

foreach ([$managerSource, $registrySource] as $source) {
    foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator', 'glob(', 'scandir(', 'move_uploaded_file', 'ZipArchive'] as $forbidden) {
        boundary_assert(!str_contains($source, $forbidden), 'Module loading must not discover executable code from writable paths: ' . $forbidden);
    }
}

foreach (['move_uploaded_file', '$_FILES', 'ZipArchive', 'Composer\\Installer'] as $forbidden) {
    boundary_assert(!str_contains($moduleControllerSource, $forbidden), 'Module administration must not accept executable packages: ' . $forbidden);
}
boundary_assert(!str_contains(strtolower($frontControllerSource), "'plugins'"), 'The browser router must not expose a plugin-management route.');
boundary_assert(!str_contains(strtolower($frontControllerSource), "'extensions'"), 'The browser router must not expose an executable-extension route.');

boundary_assert(is_dir($root . '/database/migrations'), 'SQLite migrations must remain in the Engine registry.');
boundary_assert(is_dir($root . '/database/migrations/pgsql'), 'PostgreSQL migrations must remain in the Engine registry.');
boundary_assert(!is_dir($root . '/plugins'), 'The release must not expose a runtime plugin directory.');
boundary_assert(str_contains($extensionGuide, 'not a public plugin ABI'), 'Extension documentation must keep the internal/public boundary explicit.');
boundary_assert(str_contains($extensionGuide, 'Cloud never proxies ordinary placement API traffic'), 'Extension documentation must preserve the control-plane boundary.');

echo "Internal module boundary contract passed.\n";
