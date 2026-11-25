<?php

declare(strict_types=1);

use App\Modules\Billing\AccountDocumentService;
use App\Modules\Billing\BillingService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$money = static fn (int $cents): string => BillingService::money($cents);
$date = static fn (?string $value): string => $value ? date('d/m/Y', strtotime($value) ?: 0) : '';
$publicBasePath = preg_replace('~/admin$~', '', $basePath) ?? $basePath;
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Documentos de cartera</h1>
        <p class="text-secondary mb-0">Estado de cuenta y paz y salvo de cada inmueble. El paz y salvo solo se expide si el inmueble está al día, vence el último día del mes y trae un código que cualquiera puede comprobar en <a href="<?= $escape($publicBasePath) ?>/verificar-paz-y-salvo" target="_blank" rel="noopener">la página de verificación</a>. Los residentes también pueden descargarlos desde su portal y la administración puede adjuntarlos en Atención a residentes.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h2 class="card-title h6 mb-0">Inmuebles con notas de cobro</h2></div>
                <div class="card-body p-0">
                    <?php if ($units === []): ?>
                        <p class="text-secondary p-3 mb-0">Aún no hay notas de cobro emitidas.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th scope="col">Inmueble</th><th scope="col">Ref. de pago</th><th scope="col">Última nota</th><th scope="col" class="text-end">Saldo</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
                                <tbody>
                                <?php foreach ($units as $unit): ?>
                                    <?php $balance = (int) $unit['balance']; ?>
                                    <tr>
                                        <td><?= $escape($unit['unit_name']) ?></td>
                                        <td class="font-monospace small"><?= $escape($references[$unit['unit_id']] ?? '—') ?></td>
                                        <td class="small"><?= $escape($unit['period']) ?></td>
                                        <td class="text-end<?= $balance > 0 ? ' text-danger' : ' text-success' ?>"><?= $escape($money(max(0, $balance))) ?></td>
                                        <td class="text-end text-nowrap">
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/documentos/estado-de-cuenta?inmueble=<?= $escape($unit['unit_id']) ?>" target="_blank" rel="noopener">Estado de cuenta</a>
                                            <?php if ($canWrite && $balance <= 0): ?>
                                                <form method="post" action="<?= $escape($basePath) ?>/cartera/documentos/paz-y-salvo/expedir" class="d-inline">
                                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                    <input type="hidden" name="unit_id" value="<?= $escape($unit['unit_id']) ?>">
                                                    <button class="btn btn-sm btn-success" type="submit">Expedir paz y salvo</button>
                                                </form>
                                            <?php elseif ($balance > 0): ?>
                                                <span class="small text-secondary ms-1" title="Tiene saldo pendiente">Sin paz y salvo</span>
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
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title h6 mb-0">Últimos paz y salvo expedidos</h2></div>
                <div class="card-body">
                    <?php if ($clearances === []): ?>
                        <p class="small text-secondary mb-0">Aún no se ha expedido ninguno.</p>
                    <?php else: ?>
                        <ol class="list-unstyled mb-0">
                            <?php foreach ($clearances as $clearance): ?>
                                <li class="border-bottom py-2 small">
                                    <div class="d-flex justify-content-between gap-2">
                                        <a href="<?= $escape($basePath) ?>/cartera/documentos/paz-y-salvo?id=<?= (int) $clearance['id'] ?>" target="_blank" rel="noopener"><strong><?= $escape($clearance['unit_name']) ?></strong></a>
                                        <span class="badge <?= $clearance['expired'] ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $clearance['expired'] ? 'Vencido' : 'Vigente' ?></span>
                                    </div>
                                    <div class="font-monospace"><?= $escape(AccountDocumentService::formatCode($clearance['code'])) ?></div>
                                    <div class="text-secondary">
                                        <?= $escape($date($clearance['issued_at'])) ?> · hasta <?= $escape($date($clearance['valid_until'])) ?> ·
                                        <?= $clearance['requested_by_name'] !== null && $clearance['issued_by_name'] === null ? 'pedido por ' . $escape($clearance['requested_by_name']) . ' en el portal' : 'expedido por ' . $escape($clearance['issued_by_name'] ?? 'la administración') ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
