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
            <?php if ($paymentMethods !== [] || $terminalPaymentsReady): ?>
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
                </div>
                <p class="small text-secondary mt-3 mb-0">Los pagos en línea se procesan mediante Bold. El saldo solo se actualiza cuando el proveedor confirma la transacción.</p>
            <?php else: ?>
                <div class="alert alert-light border mb-0" role="status">La administración aún no ha habilitado opciones de pago en línea. Puedes consultar aquí tus notas y contactar a la administración para conocer otros medios de pago.</div>
            <?php endif; ?>
        </div>
    </div>

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
                                <td><?= $escape((string) $document['unit_name']) ?></td>
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
                                            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-credit-card me-1" aria-hidden="true"></i><?= $document['checkout_url'] !== null ? 'Continuar pago en línea' : 'Pagar ' . $escape($currency($balance / 100)) . ' en línea' ?></button>
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
                                    <?php elseif ($payable && !$onlinePaymentsReady && !$terminalPaymentsReady): ?>
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
</section>
