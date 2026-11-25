<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Privacy\PrivacyPublicController;

/** @var Router $router */
$privacyPublic = new PrivacyPublicController();
$router->get('/politica-de-datos', [$privacyPublic, 'dataPolicy']);
$router->get('/autorizacion-de-datos', [$privacyPublic, 'authorization']);
$router->get('/politica-de-cookies', [$privacyPublic, 'cookiePolicy']);
$router->post('/contacto', [$privacyPublic, 'submitContact']);
