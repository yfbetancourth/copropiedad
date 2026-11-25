<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Classifieds\ClassifiedsAdminController;

/** @var Router $router */
$classifiedsAdmin = new ClassifiedsAdminController();
$router->get('/clasificados', [$classifiedsAdmin, 'index']);
$router->post('/clasificados/publicar', [$classifiedsAdmin, 'approve']);
$router->post('/clasificados/rechazar', [$classifiedsAdmin, 'reject']);
