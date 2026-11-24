<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Community\ResidentController;
use App\Modules\Community\ResidentImportController;
use App\Modules\Community\ResidentTypeController;

/** @var Router $router */
$residents = new ResidentController();
$router->get('/comunidad/residentes', [$residents, 'index']);
$router->get('/comunidad/residentes/nuevo', [$residents, 'form']);
$router->get('/comunidad/residentes/editar', [$residents, 'form']);
$router->post('/comunidad/residentes/guardar', [$residents, 'save']);
$router->post('/comunidad/residentes/invitar', [$residents, 'invite']);
$router->post('/comunidad/residentes/estado', [$residents, 'setStatus']);
$router->post('/comunidad/residentes/eliminar', [$residents, 'delete']);
$router->post('/comunidad/residentes/lote', [$residents, 'bulk']);

$import = new ResidentImportController();
$router->get('/comunidad/residentes/importar', [$import, 'form']);
$router->get('/comunidad/residentes/importar/plantilla', [$import, 'template']);
$router->post('/comunidad/residentes/importar/revisar', [$import, 'review']);
$router->post('/comunidad/residentes/importar/confirmar', [$import, 'confirm']);

$types = new ResidentTypeController();
$router->get('/comunidad/tipos-residente', [$types, 'index']);
$router->post('/comunidad/tipos-residente/guardar', [$types, 'save']);
$router->post('/comunidad/tipos-residente/estado', [$types, 'setStatus']);
$router->post('/comunidad/tipos-residente/eliminar', [$types, 'delete']);
