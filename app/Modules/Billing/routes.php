<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Billing\BillingController;

/** @var Router $router */
$billing = new BillingController();
$router->get('/cartera/cobros', [$billing, 'index']);
$router->get('/cartera/cobros/nota', [$billing, 'statement']);
$router->get('/cartera/cobros/vista-previa', [$billing, 'draft']);
$router->post('/cartera/cobros/concepto', [$billing, 'addItem']);
$router->post('/cartera/cobros/concepto/lote', [$billing, 'addItemBulk']);
$router->post('/cartera/cobros/concepto/eliminar', [$billing, 'removeItem']);
$router->post('/cartera/cobros/pago', [$billing, 'addPayment']);
$router->post('/cartera/cobros/pago/anular', [$billing, 'voidPayment']);
$router->post('/cartera/cobros/emitir', [$billing, 'issue']);
$router->post('/cartera/cobros/reintentar', [$billing, 'retry']);

$reports = new \App\Modules\Billing\ReportController();
$router->get('/cartera/reportes', [$reports, 'index']);
$router->get('/cartera/reportes/exportar', [$reports, 'export']);
$router->get('/cartera/buscar', [$reports, 'search']);

$paymentReports = new \App\Modules\Billing\PaymentReportController();
$router->get('/cartera/pagos-reportados', [$paymentReports, 'index']);
$router->get('/cartera/pagos-reportados/soporte', [$paymentReports, 'proof']);
$router->post('/cartera/pagos-reportados/aprobar', [$paymentReports, 'approve']);
$router->post('/cartera/pagos-reportados/rechazar', [$paymentReports, 'reject']);
$router->get('/cartera/cobros/comprobante', [$paymentReports, 'receipt']);
$deposits = new \App\Modules\Billing\UnidentifiedDepositController();
$router->get('/cartera/consignaciones', [$deposits, 'index']);
$router->post('/cartera/consignaciones/registrar', [$deposits, 'store']);
$router->post('/cartera/consignaciones/identificar', [$deposits, 'identify']);
$router->post('/cartera/consignaciones/descartar', [$deposits, 'discard']);
$router->get('/cartera/consignaciones/soporte', [$deposits, 'proof']);
$router->post('/cartera/consignaciones/publicar', [$deposits, 'setPublished']);
$router->post('/cartera/consignaciones/reclamo/aprobar', [$deposits, 'approveClaim']);
$router->post('/cartera/consignaciones/reclamo/rechazar', [$deposits, 'rejectClaim']);
$router->get('/cartera/consignaciones/reclamo/soporte', [$deposits, 'claimProof']);

$paymentLinks = new \App\Modules\Billing\PaymentLinkController();
$router->get('/cartera/cobros/enlaces', [$paymentLinks, 'index']);
$router->post('/cartera/cobros/enlaces/crear', [$paymentLinks, 'create']);
$router->post('/cartera/cobros/enlaces/enviar', [$paymentLinks, 'send']);
$router->post('/cartera/cobros/enlaces/revocar', [$paymentLinks, 'revoke']);
