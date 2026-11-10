<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Messaging\MessagingController;

/** @var Router $router */
$messaging = new MessagingController();
$router->get('/mensajes', [$messaging, 'index']);
$router->post('/mensajes', [$messaging, 'send']);
