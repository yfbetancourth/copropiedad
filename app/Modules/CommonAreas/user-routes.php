<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\CommonAreas\CommonAreaPortalController;

/** @var Router $router */
$commonAreasPortal = new CommonAreaPortalController();
$router->get('/usuario/zonas-comunes', [$commonAreasPortal, 'index']);
$router->post('/usuario/zonas-comunes/solicitar', [$commonAreasPortal, 'request']);
$router->post('/usuario/zonas-comunes/cancelar', [$commonAreasPortal, 'cancel']);
