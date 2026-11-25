<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currency = static fn (mixed $amount): string => '$ ' . number_format((float) $amount, 2, ',', '.') . ' COP';
$methodIcons = [
    'CREDIT_CARD' => 'bi-credit-card-2-front',
    'PSE' => 'bi-bank',
    'BOTON_BANCOLOMBIA' => 'bi-bank2',
    'NEQUI' => 'bi-phone',
];

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-credit-card me-2" aria-hidden="true"></i>Pagos</h1>
        <p class="text-secondary mb-0">Consulta las opciones habilitadas por la administración y paga tus notas pendientes.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card card-primary card-outline mb-4">
        <div class="card-header">
            <h2 class="card-title">Opciones de pago disponibles</h2>
            <span class="badge text-bg-secondary">Configuradas por la administración</span>
        </div>
        <div class="card-body">
            <?php if ($paymentMethods !== [] || $terminalPaymentsReady || $wompiReady): ?>
                <div class="row g-3">
                    <?php foreach ($paymentMethods as $method): ?>
                        <div class="col-sm-6 col-xl-3">
                            <div class="border rounded p-3 h-100 d-flex align-items-center gap-3">
                                <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 2.75rem; height: 2.75rem">
                                    <i class="bi <?= $escape($methodIcons[$method['code']] ?? 'bi-cash') ?> fs-5" aria-hidden="true"></i>
                                </span>
                                <span class="fw-semibold"><?= $escape($method['label']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($wompiReady): ?>
                        <div class="col-sm-6 col-xl-3">
                            <div class="border rounded p-3 h-100 d-flex align-items-center gap-3">
                                <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 2.75rem; height: 2.75rem">
                                    <i class="bi bi-wallet2 fs-5" aria-hidden="true"></i>
                                </span>
                                <span><span class="fw-semibold d-block">Wompi</span><span class="small text-secondary">Tarjeta, PSE, Nequi, Bancolombia</span></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="small text-secondary mt-3 mb-0">Los pagos en línea se procesan en la página segura de cada pasarela. El saldo solo se actualiza cuando la pasarela confirma la transacción.</p>
            <?php else: ?>
                <div class="alert alert-light border mb-0" role="status">La administración aún no ha habilitado opciones de pago en línea. Puedes consultar aquí tus notas y contactar a la administración para conocer otros medios de pago.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($paymentQr ?? null)): ?>
        <div class="card card-primary card-outline mb-4" id="qr">
            <div class="card-header">
                <h2 class="card-title">Pagar con QR · <?= $escape((string) $paymentQr['number']) ?></h2>
            </div>
            <div class="card-body">
                <div class="row g-4 align-items-center">
                    <div class="col-sm-5 col-md-4 col-xl-3">
                        <div class="border rounded p-2 bg-white" id="portal-qr" data-qr="<?= $escape((string) $paymentQr['url']) ?>"
                             data-qr-label="QR para pagar la nota <?= $escape((string) $paymentQr['number']) ?>"></div>
                    </div>
                    <div class="col-sm-7 col-md-8 col-xl-9">
                        <p class="mb-2">Escanéalo con la cámara del celular para pagar <strong><?= $escape($currency($paymentQr['balance'] / 100)) ?></strong> de <?= $escape((string) $paymentQr['unit_name']) ?> en línea, o comparte el enlace con quien vaya a pagar. No necesita iniciar sesión.</p>
                        <p class="small text-secondary">Vence el <?= $escape(date('d/m/Y H:i', strtotime((string) $paymentQr['expires_at']) ?: time())) ?>. El pago queda a tu nombre y el comprobante llega a tu correo.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-outline-primary btn-sm" type="button" data-copy="<?= $escape((string) $paymentQr['url']) ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiar enlace</button>
                            <a class="btn btn-outline-success btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= $escape(rawurlencode('Enlace para pagar la nota ' . $paymentQr['number'] . ': ' . $paymentQr['url'])) ?>"><i class="bi bi-whatsapp me-1" aria-hidden="true"></i>WhatsApp</a>
                            <a class="btn btn-outline-secondary btn-sm" data-qr-download="portal-qr" download="qr-pago-<?= $escape((string) $paymentQr['number']) ?>.gif" href="#"><i class="bi bi-download me-1" aria-hidden="true"></i>Descargar QR</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php require dirname(__DIR__, 3) . '/Shared/qr-script.php'; ?>
    <?php endif; ?>

    <div class="card card-success card-outline">
        <div class="card-header">
            <h2 class="card-title">Mis notas de cobro</h2>
            <span class="badge text-bg-primary"><?= count($billingDocuments) ?></span>
        </div>
        <div class="card-body">
            <?php if ($billingDocuments === []): ?>
                <p class="text-secondary mb-0">No tienes notas de cobro emitidas.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead><tr><th>Nota</th><th>Inmueble</th><th>Período</th><th>Saldo pendiente</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($billingDocuments as $document): ?>
                            <?php
                            $balance = (int) $document['balance'];
                            $supersededPeriod = is_array($document["superseded_by"] ?? null) ? (string) $document["superseded_by"]["period"] : null;
                            $payable = $balance > 0 && $supersededPeriod === null;
                            $paymentStatus = $document['terminal_payment_status'] ?? $document['online_payment_status'] ?? null;
                            ?>
                            <tr>
                                <td><?= $escape((string) $document['number']) ?></td>
                                <td>
                                    <?= $escape((string) $document['unit_name']) ?>
                                    <?php if (isset($paymentReferences[$document['unit_id']])): ?>
                                        <div class="small text-secondary text-nowrap">Ref. de pago <strong class="text-body"><?= $escape($paymentReferences[$document['unit_id']]) ?></strong></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $escape((string) $document['period']) ?></td>
                                <td><?= $supersededPeriod !== null ? '<span class="text-secondary">Incluido en ' . $escape($supersededPeriod) . '</span>' : $escape($currency($balance / 100)) ?></td>
                                <td>
                                    <?php if ($supersededPeriod !== null): ?><span class="badge text-bg-secondary">Saldo trasladado a <?= htmlspecialchars($supersededPeriod, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                                    <?php elseif ($balance <= 0): ?><span class="badge text-bg-success">Pagada</span>
                                    <?php elseif ($paymentStatus === 'approved'): ?><span class="badge text-bg-success">Pago aprobado</span>
                                    <?php elseif ($paymentStatus === 'pending'): ?><span class="badge text-bg-warning">Pago en proceso</span>
                                    <?php elseif ($paymentStatus === 'rejected'): ?><span class="badge text-bg-danger">Pago rechazado</span>
                                    <?php else: ?><span class="badge text-bg-secondary">Pendiente</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($payable && $onlinePaymentsReady): ?>
                                        <form method="post" action="<?= $escape($basePath) ?>/usuario/pagar" aria-label="Pagar nota <?= $escape((string) $document['number']) ?> en línea">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                            <input type="hidden" name="gateway" value="bold">
                                            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-credit-card me-1" aria-hidden="true"></i><?= $document['checkout_url'] !== null ? 'Continuar pago con Bold' : 'Pagar ' . $escape($currency($balance / 100)) . ' con Bold' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payable && $wompiReady): ?>
                                        <form class="mt-1" method="post" action="<?= $escape($basePath) ?>/usuario/pagar" aria-label="Pagar nota <?= $escape((string) $document['number']) ?> con Wompi">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                            <input type="hidden" name="gateway" value="wompi">
                                            <button class="btn btn-<?= $onlinePaymentsReady ? 'outline-' : '' ?>success btn-sm" type="submit"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Pagar <?= $escape($currency($balance / 100)) ?> con Wompi</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payable && ($onlinePaymentsReady || $wompiReady)): ?>
                                        <form class="mt-1" method="post" action="<?= $escape($basePath) ?>/usuario/pagos/qr" aria-label="QR para pagar la nota <?= $escape((string) $document['number']) ?> desde otro dispositivo">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>QR / compartir</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payable && $terminalPaymentsReady): ?>
                                        <form class="mt-1" method="post" action="<?= $escape($basePath) ?>/usuario/pagar-datafono" aria-label="Pagar nota <?= $escape((string) $document['number']) ?> en datáfono Bold">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                            <button class="btn btn-outline-success btn-sm" type="submit"><i class="bi bi-phone me-1" aria-hidden="true"></i>Datáfono Bold · <?= $escape($currency($balance / 100)) ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($supersededPeriod !== null): ?>
                                        <span class="text-secondary small">Paga la nota más reciente</span>
                                    <?php elseif ($payable && !$onlinePaymentsReady && !$wompiReady && !$terminalPaymentsReady): ?>
                                        <span class="text-secondary small">Pago en línea no disponible</span>
                                    <?php elseif ($balance <= 0): ?>
                                        <span class="text-secondary small">Sin saldo pendiente</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $receipts = [];
    foreach ($billingDocuments as $document) {
        foreach ($document['payments'] ?? [] as $payment) {
            if ($payment['voided_at'] === null) {
                $receipts[] = $payment + ['unit_name' => $document['unit_name'], 'statement_number' => $document['number']];
            }
        }
    }
    usort($receipts, static fn (array $a, array $b): int => [$b['received_on'], $b['id']] <=> [$a['received_on'], $a['id']]);
    $reportableDocuments = array_values(array_filter(
        $billingDocuments,
        static fn (array $document): bool => (int) $document['payable_balance'] > 0
    ));
    $methodLabels = \App\Modules\Billing\PaymentReportService::METHOD_LABELS;
    $statusLabels = \App\Modules\Billing\PaymentReportService::STATUS_LABELS;
    $statusBadges = ['pending' => 'warning', 'processing' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    ?>

    <div class="card card-secondary card-outline mt-4" id="comprobantes">
        <div class="card-header">
            <h2 class="card-title">Mis pagos y comprobantes</h2>
            <span class="badge text-bg-secondary"><?= count($receipts) ?></span>
        </div>
        <div class="card-body">
            <?php if ($receipts === []): ?>
                <p class="text-secondary mb-0">Aún no hay pagos registrados en tus notas de cobro.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Fecha</th><th>Inmueble</th><th>Nota</th><th class="text-end">Valor</th><th>Referencia</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($receipts, 0, 24) as $receipt): ?>
                            <tr>
                                <td><?= $escape((string) $receipt['received_on']) ?></td>
                                <td><?= $escape((string) $receipt['unit_name']) ?></td>
                                <td><?= $escape((string) $receipt['statement_number']) ?></td>
                                <td class="text-end"><?= $escape($currency($receipt['amount'])) ?></td>
                                <td class="small text-secondary"><?= $escape((string) $receipt['reference']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener"
                                       href="<?= $escape($basePath) ?>/usuario/pagos/comprobante?id=<?= (int) $receipt['id'] ?>">
                                        <i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>Comprobante
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($paymentReports !== null): ?>
    <div class="card card-warning card-outline mt-4" id="reportar">
        <div class="card-header">
            <h2 class="card-title">Reportar un pago</h2>
        </div>
        <div class="card-body">
            <p class="text-secondary">¿Pagaste por transferencia, consignación o en efectivo? Adjunta el soporte y la administración lo aplicará a tu nota de cobro. Te avisaremos aquí y por correo cuando quede aprobado.</p>
            <?php if (trim((string) ($bankDetails['account_number'] ?? '')) !== ''): ?>
                <div class="alert alert-light border small">
                    <strong>Cuenta de la copropiedad:</strong>
                    <?= $escape((string) ($bankDetails['bank_name'] ?? '')) ?>
                    <?= ($bankDetails['account_type'] ?? '') !== '' ? '· Cuenta ' . $escape((string) $bankDetails['account_type']) : '' ?>
                    · N.º <?= $escape((string) $bankDetails['account_number']) ?>
                    <?= trim((string) ($bankDetails['account_holder'] ?? '')) !== '' ? '· Titular: ' . $escape((string) $bankDetails['account_holder']) : '' ?>
                    <?= trim((string) ($bankDetails['tax_id'] ?? '')) !== '' ? '· NIT ' . $escape((string) $bankDetails['tax_id']) : '' ?>
                    <?php if ($paymentReferences !== []): ?>
                        <br><i class="bi bi-hash" aria-hidden="true"></i>Al consignar o transferir escribe la <strong>referencia de pago</strong> de tu inmueble:
                        <?= $escape(implode(' · ', array_map(
                            static fn (array $document): string => $document['unit_name'] . ': ' . ($paymentReferences[$document['unit_id']] ?? ''),
                            array_values(array_filter(
                                array_column($billingDocuments, null, 'unit_id'),
                                static fn (array $document): bool => isset($paymentReferences[$document['unit_id']])
                            ))
                        ))) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($reportableDocuments === []): ?>
                <div class="alert alert-light border mb-0" role="status">No tienes notas con saldo pendiente para reportar pagos.</div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" action="<?= $escape($basePath) ?>/usuario/pagos/reportar">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="report-statement" class="form-label">Nota de cobro</label>
                            <select id="report-statement" name="statement_id" class="form-select" required>
                                <?php foreach ($reportableDocuments as $document): ?>
                                    <option value="<?= (int) $document['id'] ?>">
                                        <?= $escape((string) $document['number']) ?> · <?= $escape((string) $document['unit_name']) ?> · saldo <?= $escape($currency($document['payable_balance'] / 100)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="report-amount" class="form-label">Valor pagado (COP)</label>
                            <input id="report-amount" name="amount" type="number" class="form-control" min="0.01" max="99999999999.99" step="0.01" required
                                   value="<?= $escape(number_format($reportableDocuments[0]['payable_balance'] / 100, 2, '.', '')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="report-date" class="form-label">Fecha del pago</label>
                            <input id="report-date" name="paid_on" type="date" class="form-control" max="<?= $escape($today) ?>" value="<?= $escape($today) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="report-method" class="form-label">Medio de pago</label>
                            <select id="report-method" name="method" class="form-select" required>
                                <?php foreach ($methodLabels as $value => $label): ?>
                                    <option value="<?= $escape($value) ?>"><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="report-reference" class="form-label">Referencia o N.º de transacción</label>
                            <input id="report-reference" name="reference" class="form-control" maxlength="80">
                        </div>
                        <div class="col-md-4">
                            <label for="report-proof" class="form-label">Soporte del pago</label>
                            <input id="report-proof" name="proof" type="file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                            <div class="form-text">Imagen o PDF, máximo 5 MB.</div>
                        </div>
                        <div class="col-12">
                            <label for="report-notes" class="form-label">Observaciones (opcional)</label>
                            <input id="report-notes" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                    <button class="btn btn-warning mt-3" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i>Enviar reporte de pago</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card card-outline mt-4" id="reportes">
        <div class="card-header">
            <h2 class="card-title">Mis pagos reportados</h2>
            <span class="badge text-bg-secondary"><?= count($paymentReports) ?></span>
        </div>
        <div class="card-body">
            <?php if ($paymentReports === []): ?>
                <p class="text-secondary mb-0">No has reportado pagos.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Reportado</th><th>Inmueble</th><th>Fecha de pago</th><th class="text-end">Valor</th><th>Medio</th><th>Estado</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($paymentReports as $report): ?>
                            <tr>
                                <td><?= $escape(substr((string) $report['created_at'], 0, 10)) ?></td>
                                <td><?= $escape((string) $report['unit_name']) ?> · <?= $escape((string) $report['period']) ?></td>
                                <td><?= $escape((string) $report['paid_on']) ?></td>
                                <td class="text-end"><?= $escape($currency($report['amount'])) ?></td>
                                <td><?= $escape($methodLabels[$report['method']] ?? (string) $report['method']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $statusBadges[$report['status']] ?? 'secondary' ?>"><?= $escape($statusLabels[$report['status']] ?? (string) $report['status']) ?></span>
                                    <?php if ($report['status'] === 'rejected' && (string) $report['review_notes'] !== ''): ?>
                                        <div class="small text-danger mt-1"><?= $escape((string) $report['review_notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/usuario/pagos/soporte?id=<?= (int) $report['id'] ?>">Soporte</a>
                                    <?php if ($report['payment_id'] !== null): ?>
                                        <a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/usuario/pagos/comprobante?id=<?= (int) $report['payment_id'] ?>">Comprobante</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $publicDeposits = $publicDeposits ?? [];
    $depositClaims = $depositClaims ?? [];
    $claimedDepositIds = array_column($depositClaims, 'deposit_id');
    $depositChannels = \App\Modules\Billing\UnidentifiedDepositService::CHANNEL_LABELS;
    $claimStatusLabels = \App\Modules\Billing\DepositClaimService::STATUS_LABELS;
    $claimBadges = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
    ?>
    <?php if ($publicDeposits !== [] || $depositClaims !== []): ?>
    <div class="card card-info card-outline mt-4" id="consignaciones">
        <div class="card-header">
            <h2 class="card-title">Consignaciones sin identificar</h2>
        </div>
        <div class="card-body">
            <p class="text-secondary">La administración recibió estos pagos sin saber de qué inmueble son. Si alguno es tuyo, pulsa <strong>Es mía</strong> y adjunta el soporte para que lo apliquen a tu nota de cobro. Para evitarlo, escribe siempre la referencia de pago de tu inmueble al consignar.</p>
            <?php if ($publicDeposits !== []): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th scope="col">Fecha</th><th scope="col">Valor</th><th scope="col">Medio</th><th scope="col"><span class="visually-hidden">Acción</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($publicDeposits as $publicDeposit): ?>
                            <?php $publicId = (int) $publicDeposit['id']; ?>
                            <tr>
                                <td><?= $escape(date('d/m/Y', strtotime($publicDeposit['deposited_on']) ?: 0)) ?></td>
                                <td><strong><?= $escape($currency($publicDeposit['amount_cents'] / 100)) ?></strong></td>
                                <td><?= $escape($depositChannels[$publicDeposit['channel']] ?? '') ?></td>
                                <td class="text-end">
                                    <?php if (in_array($publicId, array_map('intval', $claimedDepositIds), true)): ?>
                                        <span class="badge text-bg-light border">Ya la reclamaste</span>
                                    <?php elseif ($reportableDocuments === [] && $billingDocuments === []): ?>
                                        <span class="small text-secondary">Sin notas de cobro</span>
                                    <?php else: ?>
                                        <details>
                                            <summary class="btn btn-sm btn-outline-primary">Es mía</summary>
                                            <form method="post" action="<?= $escape($basePath) ?>/usuario/pagos/consignacion" enctype="multipart/form-data" class="text-start mt-2" style="min-width: 260px">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="deposit_id" value="<?= $publicId ?>">
                                                <label class="form-label small" for="claim-statement-<?= $publicId ?>">Aplicar a la nota</label>
                                                <select class="form-select form-select-sm" id="claim-statement-<?= $publicId ?>" name="statement_id" required>
                                                    <?php foreach ($reportableDocuments !== [] ? $reportableDocuments : $billingDocuments as $document): ?>
                                                        <option value="<?= (int) $document['id'] ?>"><?= $escape($document['unit_name'] . ' · ' . $document['period']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label class="form-label small mt-2" for="claim-proof-<?= $publicId ?>">Soporte (imagen o PDF, máx. 5 MB)</label>
                                                <input class="form-control form-control-sm" type="file" id="claim-proof-<?= $publicId ?>" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                                                <label class="form-label small mt-2" for="claim-notes-<?= $publicId ?>">Nota <span class="text-secondary">(opcional)</span></label>
                                                <input class="form-control form-control-sm" type="text" id="claim-notes-<?= $publicId ?>" name="notes" maxlength="255" placeholder="Ej.: la hice desde la cuenta de mi esposa">
                                                <button class="btn btn-sm btn-primary mt-2" type="submit">Enviar reclamo</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($depositClaims !== []): ?>
                <h3 class="h6 mt-3">Mis reclamos</h3>
                <ul class="list-group">
                    <?php foreach ($depositClaims as $claim): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div class="small">
                                Consignación del <?= $escape(date('d/m/Y', strtotime((string) $claim['deposited_on']) ?: 0)) ?> por <strong><?= $escape($currency($claim['amount_cents'] / 100)) ?></strong> · <?= $escape((string) $claim['unit_name']) ?>
                                <?php if ($claim['status'] === 'rejected' && (string) $claim['review_note'] !== ''): ?>
                                    <div class="text-danger"><?= $escape((string) $claim['review_note']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-nowrap">
                                <span class="badge text-bg-<?= $escape($claimBadges[$claim['status']] ?? 'secondary') ?>"><?= $escape($claimStatusLabels[$claim['status']] ?? '') ?></span>
                                <a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/usuario/pagos/consignacion/soporte?id=<?= (int) $claim['id'] ?>">Soporte</a>
                                <?php if ($claim['status'] === 'approved' && $claim['payment_id'] !== null): ?>
                                    <a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/usuario/pagos/comprobante?id=<?= (int) $claim['payment_id'] ?>">Comprobante</a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
