<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Privacy\ContactAdminController;
use App\Modules\Privacy\PrivacyAdminController;

/** @var Router $router */
$privacyAdmin = new PrivacyAdminController();
$router->get('/configuracion/privacidad', [$privacyAdmin, 'index']);
$router->post('/configuracion/privacidad/publicar', [$privacyAdmin, 'publish']);
$router->post('/configuracion/privacidad/responsable', [$privacyAdmin, 'saveSettings']);
$router->get('/configuracion/privacidad/version', [$privacyAdmin, 'version']);
$router->get('/configuracion/privacidad/autorizaciones', [$privacyAdmin, 'consentsIndex']);
$router->get('/configuracion/privacidad/autorizaciones/exportar', [$privacyAdmin, 'exportConsents']);
$router->post('/configuracion/privacidad/autorizaciones/revocar', [$privacyAdmin, 'revoke']);

$contactAdmin = new ContactAdminController();
$router->get('/comunicacion/contacto', [$contactAdmin, 'index']);
$router->post('/comunicacion/contacto/leido', [$contactAdmin, 'markRead']);
$router->post('/comunicacion/contacto/eliminar', [$contactAdmin, 'delete']);
