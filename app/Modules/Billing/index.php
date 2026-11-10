<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$currency = static fn (mixed $cents): string => \App\Modules\Billing\BillingService::money((int) $cents);
$canWrite = (bool) ($canWrite ?? false);
$categories = [
    'opening_balance' => 'Saldo inicial / ajuste de saldo anterior',
    'interest' => 'Interés moratorio',
    'legal' => 'Cobro por proceso jurídico',
    'fine' => 'Multas',
    'retroactive' => 'Retroactivo',
    'extra' => 'Cuota extra',
    'other' => 'Otros cobros',
];

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-receipt me-2" aria-hidden="true"></i>Cartera / Cobros</h1>
        <p class="text-secondary mb-0">Previsualiza, emite y notifica las notas mensuales de los inmuebles con usuarios activos asignados.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-end gap-3">
            <form method="get" action="<?= $escape($basePath) ?>/cartera/cobros" class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label for="billing-period" class="form-label">Período mensual</label>
                    <input id="billing-period" name="periodo" type="month" class="form-control" required value="<?= $escape($period) ?>" max="<?= $escape((new DateTimeImmutable('first day of this month'))->format('Y-m')) ?>">
                </div>
                <button class="btn btn-outline-primary" type="submit">Consultar período</button>
            </form>
            <?php if (is_array($preview) && $canWrite && $preview['ready']): ?>
                <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/emitir" data-confirm="Se emitirán las notas pendientes y se enviarán correos a los usuarios asignados. Los documentos emitidos no se pueden editar. ¿Deseas continuar?">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="period" value="<?= $escape($period) ?>">
                    <button class="btn btn-primary" type="submit" data-loading-label="Emitiendo y enviando...">
                        Emitir y enviar <?= (int) $preview['new_count'] ?> notas
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($preview)): ?>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-primary"><i class="bi bi-houses" aria-hidden="true"></i></span>
                    <div class="info-box-content"><span class="info-box-text">Inmuebles con asignación activa</span><span class="info-box-number"><?= count($preview['rows']) ?></span></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-success"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></span>
                    <div class="info-box-content"><span class="info-box-text">Notas ya emitidas</span><span class="info-box-number"><?= (int) $preview['issued_count'] ?></span></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-info"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
                    <div class="info-box-content"><span class="info-box-text">Nuevas notas para emitir</span><span class="info-box-number"><?= (int) $preview['new_count'] ?></span></div>
                </div>
            </div>
        </div>

        <?php if ($preview['blocking_reasons'] !== []): ?>
            <div class="alert alert-warning" role="status">
                <strong>Antes de emitir:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach ($preview['blocking_reasons'] as $reason): ?>
                        <li><?= $escape($reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($canWrite && $preview['new_count'] > 0): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Registrar conceptos del período</h2></div>
                <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/concepto">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="period" value="<?= $escape($period) ?>">
                        <div class="row g-3">
                            <div class="col-lg-3">
                                <label for="billing-unit" class="form-label">Inmueble</label>
                                <select id="billing-unit" name="unit_id" class="form-select" required>
                                    <?php foreach ($preview['rows'] as $row): ?>
                                        <option value="<?= $escape($row['id']) ?>" <?= $row['already_issued'] ? 'disabled' : '' ?>>
                                            #<?= $escape($row['name']) ?><?= $row['already_issued'] ? ' (emitido)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2">
                                <label for="billing-category" class="form-label">Concepto</label>
                                <select id="billing-category" name="category" class="form-select" required>
                                    <?php foreach ($categories as $key => $label): ?>
                                        <option value="<?= $escape($key) ?>"><?= $escape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-3">
                                <label for="billing-description" class="form-label">Descripción</label>
                                <input id="billing-description" name="description" class="form-control" maxlength="160" required placeholder="Detalle del cobro o ajuste">
                            </div>
                            <div class="col-lg-2">
                                <label for="billing-amount" class="form-label">Valor (COP)</label>
                                <input id="billing-amount" name="amount" type="number" class="form-control" min="0.01" max="9999999999999.99" step="0.01" required>
                            </div>
                            <div class="col-lg-2 d-flex align-items-end">
                                <button class="btn btn-outline-primary" type="submit">Agregar concepto</button>
                            </div>
                        </div>
                        <small class="text-secondary d-block mt-2">Los intereses, multas y demás conceptos se registran manualmente para que la administración verifique su soporte y valor antes de emitir. Los movimientos quedan bloqueados al emitir la nota.</small>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h2 class="card-title mb-0">Previsualización · <?= $escape($period) ?></h2>
                <?php if ($preview['rows'] !== []): ?>
                    <input id="billing-filter" class="form-control form-control-sm" style="max-width: 20rem" type="search" placeholder="Filtrar por inmueble o usuario" aria-label="Filtrar por inmueble o usuario">
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0" id="billing-preview-table">
                    <thead>
                    <tr><th>Inmueble</th><th>Usuario(s) asignado(s)</th><th>Saldo anterior</th><th>Cuota</th><th>Otros conceptos</th><th>Total del período</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['rows'] as $row): ?>
                        <?php
                        $otherCharges = array_sum($row['items']);
                        $recipientNames = implode(', ', array_column($row['recipients'], 'username'));
                        $searchText = mb_strtolower($row['name'] . ' ' . $recipientNames);
                        ?>
                        <tr data-search="<?= $escape($searchText) ?>">
                            <td><strong>#<?= $escape($row['name']) ?></strong><br><small class="text-secondary"><?= $escape($row['category']) ?></small></td>
                            <td><?= $escape($recipientNames) ?><br><small class="text-secondary"><?= $escape(implode(', ', array_column($row['recipients'], 'email'))) ?></small></td>
                            <td><?= $currency($row['opening_balance']) ?></td>
                            <td><?= $currency($row['fee']) ?></td>
                            <td><?= $currency($otherCharges) ?></td>
                            <td><strong><?= $currency($row['due']) ?></strong>
                                <?php if ($row['credit'] > 0): ?><br><small class="text-success">Saldo a favor: <?= $currency($row['credit']) ?></small><?php endif; ?>
                                <br><small class="text-secondary">Pronto pago: <?= $currency($row['early_payable']) ?></small>
                            </td>
                            <td>
                                <?php if ($row['already_issued']): ?>
                                    <a class="badge text-bg-success text-decoration-none" href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= (int) $row['statement_id'] ?>">Emitida · ver nota</a>
                                <?php elseif ($row['prior_period_invalid']): ?>
                                    <span class="badge text-bg-warning">Emitir mes anterior primero</span>
                                <?php elseif ($row['invalid_recipient']): ?>
                                    <span class="badge text-bg-danger">Revisar asignación / correo</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Pendiente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($row['item_details'] !== []): ?>
                            <tr class="billing-details" data-search="<?= $escape($searchText) ?>">
                                <td colspan="7"><details><summary>Conceptos registrados</summary>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($row['item_details'] as $item): ?>
                                            <li class="d-flex flex-wrap justify-content-between gap-2">
                                                <span><?= $escape($item['description']) ?> · <?= $escape($categories[$item['category']] ?? $item['category']) ?> · <?= $currency(\App\Modules\Billing\BillingService::moneyToCents($item['amount'])) ?></span>
                                                <?php if ($canWrite && !$row['already_issued']): ?>
                                                    <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/concepto/eliminar">
                                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                        <input type="hidden" name="period" value="<?= $escape($period) ?>">
                                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                        <button class="btn btn-link btn-sm text-danger p-0" type="submit" onclick="return window.confirm('¿Eliminar este concepto pendiente?')">Eliminar antes de emitir</button>
                                                    </form>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($preview['rows'] === []): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No hay inmuebles con usuarios activos asignados para este período.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2 class="card-title mb-0">Notas emitidas · <?= $escape($period) ?></h2>
            <?php if ($canWrite && array_sum(array_map(static fn (array $statement): int => (int) $statement['failed_count'] + (int) $statement['pending_count'], $statements)) > 0): ?>
                <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/reintentar">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="period" value="<?= $escape($period) ?>">
                    <button class="btn btn-outline-warning btn-sm" type="submit">Enviar / reintentar correos pendientes</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Consecutivo</th><th>Inmueble</th><th>Total</th><th>Pagos registrados</th><th>Correos</th><th>Nota</th></tr></thead>
                <tbody>
                <?php foreach ($statements as $statement): ?>
                    <?php
                    $sent = (int) $statement['sent_count'];
                    $failed = (int) $statement['failed_count'];
                    $recipientCount = (int) $statement['recipient_count'];
                    $totalDueCents = \App\Modules\Billing\BillingService::moneyToCents($statement['total_due']);
                    ?>
                    <tr>
                        <td>NC-<?= $escape(str_replace('-', '', $statement['period'])) ?>-<?= str_pad((string) $statement['id'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td>#<?= $escape($statement['unit_name']) ?></td>
                        <td>
                            <?= $currency(max(0, $totalDueCents)) ?>
                            <?php if ($totalDueCents < 0): ?>
                                <br><small class="text-success">Saldo a favor: <?= $currency(-$totalDueCents) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $currency(\App\Modules\Billing\BillingService::moneyToCents($statement['payments_total'])) ?></td>
                        <td>
                            <span class="badge text-bg-<?= $failed > 0 ? 'danger' : ($sent === $recipientCount ? 'success' : 'warning') ?>">
                                <?= $sent ?>/<?= $recipientCount ?> enviados<?= $failed > 0 ? ' · ' . $failed . ' fallidos' : '' ?>
                            </span>
                        </td>
                        <td><a class="btn btn-outline-primary btn-sm" href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= (int) $statement['id'] ?>">Ver / imprimir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($statements === []): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">Aún no se han emitido notas para este período.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canWrite && $statements !== []): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Registrar un pago recibido</h2>
                <p class="small text-secondary mb-0">Selecciona la nota del período al que se aplica el pago; los pagos de un período anterior deben registrarse antes de emitir el siguiente.</p>
            </div>
            <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/pago">
                <div class="card-body">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="period" value="<?= $escape($period) ?>">
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <label for="payment-statement" class="form-label">Nota / inmueble</label>
                            <select id="payment-statement" name="statement_id" class="form-select" required>
                                <?php foreach ($statements as $statement): ?>
                                    <option value="<?= (int) $statement['id'] ?>">#<?= $escape($statement['unit_name']) ?> · NC-<?= $escape(str_replace('-', '', $statement['period'])) ?>-<?= str_pad((string) $statement['id'], 6, '0', STR_PAD_LEFT) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2">
                            <label for="payment-amount" class="form-label">Valor recibido (COP)</label>
                            <input id="payment-amount" name="amount" type="number" class="form-control" min="0.01" max="9999999999999.99" step="0.01" required>
                        </div>
                        <div class="col-lg-2">
                            <label for="payment-date" class="form-label">Fecha de recepción</label>
                            <input id="payment-date" name="received_on" type="date" class="form-control" required value="<?= $escape((new DateTimeImmutable('today'))->format('Y-m-d')) ?>" max="<?= $escape((new DateTimeImmutable('today'))->format('Y-m-d')) ?>">
                        </div>
                        <div class="col-lg-2">
                            <label for="payment-reference" class="form-label">Referencia (opcional)</label>
                            <input id="payment-reference" name="reference" class="form-control" maxlength="80">
                        </div>
                        <div class="col-lg-2">
                            <label for="payment-notes" class="form-label">Notas (opcional)</label>
                            <input id="payment-notes" name="notes" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="card-footer"><button class="btn btn-outline-primary" type="submit">Registrar pago</button></div>
            </form>
        </div>
    <?php endif; ?>

    <div class="alert alert-info" role="note">
        La emisión es única por inmueble y mes; el consecutivo no se reutiliza. Los importes y datos bancarios quedan como copia del momento de emisión. Los pagos y cargos adicionales se registran por separado. Esta versión envía una nota imprimible por correo; no reemplaza la revisión contable ni constituye una factura electrónica.
    </div>
</section>
<script>
    document.getElementById('billing-filter')?.addEventListener('input', function () {
        const query = this.value.trim().toLocaleLowerCase();
        document.querySelectorAll('#billing-preview-table tbody tr[data-search]').forEach((row) => {
            row.hidden = !row.dataset.search.includes(query);
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                return;
            }
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = button.dataset.loadingLabel;
            }
        });
    });
</script>
