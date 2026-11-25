<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;
use App\Modules\Billing\UnidentifiedDepositService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$money = static fn (int $cents): string => BillingService::money($cents);
$date = static fn (?string $value): string => $value ? date('d/m/Y', strtotime($value) ?: 0) : '';
$dateTime = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
$today = date('Y-m-d');
$kindBadges = [
    'reference' => ['text-bg-success', 'Referencia de pago'],
    'report' => ['text-bg-primary', 'Pago reportado'],
    'amount' => ['text-bg-secondary', 'Mismo valor'],
];
$emptyMessages = [
    'pending' => 'No hay consignaciones por identificar.',
    'identified' => 'Aún no se ha identificado ninguna consignación.',
    'discarded' => 'No hay consignaciones descartadas.',
];
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-question-diamond me-2" aria-hidden="true"></i>Consignaciones por identificar</h1>
            <p class="text-secondary mb-0">Dinero que llegó a la cuenta de la copropiedad sin saber de qué inmueble es (por ejemplo, el aviso del banco en tu correo). Regístralo aquí: queda separado de la cartera hasta identificarlo y, al hacerlo, se registra como pago de la nota con su comprobante y el aviso al residente.</p>
        </div>
        <?php if ($counts['pending'] > 0): ?>
            <span class="badge text-bg-warning fs-6"><?= (int) $counts['pending'] ?> por identificar · <?= $escape($money($counts['pending_amount'])) ?></span>
        <?php endif; ?>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($canWrite): ?>
        <details class="card mb-3"<?= $old !== [] || ($counts['pending'] + $counts['identified'] + $counts['discarded']) === 0 ? ' open' : '' ?>>
            <summary class="card-header fw-semibold" style="cursor:pointer"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Registrar una consignación sin identificar</summary>
            <div class="card-body">
                <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/registrar" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="deposit-amount">Valor (COP)</label>
                        <input class="form-control" type="number" id="deposit-amount" name="amount" min="1" step="0.01" inputmode="decimal" value="<?= $escape($old['amount'] ?? '') ?>" required>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="deposit-date">Fecha</label>
                        <input class="form-control" type="date" id="deposit-date" name="deposited_on" max="<?= $escape($today) ?>" value="<?= $escape($old['deposited_on'] ?? $today) ?>" required>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="deposit-channel">Medio</label>
                        <select class="form-select" id="deposit-channel" name="channel" required>
                            <?php foreach (UnidentifiedDepositService::CHANNEL_LABELS as $key => $label): ?>
                                <option value="<?= $escape($key) ?>"<?= ($old['channel'] ?? 'consignacion') === $key ? ' selected' : '' ?>><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="deposit-reference">Referencia del banco</label>
                        <input class="form-control" type="text" id="deposit-reference" name="bank_reference" maxlength="80" value="<?= $escape($old['bank_reference'] ?? '') ?>" aria-describedby="deposit-reference-help">
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="deposit-payer">Descripción o pagador que muestra el banco</label>
                        <input class="form-control" type="text" id="deposit-payer" name="payer_detail" maxlength="160" value="<?= $escape($old['payer_detail'] ?? '') ?>" placeholder="Ej.: TRANSF DE JUAN PEREZ 1094">
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="deposit-proof">Soporte <span class="text-secondary">(opcional: captura del correo del banco, imagen o PDF, máx. 5 MB)</span></label>
                        <input class="form-control" type="file" id="deposit-proof" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="deposit-notes">Notas internas <span class="text-secondary">(opcional)</span></label>
                        <input class="form-control" type="text" id="deposit-notes" name="notes" maxlength="255" value="<?= $escape($old['notes'] ?? '') ?>">
                        <div class="form-text" id="deposit-reference-help">Copia la referencia y la descripción tal como las muestra el banco: si contienen la referencia de pago de un inmueble, se sugiere automáticamente.</div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Registrar</button></div>
                </form>
            </div>
        </details>
    <?php endif; ?>

    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
        <?php foreach ($filters as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link<?= $filter === $key ? ' active' : '' ?>" href="?estado=<?= $escape($key) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>>
                    <?= $escape($label) ?> <span class="badge <?= $key === 'pending' && $counts['pending'] > 0 ? 'text-bg-warning' : 'text-bg-light border' ?> ms-1"><?= (int) $counts[$key] ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($deposits === []): ?>
        <div class="alert alert-light border"><?= $escape($emptyMessages[$filter]) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($deposits as $deposit): ?>
            <?php $depositId = (int) $deposit['id']; ?>
            <div class="col-xxl-6">
                <article class="card h-100<?= $filter === 'pending' ? ' border-warning' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <h2 class="h5 mb-1"><?= $escape($money((int) $deposit['amount_cents'])) ?></h2>
                            <span class="text-secondary small">#<?= $depositId ?> · <?= $escape(UnidentifiedDepositService::CHANNEL_LABELS[$deposit['channel']] ?? $deposit['channel']) ?> del <?= $escape($date($deposit['deposited_on'])) ?></span>
                        </div>
                        <dl class="row small mb-2">
                            <?php if ($deposit['bank_reference'] !== ''): ?><dt class="col-sm-4 text-secondary fw-normal">Referencia del banco</dt><dd class="col-sm-8 mb-1 font-monospace"><?= $escape($deposit['bank_reference']) ?></dd><?php endif; ?>
                            <?php if ($deposit['payer_detail'] !== ''): ?><dt class="col-sm-4 text-secondary fw-normal">Descripción</dt><dd class="col-sm-8 mb-1"><?= $escape($deposit['payer_detail']) ?></dd><?php endif; ?>
                            <?php if ($deposit['notes'] !== ''): ?><dt class="col-sm-4 text-secondary fw-normal">Notas</dt><dd class="col-sm-8 mb-1"><?= $escape($deposit['notes']) ?></dd><?php endif; ?>
                            <dt class="col-sm-4 text-secondary fw-normal">Registrada</dt><dd class="col-sm-8 mb-1"><?= $escape($dateTime($deposit['created_at'])) ?><?= $deposit['created_by_name'] ? ' por ' . $escape($deposit['created_by_name']) : '' ?></dd>
                        </dl>
                        <?php if ($deposit['proof_storage_name'] !== null): ?>
                            <a class="btn btn-sm btn-outline-secondary mb-2" href="<?= $escape($basePath) ?>/cartera/consignaciones/soporte?id=<?= $depositId ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1" aria-hidden="true"></i>Ver soporte</a>
                        <?php endif; ?>

                        <?php if ($filter === 'identified'): ?>
                            <div class="alert alert-success small mb-0">
                                Identificada: <strong><?= $escape($deposit['unit_name'] ?? '') ?></strong> (nota <?= $escape($deposit['period'] ?? '') ?>) ·
                                comprobante <a class="alert-link" href="<?= $escape($basePath) ?>/cartera/cobros/comprobante?id=<?= (int) $deposit['payment_id'] ?>" target="_blank" rel="noopener"><?= $escape(BillingService::receiptNumber((int) $deposit['payment_id'])) ?></a>
                                <br><?= $escape($dateTime($deposit['resolved_at'])) ?><?= $deposit['resolved_by_name'] ? ' por ' . $escape($deposit['resolved_by_name']) : '' ?><?= $deposit['resolution_note'] ? ' · ' . $escape($deposit['resolution_note']) : '' ?>
                            </div>
                        <?php elseif ($filter === 'discarded'): ?>
                            <div class="alert alert-secondary small mb-0">
                                Descartada: <?= $escape($deposit['resolution_note'] ?? '') ?><br><?= $escape($dateTime($deposit['resolved_at'])) ?><?= $deposit['resolved_by_name'] ? ' por ' . $escape($deposit['resolved_by_name']) : '' ?>
                            </div>
                        <?php elseif ($deposit['status'] === 'processing'): ?>
                            <div class="alert alert-info small mb-0">Se está aplicando en este momento. Recarga la página en unos segundos.</div>
                        <?php else: ?>
                            <?php if ($canWrite): ?>
                                <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/publicar" class="d-flex align-items-center gap-2 small mb-2">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="deposit_id" value="<?= $depositId ?>">
                                    <input type="hidden" name="published" value="<?= (int) $deposit['published_to_residents'] === 1 ? '0' : '1' ?>">
                                    <?php if ((int) $deposit['published_to_residents'] === 1): ?>
                                        <span class="text-success"><i class="bi bi-eye me-1" aria-hidden="true"></i>Visible en el portal para que su dueño la reclame</span>
                                        <button class="btn btn-link btn-sm p-0" type="submit">Ocultar</button>
                                    <?php else: ?>
                                        <span class="text-secondary"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Oculta en el portal</span>
                                        <button class="btn btn-link btn-sm p-0" type="submit">Mostrar</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>

                            <?php if (($deposit['claims'] ?? []) !== []): ?>
                                <h3 class="h6 mt-2"><i class="bi bi-person-raised-hand me-1" aria-hidden="true"></i>Reclamada por residentes</h3>
                                <ul class="list-group mb-3">
                                    <?php foreach ($deposit['claims'] as $claim): ?>
                                        <?php $claimId = (int) $claim['id']; ?>
                                        <li class="list-group-item list-group-item-warning">
                                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                                <div class="small">
                                                    <strong><?= $escape($claim['unit_name']) ?></strong> · <?= $escape($claim['username']) ?>
                                                    <span class="text-secondary">· <?= $escape($dateTime($claim['created_at'])) ?></span>
                                                    <?php if ($claim['notes'] !== ''): ?><div><?= $escape($claim['notes']) ?></div><?php endif; ?>
                                                    <a href="<?= $escape($basePath) ?>/cartera/consignaciones/reclamo/soporte?id=<?= $claimId ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip" aria-hidden="true"></i> Ver soporte del residente</a>
                                                </div>
                                                <?php if ($canWrite): ?>
                                                    <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/reclamo/aprobar" onsubmit="return confirm(<?= $escape(json_encode('¿Aprobar el reclamo y registrar ' . $money((int) $deposit['amount_cents']) . ' como pago de ' . $claim['unit_name'] . '?', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>);">
                                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                        <input type="hidden" name="claim_id" value="<?= $claimId ?>">
                                                        <button class="btn btn-sm btn-success" type="submit">Aprobar reclamo</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($canWrite): ?>
                                                <details class="mt-1">
                                                    <summary class="small text-danger">Rechazar reclamo</summary>
                                                    <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/reclamo/rechazar" class="d-flex gap-2 mt-2">
                                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                        <input type="hidden" name="claim_id" value="<?= $claimId ?>">
                                                        <label class="visually-hidden" for="reject-claim-<?= $claimId ?>">Motivo para el residente</label>
                                                        <input class="form-control form-control-sm" type="text" id="reject-claim-<?= $claimId ?>" name="reason" maxlength="255" required placeholder="Motivo para el residente">
                                                        <button class="btn btn-sm btn-outline-danger text-nowrap" type="submit">Rechazar</button>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <h3 class="h6 mt-2">Sugerencias</h3>
                            <?php if ($deposit['suggestions'] === []): ?>
                                <p class="small text-secondary">No hay coincidencias por referencia, pago reportado ni valor. Asígnala a mano cuando sepas de qué inmueble es.</p>
                            <?php else: ?>
                                <ul class="list-group mb-3">
                                    <?php foreach ($deposit['suggestions'] as $suggestion): ?>
                                        <?php [$badgeClass, $badgeLabel] = $kindBadges[$suggestion['kind']]; ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                            <div class="small">
                                                <span class="badge <?= $escape($badgeClass) ?> me-1"><?= $escape($badgeLabel) ?></span>
                                                <strong><?= $escape($suggestion['unit_name']) ?></strong>
                                                <?= $suggestion['reference'] !== null ? '<span class="text-secondary">· ref. ' . $escape($suggestion['reference']) . '</span>' : '' ?>
                                                <span class="text-secondary">· saldo <?= $escape($money($suggestion['balance'])) ?></span>
                                                <div class="text-secondary"><?= $escape($suggestion['reason']) ?></div>
                                            </div>
                                            <?php if ($canWrite): ?>
                                                <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/identificar" onsubmit="return confirm(<?= $escape(json_encode('¿Registrar ' . $money((int) $deposit['amount_cents']) . ' como pago de ' . $suggestion['unit_name'] . '?', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>);">
                                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                    <input type="hidden" name="deposit_id" value="<?= $depositId ?>">
                                                    <input type="hidden" name="statement_id" value="<?= (int) $suggestion['statement_id'] ?>">
                                                    <?php if (isset($suggestion['report_id'])): ?><input type="hidden" name="report_id" value="<?= (int) $suggestion['report_id'] ?>"><?php endif; ?>
                                                    <button class="btn btn-sm btn-success" type="submit"><?= isset($suggestion['report_id']) ? 'Aprobar el reporte' : 'Aplicar' ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($canWrite): ?>
                                <div class="row g-2">
                                    <div class="col-lg-7">
                                        <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/identificar" class="d-flex gap-2" onsubmit="return confirm('¿Registrar esta consignación como pago del inmueble seleccionado?');">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="deposit_id" value="<?= $depositId ?>">
                                            <label class="visually-hidden" for="assign-<?= $depositId ?>">Asignar a un inmueble</label>
                                            <select class="form-select form-select-sm" id="assign-<?= $depositId ?>" name="statement_id" required>
                                                <option value="">Asignar a un inmueble…</option>
                                                <?php foreach ($statements as $option): ?>
                                                    <option value="<?= (int) $option['statement_id'] ?>"><?= $escape($option['unit_name'] . ($option['reference'] !== null ? ' · ref. ' . $option['reference'] : '') . ' · saldo ' . $money($option['balance'])) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">Asignar</button>
                                        </form>
                                    </div>
                                    <div class="col-lg-5">
                                        <details>
                                            <summary class="small text-danger">Descartar</summary>
                                            <form method="post" action="<?= $escape($basePath) ?>/cartera/consignaciones/descartar" class="mt-2">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="deposit_id" value="<?= $depositId ?>">
                                                <label class="form-label small" for="discard-<?= $depositId ?>">Motivo</label>
                                                <input class="form-control form-control-sm" type="text" id="discard-<?= $depositId ?>" name="reason" maxlength="255" required placeholder="Ej.: registrada dos veces">
                                                <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Descartar</button>
                                            </form>
                                        </details>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>
