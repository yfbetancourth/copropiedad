<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\CommonAreas\CommonAreaAdminController;

/** @var Router $router */
$commonAreas = new CommonAreaAdminController();
$router->get('/zonas-comunes', [$commonAreas, 'requests']);
$router->post('/zonas-comunes/aprobar', [$commonAreas, 'approve']);
$router->post('/zonas-comunes/rechazar', [$commonAreas, 'reject']);
$router->get('/zonas-comunes/espacios', [$commonAreas, 'areas']);
$router->post('/zonas-comunes/espacios/guardar', [$commonAreas, 'saveArea']);
$router->post('/zonas-comunes/espacios/estado', [$commonAreas, 'setAreaStatus']);
$router->post('/zonas-comunes/espacios/eliminar', [$commonAreas, 'deleteArea']);
