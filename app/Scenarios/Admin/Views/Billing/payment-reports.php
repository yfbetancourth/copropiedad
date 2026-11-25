<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;
use App\Modules\Billing\PaymentReportService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn (mixed $amount): string => BillingService::money(BillingService::moneyToCents($amount));
$filters = ['open' => 'Por revisar', 'approved' => 'Aprobados', 'rejected' => 'Rechazados', 'all' => 'Todos'];
$statusBadges = ['pending' => 'warning', 'processing' => 'info', 'approved' => 'success', 'rejected' => 'danger'];
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-inbox me-2" aria-hidden="true"></i>Pagos reportados</h1>
            <p class="text-secondary mb-0">Pagos por transferencia, consignación o efectivo que informan los residentes con su soporte. Al aprobar, el pago se registra en la nota vigente del inmueble y se avisa al residente con su comprobante.</p>
        </div>
        <?php if ($pendingCount > 0): ?>
            <span class="badge text-bg-warning fs-6"><?= (int) $pendingCount ?> por revisar</span>
        <?php endif; ?>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <ul class="nav nav-pills mb-3">
        <?php foreach ($filters as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link<?= $filter === $key ? ' active' : '' ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>
                   href="<?= $escape($basePath) ?>/cartera/pagos-reportados?estado=<?= $escape($key) ?>"><?= $escape($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($reports === []): ?>
        <div class="card"><div class="card-body text-center text-secondary py-4">
            <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success" aria-hidden="true"></i>
            <?php if ($filter === 'open'): ?>
                <strong class="d-block text-body">No hay pagos reportados por revisar.</strong>
                Cuando un residente informe un pago con su soporte desde el portal, aparecerá aquí para aprobarlo o rechazarlo.
            <?php else: ?>
                No hay pagos reportados en esta vista.
            <?php endif; ?>
        </div></div>
    <?php endif; ?>

    <?php foreach ($reports as $report): ?>
        <?php $open = $report['status'] === 'pending'; ?>
        <div class="card mb-3 <?= $open ? 'card-warning card-outline' : '' ?>">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h2 class="card-title h6 mb-0 me-auto">
                    <?= $escape((string) $report['unit_name']) ?> · nota <?= $escape((string) $report['period']) ?>
                    <span class="text-secondary fw-normal">· reportado por <?= $escape((string) $report['username']) ?> el <?= $escape(substr((string) $report['created_at'], 0, 16)) ?></span>
                </h2>
                <span class="badge text-bg-<?= $statusBadges[$report['status']] ?? 'secondary' ?>"><?= $escape(PaymentReportService::STATUS_LABELS[$report['status']] ?? (string) $report['status']) ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <dl class="row mb-0 small">
                            <dt class="col-5">Valor reportado</dt><dd class="col-7 fw-semibold"><?= $escape($money($report['amount'])) ?></dd>
                            <dt class="col-5">Fecha del pago</dt><dd class="col-7"><?= $escape((string) $report['paid_on']) ?></dd>
                            <dt class="col-5">Medio</dt><dd class="col-7"><?= $escape(PaymentReportService::METHOD_LABELS[$report['method']] ?? (string) $report['method']) ?></dd>
                            <dt class="col-5">Referencia</dt><dd class="col-7"><?= $escape((string) $report['reference'] !== '' ? (string) $report['reference'] : '—') ?></dd>
                            <dt class="col-5">Observaciones</dt><dd class="col-7"><?= $escape((string) $report['notes'] !== '' ? (string) $report['notes'] : '—') ?></dd>
                            <dt class="col-5">Correo</dt><dd class="col-7"><?= $escape((string) $report['email']) ?></dd>
                            <?php if (!$open && $report['reviewer_name'] !== null): ?>
                                <dt class="col-5">Revisado por</dt><dd class="col-7"><?= $escape((string) $report['reviewer_name']) ?> · <?= $escape(substr((string) $report['reviewed_at'], 0, 16)) ?></dd>
                            <?php endif; ?>
                            <?php if ($report['status'] === 'rejected'): ?>
                                <dt class="col-5">Motivo</dt><dd class="col-7 text-danger"><?= $escape((string) $report['review_notes']) ?></dd>
                            <?php endif; ?>
                        </dl>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/cartera/pagos-reportados/soporte?id=<?= (int) $report['id'] ?>">
                                <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Ver soporte (<?= $report['proof_mime_type'] === 'application/pdf' ? 'PDF' : 'imagen' ?>)
                            </a>
                            <?php if ($report['payment_id'] !== null): ?>
                                <a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/cartera/cobros/comprobante?id=<?= (int) $report['payment_id'] ?>">
                                    <i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>Comprobante <?= $escape(BillingService::receiptNumber((int) $report['payment_id'])) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($open && $canReview): ?>
                        <div class="col-lg-7">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <form method="post" action="<?= $escape($basePath) ?>/cartera/pagos-reportados/aprobar" class="border rounded p-3 h-100">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                        <h3 class="h6">Aprobar y registrar</h3>
                                        <p class="small text-secondary">Corrige el valor o la fecha si no coinciden con el extracto.</p>
                                        <label class="form-label small" for="approve-amount-<?= (int) $report['id'] ?>">Valor recibido</label>
                                        <input id="approve-amount-<?= (int) $report['id'] ?>" class="form-control form-control-sm mb-2" type="number" name="amount" min="0.01" step="0.01" required value="<?= $escape((string) $report['amount']) ?>">
                                        <label class="form-label small" for="approve-date-<?= (int) $report['id'] ?>">Fecha de recepción</label>
                                        <input id="approve-date-<?= (int) $report['id'] ?>" class="form-control form-control-sm mb-3" type="date" name="received_on" max="<?= $escape($today) ?>" required value="<?= $escape((string) $report['paid_on']) ?>">
                                        <button class="btn btn-success btn-sm w-100" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar pago</button>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <form method="post" action="<?= $escape($basePath) ?>/cartera/pagos-reportados/rechazar" class="border rounded p-3 h-100">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                        <h3 class="h6">Rechazar</h3>
                                        <p class="small text-secondary">El residente verá este motivo y lo recibirá por correo.</p>
                                        <label class="form-label small" for="reject-reason-<?= (int) $report['id'] ?>">Motivo</label>
                                        <textarea id="reject-reason-<?= (int) $report['id'] ?>" class="form-control form-control-sm mb-3" name="reason" rows="3" minlength="5" maxlength="255" required></textarea>
                                        <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar reporte</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($filter === 'open'): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title h6 mb-0">Últimos pagos reportados ya revisados</h2>
                <a class="small" href="<?= $escape($basePath) ?>/cartera/pagos-reportados?estado=all">Ver todos</a>
            </div>
            <?php if ($recent === []): ?>
                <div class="card-body text-secondary">Aún no se ha revisado ningún pago reportado.</div>
            <?php else: ?>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-billing-payment-reports-1">
                    <table class="table table-sm align-middle mb-0">
                        <caption id="tbl-billing-payment-reports-1" class="visually-hidden">Últimos pagos reportados ya revisados</caption>
                        <thead><tr><th scope="col">Reportado</th><th scope="col">Residente</th><th scope="col">Inmueble · nota</th><th scope="col" class="text-end">Valor</th><th scope="col">Medio</th><th scope="col">Estado</th><th scope="col">Revisó</th><th scope="col"></th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $report): ?>
                            <tr>
                                <td class="text-nowrap"><?= $escape(substr((string) $report['created_at'], 0, 10)) ?></td>
                                <td><?= $escape((string) $report['username']) ?></td>
                                <td><?= $escape((string) $report['unit_name']) ?> · <?= $escape((string) $report['period']) ?></td>
                                <td class="text-end text-nowrap"><?= $escape($money($report['amount'])) ?></td>
                                <td><?= $escape(PaymentReportService::METHOD_LABELS[$report['method']] ?? (string) $report['method']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $statusBadges[$report['status']] ?? 'secondary' ?>"><?= $escape(PaymentReportService::STATUS_LABELS[$report['status']] ?? (string) $report['status']) ?></span>
                                    <?php if ($report['status'] === 'rejected' && (string) $report['review_notes'] !== ''): ?>
                                        <div class="small text-danger"><?= $escape((string) $report['review_notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= $escape((string) ($report['reviewer_name'] ?? '')) ?><?= $report['reviewed_at'] !== null ? '<span class="d-block text-secondary">' . $escape(substr((string) $report['reviewed_at'], 0, 10)) . '</span>' : '' ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/cartera/pagos-reportados/soporte?id=<?= (int) $report['id'] ?>">Soporte</a>
                                    <?php if ($report['payment_id'] !== null): ?>
                                        <a class="btn btn-link btn-sm" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/cartera/cobros/comprobante?id=<?= (int) $report['payment_id'] ?>"><?= $escape(BillingService::receiptNumber((int) $report['payment_id'])) ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
