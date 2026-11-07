<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Auth\AuthController;

/** @var Router $router */
$auth = new AuthController();
$router->get('/login', [$auth, 'loginForm']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/forgot-password', [$auth, 'forgotPasswordForm']);
$router->post('/forgot-password', [$auth, 'requestPasswordReset']);
$router->get('/reset-password', [$auth, 'resetPasswordForm']);
$router->post('/reset-password', [$auth, 'resetPassword']);
$router->get('/admin', [$auth, 'adminPanel']);
