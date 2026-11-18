<?php

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */
require dirname(__DIR__, 2) . '/Modules/Auth/user-routes.php';
require dirname(__DIR__, 2) . '/Modules/Classifieds/user-routes.php';
