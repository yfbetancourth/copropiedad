<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Classifieds\ClassifiedsController;

/** @var Router $router */
$classifieds = new ClassifiedsController();
$router->get('/clasificados', [$classifieds, 'index']);
$router->get('/clasificados/imagen', [$classifieds, 'image']);
