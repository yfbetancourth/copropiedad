<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Home\HomeController;

/** @var Router $router */
$router->get('/', [new HomeController(), 'index']);
