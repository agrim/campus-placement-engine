<?php

declare(strict_types=1);

define('CPE_SKIP_HTTP_BOOTSTRAP', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Modules\ModuleRegistry;
use App\Core\Security\CapabilityService;

$factory = new ReflectionMethod(CapabilityService::class, 'fromDatabase');
$parameters = $factory->getParameters();
if (count($parameters) !== 2) {
    throw new RuntimeException('Installed capability factory must have exactly PDO and ModuleRegistry parameters.');
}
$moduleParameter = $parameters[1];
$type = $moduleParameter->getType();
if ($moduleParameter->isOptional()
    || !$type instanceof ReflectionNamedType
    || $type->allowsNull()
    || $type->getName() !== ModuleRegistry::class) {
    throw new RuntimeException('Installed capability factory must require a non-null ModuleRegistry.');
}

echo "Capability factory contract passed.\n";
