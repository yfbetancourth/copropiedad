<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Classifieds\ClassifiedsController;

/** @var Router $router */
$classifieds = new ClassifiedsController();
$router->post('/usuario/clasificados/guardar', [$classifieds, 'save']);
$router->post('/usuario/clasificados/estado', [$classifieds, 'changeStatus']);
$router->post('/usuario/clasificados/eliminar', [$classifieds, 'delete']);
