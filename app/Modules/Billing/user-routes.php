<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Billing\ResidentPaymentController;

/** @var Router $router */
$residentPayments = new ResidentPaymentController();
$router->get('/usuario/pagos', [$residentPayments, 'index']);
$router->post('/usuario/pagar', [$residentPayments, 'startOnline']);
$router->post('/usuario/pagar-datafono', [$residentPayments, 'startTerminal']);
$router->post('/usuario/pagos/qr', [$residentPayments, 'qr']);
$router->post('/usuario/pagos/reportar', [$residentPayments, 'report']);
$router->get('/usuario/pagos/soporte', [$residentPayments, 'proof']);
$router->get('/usuario/pagos/comprobante', [$residentPayments, 'receipt']);
$router->post('/usuario/pagos/consignacion', [$residentPayments, 'claimDeposit']);
$router->get('/usuario/pagos/consignacion/soporte', [$residentPayments, 'claimProof']);
