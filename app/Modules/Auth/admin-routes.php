<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Auth\AuthController;

/** @var Router $router */
$adminAuth = new AuthController();
$router->get('/admin', [$adminAuth, 'adminPanel']);
$router->get('/mi-cuenta', [$adminAuth, 'staffAccount']);
$router->post('/mi-cuenta', [$adminAuth, 'updateOwnProfile']);
