<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Auth\AuthController;

/** @var Router $router */
$userAuth = new AuthController();
$router->get('/usuario', [$userAuth, 'userPanel']);
$router->get('/usuario/pagos', [$userAuth, 'userPayments']);
$router->get('/usuario/mensajes', [$userAuth, 'userMessages']);
$router->post('/usuario/mensajes/leer', [$userAuth, 'markMessageRead']);
$router->post('/usuario/notificaciones/cobro', [$userAuth, 'markBillingNotificationRead']);
$router->post('/usuario/perfil', [$userAuth, 'updateOwnProfile']);
$router->post('/usuario/pagar', [$userAuth, 'userStartPayment']);
$router->post('/usuario/pagar-datafono', [$userAuth, 'userStartTerminalPayment']);
