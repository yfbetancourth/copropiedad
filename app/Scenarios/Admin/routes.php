<?php

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */
require dirname(__DIR__, 2) . '/Modules/Auth/routes.php';
require dirname(__DIR__, 2) . '/Modules/Auth/admin-routes.php';
require dirname(__DIR__, 2) . '/Modules/Configuration/routes.php';
require dirname(__DIR__, 2) . '/Modules/PropertyAssignment/routes.php';
require dirname(__DIR__, 2) . '/Modules/Billing/routes.php';
require dirname(__DIR__, 2) . '/Modules/Messaging/routes.php';
