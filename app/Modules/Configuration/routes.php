<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Configuration\ConfigurationController;

/** @var Router $router */
$configuration = new ConfigurationController();
$router->get('/configuracion/parametrizacion', [$configuration, 'parametrization']);
$router->post('/configuracion/parametrizacion/guardar', [$configuration, 'saveParametrization']);
$router->get('/configuracion/financiera', [$configuration, 'financial']);
$router->post('/configuracion/financiera/guardar', [$configuration, 'saveFinancial']);
$router->get('/configuracion/correo', [$configuration, 'mail']);
$router->post('/configuracion/correo/guardar', [$configuration, 'saveMail']);
$router->post('/configuracion/correo/probar', [$configuration, 'testMail']);
$router->get('/configuracion/metodos-pago', [$configuration, 'paymentMethods']);
$router->post('/configuracion/metodos-pago/guardar', [$configuration, 'savePaymentMethods']);
$router->get('/configuracion/caracterizacion', [$configuration, 'characterization']);
$router->post('/configuracion/caracterizacion/guardar', [$configuration, 'saveCharacterization']);
$router->get('/configuracion/conformacion', [$configuration, 'composition']);
$router->post('/configuracion/conformacion/paso', [$configuration, 'saveCompositionStep']);
$router->post('/configuracion/conformacion/generar', [$configuration, 'generateComposition']);
$router->post('/configuracion/conformacion/finalizar', [$configuration, 'finishComposition']);
$router->get('/configuracion/equipo', [$configuration, 'team']);
$router->post('/configuracion/equipo/guardar', [$configuration, 'saveUser']);
$router->post('/configuracion/equipo/estado', [$configuration, 'setUserStatus']);
$router->get('/configuracion/roles', [$configuration, 'roles']);
$router->post('/configuracion/roles/guardar', [$configuration, 'saveRole']);
$router->post('/configuracion/roles/eliminar', [$configuration, 'deleteRole']);
