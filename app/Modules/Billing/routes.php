<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Billing\BillingController;
use App\Modules\Billing\PaymentWebhookController;

/** @var Router $router */
$billing = new BillingController();
$router->get('/cartera/cobros', [$billing, 'index']);
$router->get('/cartera/cobros/nota', [$billing, 'statement']);
$router->post('/cartera/cobros/concepto', [$billing, 'addItem']);
$router->post('/cartera/cobros/concepto/eliminar', [$billing, 'removeItem']);
$router->post('/cartera/cobros/pago', [$billing, 'addPayment']);
$router->post('/cartera/cobros/emitir', [$billing, 'issue']);
$router->post('/cartera/cobros/reintentar', [$billing, 'retry']);
$paymentWebhook = new PaymentWebhookController();
$router->post('/pagos/bold/webhook', [$paymentWebhook, 'receive']);
$router->get('/pagos/bold/retorno', [$paymentWebhook, 'returnFromBold']);
