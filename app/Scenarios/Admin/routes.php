<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Tenant;

/** @var Router $router */
require dirname(__DIR__, 2) . '/Modules/Auth/routes.php';

// Dominio principal: solo la consola del Superadmin para administrar los conjuntos residenciales.
if (Tenant::isPlatform()) {
    require dirname(__DIR__, 2) . '/Modules/Platform/routes.php';
    return;
}

require dirname(__DIR__, 2) . '/Modules/Auth/admin-routes.php';
require dirname(__DIR__, 2) . '/Modules/Configuration/routes.php';
require dirname(__DIR__, 2) . '/Modules/PropertyAssignment/routes.php';
require dirname(__DIR__, 2) . '/Modules/Billing/routes.php';
require dirname(__DIR__, 2) . '/Modules/Messaging/routes.php';
require dirname(__DIR__, 2) . '/Modules/Community/routes.php';
require dirname(__DIR__, 2) . '/Modules/CommonAreas/routes.php';
require dirname(__DIR__, 2) . '/Modules/Classifieds/admin-routes.php';
