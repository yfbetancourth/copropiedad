<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\PropertyAssignment\PropertyAssignmentController;

/** @var Router $router */
$assignments = new PropertyAssignmentController();
$router->get('/inmuebles/asignaciones', [$assignments, 'index']);
$router->post('/inmuebles/asignaciones/guardar', [$assignments, 'save']);
