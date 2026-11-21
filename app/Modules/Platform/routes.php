<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Platform\PlatformController;

/** @var Router $router */
$platform = new PlatformController();
$router->get('/admin', [$platform, 'index']);
$router->get('/clientes', [$platform, 'index']);
$router->get('/clientes/nuevo', [$platform, 'createForm']);
$router->post('/clientes/guardar', [$platform, 'store']);
$router->get('/clientes/editar', [$platform, 'edit']);
$router->post('/clientes/actualizar', [$platform, 'update']);
$router->post('/clientes/modulos', [$platform, 'saveModules']);
$router->post('/clientes/estado', [$platform, 'setStatus']);
$router->post('/clientes/administrador', [$platform, 'addAdmin']);
$router->post('/clientes/administrador/estado', [$platform, 'setAdminStatus']);
$router->post('/clientes/administrador/clave', [$platform, 'resetAdminPassword']);
