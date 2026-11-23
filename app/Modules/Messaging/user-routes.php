<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Messaging\ConversationPortalController;

/** @var Router $router */
$residentConversations = new ConversationPortalController();
$router->get('/usuario/comunicacion', [$residentConversations, 'index']);
$router->get('/usuario/comunicacion/ver', [$residentConversations, 'show']);
$router->post('/usuario/comunicacion/nueva', [$residentConversations, 'open']);
$router->post('/usuario/comunicacion/responder', [$residentConversations, 'reply']);
