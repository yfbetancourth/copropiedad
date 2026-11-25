<?php

declare(strict_types=1);

use App\Modules\Billing\BankImportService;
use App\Modules\Billing\BillingService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$money = static fn (int $cents): string => BillingService::money($cents);
$isDraft = $import['status'] === 'draft';
$proposed = array_count_values(array_column($movements, 'proposed_action'));
$statusBadges = ['pending' => 'text-bg-light border', 'applied' => 'text-bg-success', 'unidentified' => 'text-bg-warning', 'omitted' => 'text-bg-secondary', 'failed' => 'text-bg-danger'];
$actionBadges = ['apply' => 'text-bg-success', 'report' => 'text-bg-primary', 'unidentified' => 'text-bg-warning', 'omit' => 'text-bg-secondary'];
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-bank me-2" aria-hidden="true"></i><?= $isDraft ? 'Revisar extracto' : 'Extracto importado' ?></h1>
            <p class="text-secondary mb-0">
                <?= $escape($import['file_name']) ?> · <?= $escape($import['format']) ?> · <?= (int) $import['movements_count'] ?> ingreso(s) por <?= $escape($money(BillingService::moneyToCents($import['total_amount']))) ?>
                <?php if (!$isDraft): ?> · confirmado el <?= $escape(date('d/m/Y h:i a', strtotime((string) $import['confirmed_at']) ?: 0)) ?><?= $import['confirmed_by_name'] ? ' por ' . $escape($import['confirmed_by_name']) : '' ?><?php endif; ?>
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/extractos"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($isDraft): ?>
        <div class="alert alert-info">
            <strong>Aún no se ha registrado nada.</strong> Revisa la propuesta de cada ingreso y cámbiala si hace falta:
            <?= (int) ($proposed['apply'] ?? 0) ?> con referencia de pago, <?= (int) ($proposed['report'] ?? 0) ?> que coinciden con un pago reportado,
            <?= (int) ($proposed['unidentified'] ?? 0) ?> sin identificar y <?= (int) ($proposed['omit'] ?? 0) ?> para omitir.
            Lo que envíes a «por identificar» podrás asignarlo después, con sugerencias por valor y los reclamos de los residentes.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($basePath) ?>/cartera/extractos/confirmar" id="import-form">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="import_id" value="<?= (int) $import['id'] ?>">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <caption class="visually-hidden">Ingresos del extracto</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col" class="text-end">Valor</th>
                        <th scope="col">Descripción del banco</th>
                        <th scope="col">Propuesta</th>
                        <th scope="col"><?= $isDraft ? 'Acción' : 'Resultado' ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($movements as $movement): ?>
                    <?php $movementId = (int) $movement['id']; ?>
                    <tr>
                        <td class="text-nowrap small"><?= $escape(date('d/m/Y', strtotime((string) $movement['movement_date']) ?: 0)) ?><div class="text-secondary">línea <?= (int) $movement['line_number'] ?></div></td>
                        <td class="text-end text-nowrap fw-semibold"><?= $escape($money($movement['amount_cents'])) ?></td>
                        <td class="small">
                            <?= $escape($movement['description']) ?>
                            <?php if ($movement['reference'] !== '' || $movement['document'] !== ''): ?>
                                <div class="text-secondary font-monospace"><?= $movement['reference'] !== '' ? 'Ref. ' . $escape($movement['reference']) : '' ?><?= $movement['document'] !== '' ? ' Doc. ' . $escape($movement['document']) : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <span class="badge <?= $escape($actionBadges[$movement['proposed_action']]) ?>"><?= $escape(BankImportService::ACTION_LABELS[$movement['proposed_action']]) ?></span>
                            <?php if ($movement['unit_name'] !== null && $movement['proposed_action'] !== 'omit'): ?><strong class="ms-1"><?= $escape($movement['unit_name']) ?></strong><?php endif; ?>
                            <div class="text-secondary"><?= $escape($movement['proposal_note']) ?></div>
                        </td>
                        <td class="small" style="min-width: 190px">
                            <?php if ($isDraft && $canWrite): ?>
                                <label class="visually-hidden" for="action-<?= $movementId ?>">Acción para la línea <?= (int) $movement['line_number'] ?></label>
                                <select class="form-select form-select-sm" id="action-<?= $movementId ?>" name="action[<?= $movementId ?>]">
                                    <?php foreach (BankImportService::ACTION_LABELS as $key => $label): ?>
                                        <?php
                                        if (($key === 'apply' && $movement['proposed_statement_id'] === null) || ($key === 'report' && $movement['proposed_report_id'] === null)
                                            || ($key === 'apply' && $movement['proposed_action'] === 'report')) {
                                            continue;
                                        }
                                        $optionLabel = $key === 'apply' && $movement['unit_name'] !== null ? 'Registrar pago de ' . $movement['unit_name'] : $label;
                                        ?>
                                        <option value="<?= $escape($key) ?>"<?= $movement['proposed_action'] === $key ? ' selected' : '' ?>><?= $escape($optionLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (!$isDraft): ?>
                                <span class="badge <?= $escape($statusBadges[$movement['status']]) ?>"><?= $escape(BankImportService::STATUS_LABELS[$movement['status']]) ?></span>
                                <?php if ($movement['payment_id'] !== null): ?>
                                    <a class="d-block" href="<?= $escape($basePath) ?>/cartera/cobros/comprobante?id=<?= (int) $movement['payment_id'] ?>" target="_blank" rel="noopener"><?= $escape(BillingService::receiptNumber((int) $movement['payment_id'])) ?></a>
                                <?php elseif ($movement['status'] === 'unidentified'): ?>
                                    <a class="d-block" href="<?= $escape($basePath) ?>/cartera/consignaciones">Ver en por identificar</a>
                                <?php endif; ?>
                                <?php if ($movement['result_note']): ?><div class="text-secondary"><?= $escape($movement['result_note']) ?></div><?php endif; ?>
                            <?php else: ?>
                                <span class="text-secondary">Sin permiso para confirmar</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($isDraft && $canWrite): ?>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" type="submit" form="import-form" onclick="return confirm('¿Confirmar la importación? Los pagos se registran y se avisa a los residentes.');"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Confirmar importación</button>
            <form method="post" action="<?= $escape($basePath) ?>/cartera/extractos/descartar" onsubmit="return confirm('¿Descartar esta importación? No se registrará nada.');">
                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="import_id" value="<?= (int) $import['id'] ?>">
                <button class="btn btn-outline-danger" type="submit">Descartar</button>
            </form>
        </div>
    <?php endif; ?>
</section>
