<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Community\ResidentController;

/** @var Router $router */
$residents = new ResidentController();
$router->get('/comunidad/residentes', [$residents, 'index']);
$router->get('/comunidad/residentes/nuevo', [$residents, 'form']);
$router->get('/comunidad/residentes/editar', [$residents, 'form']);
$router->post('/comunidad/residentes/guardar', [$residents, 'save']);
$router->post('/comunidad/residentes/invitar', [$residents, 'invite']);
$router->post('/comunidad/residentes/estado', [$residents, 'setStatus']);
$router->post('/comunidad/residentes/eliminar', [$residents, 'delete']);
