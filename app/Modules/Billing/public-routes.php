<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Billing\PaymentWebhookController;

/** @var Router $router */
$paymentWebhook = new PaymentWebhookController();
$router->post('/pagos/bold/webhook', [$paymentWebhook, 'receive']);
$router->get('/pagos/bold/retorno', [$paymentWebhook, 'returnFromBold']);
