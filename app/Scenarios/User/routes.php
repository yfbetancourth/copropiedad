<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Tenant;

/** @var Router $router */
// El portal de residentes existe solo dentro de un conjunto residencial.
if (Tenant::isPlatform()) {
    return;
}

require dirname(__DIR__, 2) . '/Modules/Auth/user-routes.php';
require dirname(__DIR__, 2) . '/Modules/Billing/user-routes.php';
require dirname(__DIR__, 2) . '/Modules/Classifieds/user-routes.php';
require dirname(__DIR__, 2) . '/Modules/CommonAreas/user-routes.php';
require dirname(__DIR__, 2) . '/Modules/Messaging/user-routes.php';
