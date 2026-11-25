<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Billing\PaymentWebhookController;

/** @var Router $router */
$paymentWebhook = new PaymentWebhookController();
$router->post('/pagos/bold/webhook', [$paymentWebhook, 'receive']);
$router->get('/pagos/bold/retorno', [$paymentWebhook, 'returnFromBold']);
$router->post('/pagos/wompi/eventos', [$paymentWebhook, 'receiveWompi']);
$router->get('/pagos/wompi/retorno', [$paymentWebhook, 'returnFromWompi']);

// Enlaces de pago temporales: no requieren sesión; el token del enlace da acceso a una sola nota.
$payLink = new \App\Modules\Billing\PayLinkController();
$router->get('/pagar', [$payLink, 'show']);
$router->post('/pagar', [$payLink, 'start']);
$router->get('/pagar/resultado', [$payLink, 'result']);
// Verificación pública de un paz y salvo por su código (para compradores, arrendatarios e inmobiliarias).
$router->get('/verificar-paz-y-salvo', [new \App\Modules\Billing\AccountDocumentController(), 'verify']);
