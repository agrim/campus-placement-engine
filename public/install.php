<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\InstallController;
use App\Hosted\HostedContext;
use App\Support\Database;

if (HostedContext::isActive() && !Database::isInstalled()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Hosted sites are installed by the provisioning service.\n";
    exit;
}

$controller = new InstallController();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $controller->install();
}
$controller->show();
