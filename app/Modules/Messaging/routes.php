<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Messaging\MessagingController;

/** @var Router $router */
$messaging = new MessagingController();
$router->get('/mensajes', [$messaging, 'index']);
$router->post('/mensajes', [$messaging, 'send']);

$conversations = new \App\Modules\Messaging\ConversationAdminController();
$router->get('/comunicacion/atencion', [$conversations, 'index']);
$router->get('/comunicacion/atencion/ver', [$conversations, 'show']);
$router->post('/comunicacion/atencion/responder', [$conversations, 'reply']);
$router->post('/comunicacion/atencion/estado', [$conversations, 'setStatus']);
