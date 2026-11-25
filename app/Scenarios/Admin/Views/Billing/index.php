<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$currency = static fn (mixed $cents): string => BillingService::money((int) $cents);
// Versión corta para tarjetas y tablas: «$ 250.000» (los centavos solo si existen).
$short = static function (int $cents): string {
    $text = '$ ' . ($cents < 0 ? '-' : '') . number_format(intdiv(abs($cents), 100), 0, ',', '.');
    return abs($cents) % 100 !== 0 ? $text . ',' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT) : $text;
};
$canWrite = (bool) ($canWrite ?? false);
$canVoid = (bool) ($canVoid ?? false);
$categories = [
    'opening_balance' => ['Saldo inicial / ajuste de saldo anterior', 'Deuda que el inmueble traía antes de usar el sistema, o corrección del saldo anterior.'],
    'interest' => ['Interés moratorio', 'Calcúlalo con la tasa autorizada y conserva el soporte del cálculo.'],
    'legal' => ['Cobro por proceso jurídico', 'Honorarios o gastos de cobro jurídico aprobados.'],
    'fine' => ['Multas', 'Sanción impuesta según el reglamento; indica el motivo en la descripción.'],
    'retroactive' => ['Retroactivo', 'Diferencia de cuotas de meses anteriores por un aumento aprobado.'],
    'extra' => ['Cuota extra', 'Cuota extraordinaria aprobada por la asamblea.'],
    'other' => ['Otros cobros', 'Cualquier otro cobro con soporte; descríbelo con claridad.'],
];

$rows = is_array($preview) ? $preview['rows'] : [];
// Valor para ordenar montos (pueden ser negativos): entero desplazado, que el orden numérico compara bien.
$sortCents = static fn (int $cents): string => (string) ($cents + 1000000000000000);
$rowState = static function (array $row): string {
    if ($row['already_issued']) return 'issued';
    if ($row['prior_period_invalid'] || $row['coefficient_missing'] || $row['invalid_recipient']) return 'problem';
    return 'pending';
};
$stateCounts = ['pending' => 0, 'problem' => 0, 'issued' => 0];
foreach ($rows as $row) {
    $stateCounts[$rowState($row)]++;
}
$itemCount = array_sum(array_map(static fn (array $row): int => count($row['item_details']), $rows));
$openStatements = array_values(array_filter($statements, static fn (array $s): bool => $s['superseded_by_period'] === null));
$totalUnits = count($rows);
$issuedCount = count($statements);
$readyToIssue = is_array($preview) && $preview['ready'] && $preview['new_count'] > 0;
// Emitir solo los seleccionados: basta con la configuración general lista y al menos un inmueble «Listo».
$canIssueSelected = $canWrite && is_array($preview) && ($preview['global_blocking_reasons'] ?? ['?']) === [] && $stateCounts['pending'] > 0;

// Lista de chequeo: conceptos que irán en las notas por emitir, con cuántos inmuebles los llevan y su total.
$pendingRows = array_values(array_filter($rows, static fn (array $row): bool => !$row['already_issued']));
$conceptChecklist = [];
$addConcept = static function (string $key, string $label, string $icon, int $cents, string $hint = '') use (&$conceptChecklist): void {
    $conceptChecklist[$key] ??= ['label' => $label, 'icon' => $icon, 'units' => 0, 'cents' => 0, 'hint' => $hint];
    $conceptChecklist[$key]['units']++;
    $conceptChecklist[$key]['cents'] += $cents;
};
foreach ($pendingRows as $row) {
    if ($row['opening_balance'] !== 0) {
        $addConcept('opening_balance', 'Saldo del mes anterior', 'bi-arrow-return-right', $row['opening_balance'], 'Lo que cada inmueble trae de la nota anterior (incluye ajustes de saldo inicial).');
    }
    if ($row['fee'] > 0) {
        $addConcept('administration', 'Cuota de administración', 'bi-building', $row['fee'], 'Base mensual × coeficiente de cada inmueble.');
    }
    $unitCategories = [];
    foreach ($row['item_details'] as $item) {
        if ($item['category'] !== 'opening_balance') {
            $unitCategories[$item['category']] = ($unitCategories[$item['category']] ?? 0) + BillingService::moneyToCents($item['amount']);
        }
    }
    foreach ($unitCategories as $category => $cents) {
        $addConcept('item_' . $category, $categories[$category][0] ?? $category, 'bi-plus-square', $cents, 'Concepto adicional registrado en Preparar.');
    }
    if ($row['early_discount'] > 0) {
        $addConcept('early_discount', 'Descuento por pronto pago', 'bi-percent', -$row['early_discount'], 'Se descuenta si se paga antes del día ' . (int) ($preview['discount_day'] ?? 0) . '.');
    }
    if ($row['credit'] > 0) {
        $addConcept('credit', 'Saldo a favor', 'bi-piggy-bank', -$row['credit'], 'Inmuebles cuyo total queda a favor del residente.');
    }
}
$missingConcepts = array_values(array_filter(
    array_map(static fn (array $c): string => $c[0], array_diff_key($categories, ['opening_balance' => true])),
    static fn (string $label): bool => !in_array($label, array_column($conceptChecklist, 'label'), true)
));
// Firma de la lista: si cambia un valor o un concepto, lo ya marcado como revisado se reinicia.
$checklistSignature = hash('sha256', $period . json_encode(array_map(static fn (array $c): array => [$c['units'], $c['cents']], $conceptChecklist)));
$previewUnits = array_values(array_filter($pendingRows, static fn (array $row): bool => !$row['prior_period_invalid'] && !$row['coefficient_missing'] && !$row['invalid_recipient']));

// Avance del período: preparar → emitir → enviar → recaudar.
$steps = [
    ['Preparar', $itemCount . ' concepto(s) adicional(es)', $issuedCount > 0 || $itemCount > 0 ? 'done' : 'current'],
    ['Emitir', $issuedCount . ' de ' . max($totalUnits, $issuedCount) . ' notas', $issuedCount === 0 ? ($readyToIssue ? 'current' : 'todo') : ($stateCounts['pending'] + $stateCounts['problem'] === 0 ? 'done' : 'partial')],
    ['Enviar', $summary['sent'] . ' de ' . $summary['recipients'] . ' correos', $summary['recipients'] === 0 ? 'todo' : ($summary['failed'] + $summary['pending_mail'] === 0 ? 'done' : 'partial')],
    ['Recaudar', $short($summary['collected']) . ' de ' . $short($summary['billed']), $summary['billed'] === 0 ? 'todo' : ($summary['outstanding'] === 0 ? 'done' : 'partial')],
];
$stepStyle = ['done' => ['bi-check-lg', 'text-bg-success'], 'partial' => ['bi-hourglass-split', 'text-bg-warning'], 'current' => [null, 'text-bg-primary'], 'todo' => [null, 'text-bg-secondary']];

// Cada bloqueo lleva a la pantalla donde se corrige.
$fixLinks = [
    'Parametrización' => '/configuracion/parametrizacion',
    'Correo' => '/configuracion/correo',
    'Financiera' => '/configuracion/financiera',
    'Conformación' => '/configuracion/conformacion',
    'usuarios activos' => '/inmuebles/asignaciones',
];
$defaultTab = $readyToIssue || $issuedCount === 0 ? 'preparar' : 'notas';
$sender = is_array($sender ?? null) ? $sender : ['address' => '', 'personal' => false, 'pending' => false];
$senderLabel = $sender['address'] !== '' ? $sender['address'] : 'la cuenta general (sin configurar)';
$senderHtml = '<strong>' . $escape($senderLabel) . '</strong> '
    . ($sender['personal'] ? '<span class="badge text-bg-success"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> tu correo personal</span>' : '<span class="badge text-bg-secondary">cuenta general</span>');
?>
<section class="content" id="billing-page" data-default-tab="<?= $escape($defaultTab) ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Cartera / Cobros</h1>
            <p class="text-secondary mb-0">Prepara, emite y cobra las notas de cada mes. La cuota de cada inmueble es la base mensual × su coeficiente.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
        <form method="get" action="<?= $escape($basePath) ?>/cartera/buscar" role="search" class="d-flex">
            <label class="visually-hidden" for="global-billing-search">Buscar en toda la cartera</label>
            <div class="input-group">
                <span class="input-group-text bg-transparent"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input id="global-billing-search" name="q" type="search" class="form-control" style="width: 15rem" maxlength="100" placeholder="Buscar en todos los meses…" title="Inmueble, residente, correo, NC-…, referencia o valor">
            </div>
        </form>
        <form method="get" action="<?= $escape($basePath) ?>/cartera/cobros" class="d-flex align-items-center gap-1" aria-label="Elegir período">
            <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($previousPeriod) ?>" title="Mes anterior" aria-label="Mes anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            <label class="visually-hidden" for="billing-period">Período</label>
            <input id="billing-period" name="periodo" type="month" class="form-control fw-semibold text-center" style="width: 11rem" required
                   value="<?= $escape($period) ?>" max="<?= $escape($currentPeriod) ?>" onchange="this.form.submit()">
            <?php if ($nextPeriod !== null): ?>
                <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($nextPeriod) ?>" title="Mes siguiente" aria-label="Mes siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            <?php else: ?>
                <span class="btn btn-outline-secondary disabled" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
            <?php endif; ?>
            <?php if ($period !== $currentPeriod): ?>
                <a class="btn btn-link btn-sm" href="<?= $escape($basePath) ?>/cartera/cobros">Mes actual</a>
            <?php endif; ?>
        </form>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> d-flex gap-2" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?> mt-1" aria-hidden="true"></i><div><?= $escape($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <nav class="card border-0 shadow-sm mb-3" aria-label="Avance del período <?= $escape($periodLabel) ?>">
        <ol class="list-unstyled row g-0 mb-0">
            <?php foreach ($steps as $index => [$label, $detail, $state]): ?>
                <?php [$icon, $badge] = $stepStyle[$state]; ?>
                <li class="col-6 col-lg-3 d-flex align-items-center gap-2 p-3<?= $index > 0 ? ' border-start' : '' ?>">
                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0 <?= $badge ?>" style="width: 2rem; height: 2rem" aria-hidden="true">
                        <?php if ($icon !== null): ?><i class="bi <?= $icon ?>"></i><?php else: ?><?= $index + 1 ?><?php endif; ?>
                    </span>
                    <span style="min-width: 0">
                        <span class="d-block fw-semibold small"><?= $index + 1 ?>. <?= $escape($label) ?></span>
                        <span class="d-block small text-secondary text-truncate"><?= $escape($detail) ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div class="row g-3 mb-3">
        <?php
        $kpis = [
            ['Por emitir', $short($summary['to_issue']), $stateCounts['pending'] . ' nota(s) listas' . ($stateCounts['problem'] > 0 ? ' · ' . $stateCounts['problem'] . ' con problemas' : ''), 'bi-file-earmark-plus', 'text-primary'],
            ['Facturado', $short($summary['billed']), $issuedCount . ' nota(s) emitidas', 'bi-receipt', 'text-secondary'],
            ['Recaudado', $short($summary['collected']), $summary['billed'] > 0 ? round($summary['collected'] * 100 / $summary['billed']) . ' % de lo facturado' : 'Sin notas emitidas', 'bi-cash-stack', 'text-success'],
            ['Por cobrar', $short($summary['outstanding']), 'Saldo de notas vigentes', 'bi-hourglass-split', $summary['outstanding'] > 0 ? 'text-danger' : 'text-success'],
        ];
        ?>
        <?php foreach ($kpis as [$label, $value, $hint, $icon, $tone]): ?>
            <div class="col-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body">
                    <div class="d-flex justify-content-between"><span class="small text-secondary"><?= $escape($label) ?> · <?= $escape($periodLabel) ?></span><i class="bi <?= $icon ?> <?= $tone ?>" aria-hidden="true"></i></div>
                    <div class="h4 mb-0 mt-1"><?= $escape($value) ?></div>
                    <div class="small text-secondary"><?= $escape($hint) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (is_array($preview) && $preview['blocking_reasons'] !== [] && $stateCounts['pending'] + $stateCounts['problem'] > 0): ?>
        <div class="card border-warning mb-3">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="bi bi-list-check text-warning me-1" aria-hidden="true"></i>Antes de emitir, resuelve:</h2>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($preview['blocking_reasons'] as $reason): ?>
                        <?php
                        $link = null;
                        foreach ($fixLinks as $keyword => $path) {
                            if (str_contains($reason, $keyword)) { $link = $path; break; }
                        }
                        ?>
                        <li class="d-flex flex-wrap gap-2 align-items-baseline py-1">
                            <i class="bi bi-circle text-warning small" aria-hidden="true"></i>
                            <span><?= $escape($reason) ?></span>
                            <?php if ($link !== null): ?><a class="small" href="<?= $escape($basePath . $link) ?>">Resolver <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    <?php foreach (($preview['warnings'] ?? []) as $warning): ?>
        <div class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= $escape($warning) ?></div>
    <?php endforeach; ?>

    <?php if ($canWrite): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 small mb-2">
            <i class="bi bi-envelope-paper text-secondary" aria-hidden="true"></i>
            <span>Tus envíos salen desde <?= $senderHtml ?></span>
            <a class="ms-1" href="<?= $escape($basePath) ?>/configuracion/mi-correo"><?= $sender['personal'] ? 'Gestionar' : 'Usar mi correo certificado' ?></a>
            <?php if ($sender['pending']): ?>
                <span class="text-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Tu correo personal está pendiente de prueba o en pausa.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link" id="tab-preparar" data-bs-toggle="tab" data-bs-target="#pane-preparar" type="button" role="tab" aria-controls="pane-preparar">Preparar y emitir <span class="badge rounded-pill text-bg-primary ms-1"><?= $stateCounts['pending'] + $stateCounts['problem'] ?></span></button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="tab-notas" data-bs-toggle="tab" data-bs-target="#pane-notas" type="button" role="tab" aria-controls="pane-notas">Notas emitidas <span class="badge rounded-pill text-bg-secondary ms-1"><?= $issuedCount ?></span><?php if ($summary['failed'] > 0): ?> <i class="bi bi-exclamation-triangle-fill text-danger" title="Hay correos fallidos"></i><?php endif; ?></button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="tab-pagos" data-bs-toggle="tab" data-bs-target="#pane-pagos" type="button" role="tab" aria-controls="pane-pagos">Pagos <span class="badge rounded-pill text-bg-secondary ms-1"><?= count(array_filter($payments, static fn (array $p): bool => $p['voided_at'] === null)) ?></span></button></li>
    </ul>

    <div class="tab-content card border-0 border-top-0 shadow-sm rounded-top-0 mb-3">
        <!-- 1. Preparar y emitir -->
        <div class="tab-pane fade" id="pane-preparar" role="tabpanel" aria-labelledby="tab-preparar" tabindex="0">
            <?php if (!is_array($preview)): ?>
                <p class="text-secondary p-4 mb-0">No se pudo calcular la vista previa de este período.</p>
            <?php else: ?>
                <?php if ($conceptChecklist !== []): ?>
                    <div class="p-3 border-bottom" id="concept-checklist" data-signature="<?= $escape($checklistSignature) ?>" data-period="<?= $escape($period) ?>">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <h2 class="h6 mb-0"><i class="bi bi-list-check text-primary me-1" aria-hidden="true"></i>Conceptos incluidos en las notas de <?= $escape($periodLabel) ?></h2>
                            <span class="badge rounded-pill text-bg-secondary" id="concept-progress" aria-live="polite"></span>
                            <button class="btn btn-link btn-sm ms-auto p-0" type="button" id="concept-check-all">Marcar todos como revisados</button>
                        </div>
                        <p class="small text-secondary mb-2">Marca cada concepto cuando hayas revisado sus valores. Para emitir deben estar todos revisados; si un valor cambia, su revisión se reinicia.</p>
                        <ul class="list-group">
                            <?php foreach ($conceptChecklist as $key => $concept): ?>
                                <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                                    <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" id="concept-<?= $escape($key) ?>" value="<?= $escape($key) ?>" data-concept-check>
                                    <label class="form-check-label flex-grow-1" for="concept-<?= $escape($key) ?>" style="min-width: 12rem">
                                        <i class="bi <?= $escape($concept['icon']) ?> text-secondary me-1" aria-hidden="true"></i><strong><?= $escape($concept['label']) ?></strong>
                                        <span class="d-block small text-secondary"><?= $escape($concept['hint']) ?></span>
                                    </label>
                                    <span class="small text-secondary text-nowrap"><?= $concept['units'] ?> de <?= count($pendingRows) ?> inmueble(s)</span>
                                    <strong class="text-nowrap text-end" style="min-width: 8rem"><?= $short($concept['cents']) ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($missingConcepts !== []): ?>
                            <p class="small text-secondary mt-2 mb-0"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No incluidos este mes: <?= $escape(implode(', ', $missingConcepts)) ?>.<?= $canWrite ? ' Agrégalos con «+ Concepto» en el inmueble que corresponda.' : '' ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2 align-items-center p-3 border-bottom">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Filtrar por estado">
                        <?php foreach (['all' => ['Todos', $totalUnits], 'pending' => ['Listos', $stateCounts['pending']], 'problem' => ['Con problemas', $stateCounts['problem']], 'issued' => ['Emitidos', $stateCounts['issued']]] as $value => [$label, $count]): ?>
                            <input class="btn-check" type="radio" name="row-state" id="state-<?= $value ?>" value="<?= $value ?>" <?= $value === 'all' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="state-<?= $value ?>"><?= $escape($label) ?> <span class="opacity-75"><?= $count ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <input id="billing-filter" class="form-control form-control-sm ms-auto" style="max-width: 18rem" type="search" placeholder="Buscar inmueble o residente" aria-label="Buscar inmueble o residente">
                    <div id="billing-grid-tools" class="d-flex flex-wrap gap-2"></div>
                </div>
                <div id="billing-grid-bulk" class="px-3 py-2 bg-primary-subtle border-bottom" hidden></div>
                <div class="table-responsive" style="max-height: 60vh">
                    <table class="table table-hover align-middle mb-0" id="billing-preview-table">
                        <thead class="table-light sticky-top">
                        <tr>
                            <th scope="col" data-column="name" data-label="Inmueble" data-hideable="false" data-sortable>Inmueble</th>
                            <th scope="col" data-column="recipients" data-label="Residente(s)" data-sortable>Residente(s)</th>
                            <th scope="col" class="text-end" data-column="opening" data-label="Saldo anterior" data-sortable>Saldo anterior</th>
                            <th scope="col" class="text-end" data-column="fee" data-label="Cuota" data-sortable>Cuota</th>
                            <th scope="col" class="text-end" data-column="other" data-label="Otros" data-sortable>Otros</th>
                            <th scope="col" class="text-end" data-column="total" data-label="Total" data-sortable>Total</th>
                            <th scope="col" data-column="state" data-label="Estado" data-sortable>Estado</th>
                            <th scope="col" data-export="false"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $state = $rowState($row);
                            $recipientNames = implode(', ', array_column($row['recipients'], 'username'));
                            $searchText = mb_strtolower($row['name'] . ' ' . $recipientNames, 'UTF-8');
                            $otherCents = array_sum($row['items']) + $row['opening_adjustment'];
                            ?>
                            <tr class="billing-row" data-unit-id="<?= $escape($row['id']) ?>" data-unit-name="<?= $escape($row['name']) ?>" data-issued="<?= $row['already_issued'] ? '1' : '0' ?>"
                                data-search="<?= $escape($searchText) ?>" data-state="<?= $state ?>"
                                data-sort-name="<?= $escape($row['name']) ?>" data-sort-opening="<?= $sortCents($row['opening_balance']) ?>" data-sort-fee="<?= $sortCents($row['fee']) ?>"
                                data-sort-other="<?= $sortCents($otherCents) ?>" data-sort-total="<?= $sortCents($row['total']) ?>"
                                data-due="<?= (int) $row['due'] ?>" data-items="<?= count(array_filter($row['item_details'], static fn (array $item): bool => $item['category'] !== 'opening_balance')) ?>">
                                <td>
                                    <span class="fw-semibold"><?= $escape($row['name']) ?></span>
                                    <span class="d-block small text-secondary"><?= $escape($row['category']) ?> · coef. <?= $row['coefficient'] === null ? '—' : $escape(number_format((float) $row['coefficient'], 4, ',', '.')) . ' %' ?></span>
                                </td>
                                <td class="small"><?= $recipientNames !== '' ? $escape($recipientNames) : '<span class="text-danger">Sin residente</span>' ?></td>
                                <td class="text-end small"><?= $short($row['opening_balance']) ?></td>
                                <td class="text-end small"><?= $short($row['fee']) ?></td>
                                <td class="text-end small">
                                    <?php if ($row['item_details'] !== []): ?>
                                        <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#items-<?= $escape($row['id']) ?>" aria-expanded="false"><?= $short($otherCents) ?> <i class="bi bi-chevron-down small" aria-hidden="true"></i></button>
                                    <?php else: ?>
                                        <?= $short(0) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <span class="fw-semibold"><?= $short($row['due']) ?></span>
                                    <?php if ($row['credit'] > 0): ?><span class="d-block small text-success">A favor <?= $short($row['credit']) ?></span><?php endif; ?>
                                    <?php if ($row['early_discount'] > 0): ?><span class="d-block small text-secondary">Pronto pago <?= $short($row['early_payable']) ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['already_issued']): ?>
                                        <a class="badge text-bg-success text-decoration-none" href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= (int) $row['statement_id'] ?>" target="_blank" rel="noopener">Emitida <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                                    <?php elseif ($row['prior_period_invalid']): ?>
                                        <span class="badge text-bg-warning">Falta emitir el mes anterior</span>
                                    <?php elseif ($row['coefficient_missing']): ?>
                                        <a class="badge text-bg-warning text-decoration-none" href="<?= $escape($basePath) ?>/configuracion/conformacion">Falta coeficiente</a>
                                    <?php elseif ($row['invalid_recipient']): ?>
                                        <a class="badge text-bg-danger text-decoration-none" href="<?= $escape($basePath) ?>/inmuebles/asignaciones">Revisar residente / correo</a>
                                    <?php else: ?>
                                        <span class="badge text-bg-primary">Lista</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($canWrite && !$row['already_issued']): ?>
                                        <button class="btn btn-outline-primary btn-sm text-nowrap" type="button" data-add-item data-unit-id="<?= $escape($row['id']) ?>" data-unit-name="<?= $escape($row['name']) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Concepto</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($row['item_details'] !== []): ?>
                                <tr class="collapse" id="items-<?= $escape($row['id']) ?>" data-detail-for="<?= $escape($row['id']) ?>">
                                    <td colspan="9" class="bg-body-tertiary">
                                        <ul class="list-unstyled mb-0 small">
                                            <?php foreach ($row['item_details'] as $item): ?>
                                                <li class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-1">
                                                    <span><span class="badge text-bg-light border me-1"><?= $escape($categories[$item['category']][0] ?? $item['category']) ?></span><?= $escape($item['description']) ?></span>
                                                    <span class="d-flex align-items-center gap-3">
                                                        <strong><?= $short(BillingService::moneyToCents($item['amount'])) ?></strong>
                                                        <?php if ($canWrite && !$row['already_issued']): ?>
                                                            <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/concepto/eliminar" data-confirm="¿Eliminar «<?= $escape($item['description']) ?>»?">
                                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                                <input type="hidden" name="period" value="<?= $escape($period) ?>">
                                                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                                <button class="btn btn-link btn-sm text-danger p-0" type="submit" title="Eliminar concepto"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Eliminar</span></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="8" class="text-center text-secondary py-5">No hay inmuebles con residentes activos asignados. <a href="<?= $escape($basePath) ?>/inmuebles/asignaciones">Asignar inmuebles</a></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($rows !== []): ?><div id="billing-grid-pager" class="px-3 py-2 border-top"></div><?php endif; ?>
                <?php if ($canWrite): ?>
                    <div class="d-flex flex-wrap align-items-center gap-3 p-3 border-top bg-body-tertiary">
                        <span class="small text-secondary">
                            <?php if ($readyToIssue): ?>
                                Se emitirán <strong><?= (int) $preview['new_count'] ?></strong> nota(s) por <strong><?= $short($summary['to_issue']) ?></strong> y se enviarán por correo. Las notas emitidas ya no se pueden editar.
                            <?php elseif ($preview['new_count'] === 0 && $stateCounts['issued'] > 0): ?>
                                Todas las notas de <?= $escape($periodLabel) ?> están emitidas.
                            <?php elseif ($canIssueSelected): ?>
                                Hay inmuebles con pendientes, pero puedes emitir los que están <strong>Listos</strong>: selecciónalos y usa «Emitir y enviar seleccionados».
                            <?php else: ?>
                                Resuelve los pendientes de arriba para poder emitir.
                            <?php endif; ?>
                        </span>
                        <?php if ($stateCounts['pending'] + $stateCounts['problem'] > 0): ?>
                            <div class="d-flex flex-wrap gap-2 ms-auto">
                                <?php if ($canIssueSelected): ?>
                                    <button class="btn btn-outline-primary" type="button" id="issue-selected-button" disabled title="Selecciona inmuebles en la tabla">
                                        <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Emitir y enviar seleccionados <span class="badge text-bg-primary ms-1" id="issue-selected-count">0</span>
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#issue-modal" <?= $readyToIssue ? '' : 'disabled' ?>>
                                    <i class="bi bi-send me-1" aria-hidden="true"></i>Emitir y enviar <?= (int) $preview['new_count'] ?> nota(s)
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- 2. Notas emitidas -->
        <div class="tab-pane fade" id="pane-notas" role="tabpanel" aria-labelledby="tab-notas" tabindex="0">
            <?php if ($statements === []): ?>
                <div class="text-center text-secondary py-5">
                    <i class="bi bi-receipt fs-1 d-block mb-2" aria-hidden="true"></i>Aún no se han emitido notas para <?= $escape($periodLabel) ?>.
                </div>
            <?php else: ?>
                <?php if ($canWrite && $summary['failed'] + $summary['pending_mail'] > 0): ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom bg-warning-subtle">
                        <i class="bi bi-envelope-exclamation text-warning" aria-hidden="true"></i>
                        <span class="small"><?= $summary['failed'] ?> correo(s) fallaron y <?= $summary['pending_mail'] ?> siguen pendientes. Se reenviarán desde <?= $senderHtml ?></span>
                        <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/reintentar" class="ms-auto" data-confirm="Se enviarán de nuevo los correos fallidos o pendientes de <?= $escape($periodLabel) ?>. ¿Continuar?">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="period" value="<?= $escape($period) ?>">
                            <button class="btn btn-warning btn-sm" type="submit" data-loading-label="Enviando…"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Reintentar envío</button>
                        </form>
                    </div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2 align-items-center p-3 border-bottom">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Filtrar notas">
                        <input class="btn-check" type="radio" name="note-state" id="note-all" value="all" checked><label class="btn btn-outline-secondary" for="note-all">Todas</label>
                        <input class="btn-check" type="radio" name="note-state" id="note-debt" value="debt"><label class="btn btn-outline-secondary" for="note-debt">Con saldo</label>
                        <input class="btn-check" type="radio" name="note-state" id="note-paid" value="paid"><label class="btn btn-outline-secondary" for="note-paid">Pagadas</label>
                        <input class="btn-check" type="radio" name="note-state" id="note-mail" value="mail"><label class="btn btn-outline-secondary" for="note-mail">Correo con problema</label>
                    </div>
                    <input class="form-control form-control-sm ms-auto" style="max-width: 18rem" type="search" placeholder="Buscar inmueble o NC-…" aria-label="Buscar nota" data-table-filter="notes-table">
                    <div id="notes-grid-tools" class="d-flex flex-wrap gap-2"></div>
                </div>
                <div class="table-responsive" style="max-height: 60vh">
                    <table class="table table-hover align-middle mb-0" id="notes-table">
                        <thead class="table-light sticky-top"><tr>
                            <th scope="col" data-column="number" data-label="Nota" data-sortable>Nota</th>
                            <th scope="col" data-column="name" data-label="Inmueble" data-hideable="false" data-sortable>Inmueble</th>
                            <th scope="col" class="text-end" data-column="total" data-label="Total" data-sortable>Total</th>
                            <th scope="col" class="text-end" data-column="paid" data-label="Pagado" data-sortable>Pagado</th>
                            <th scope="col" class="text-end" data-column="balance" data-label="Saldo" data-sortable>Saldo</th>
                            <th scope="col" data-column="mail" data-label="Correo" data-sortable>Correo</th>
                            <th scope="col" class="text-end" data-export="false">Acciones</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($statements as $statement): ?>
                            <?php
                            $sent = (int) $statement['sent_count'];
                            $failed = (int) $statement['failed_count'];
                            $recipients = (int) $statement['recipient_count'];
                            $balance = (int) $statement['balance_cents'];
                            $isOpen = $statement['superseded_by_period'] === null;
                            $noteState = ($balance > 0 ? 'debt' : 'paid') . ($failed > 0 || $sent < $recipients ? ' mail' : '');
                            ?>
                            <tr class="note-row" data-search="<?= $escape(mb_strtolower($statement['number'] . ' ' . $statement['unit_name'], 'UTF-8')) ?>" data-state="<?= $noteState ?>"
                                data-sort-name="<?= $escape($statement['unit_name']) ?>" data-sort-total="<?= $sortCents(max(0, (int) $statement['total_cents'])) ?>"
                                data-sort-paid="<?= $sortCents((int) $statement['paid_cents']) ?>" data-sort-balance="<?= $sortCents($balance) ?>">
                                <td class="small font-monospace"><?= $escape($statement['number']) ?></td>
                                <td>
                                    <?= $escape($statement['unit_name']) ?>
                                    <?php if (!$isOpen): ?><span class="d-block small text-secondary"><i class="bi bi-arrow-return-right" aria-hidden="true"></i> Saldo trasladado a <?= $escape($statement['superseded_by_period']) ?></span><?php endif; ?>
                                </td>
                                <td class="text-end"><?= $short(max(0, (int) $statement['total_cents'])) ?></td>
                                <td class="text-end"><?= $short((int) $statement['paid_cents']) ?><?= $statement['discount_cents'] > 0 ? '<span class="d-block small text-success">−' . $short((int) $statement['discount_cents']) . ' pronto pago</span>' : '' ?></td>
                                <td class="text-end fw-semibold">
                                    <?php if ($balance <= 0): ?>
                                        <span class="badge text-bg-success"><?= $balance < 0 ? 'A favor ' . $short(-$balance) : 'Pagada' ?></span>
                                    <?php else: ?>
                                        <?= $short($balance) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $failed > 0 ? 'danger' : ($sent === $recipients ? 'success' : 'warning') ?>" title="<?= $sent ?> de <?= $recipients ?> destinatarios">
                                        <i class="bi <?= $failed > 0 ? 'bi-x-circle' : ($sent === $recipients ? 'bi-check2-all' : 'bi-hourglass') ?>" aria-hidden="true"></i>
                                        <?= $failed > 0 ? $failed . ' fallido(s)' : ($sent === $recipients ? 'Enviado' : 'Pendiente') ?>
                                    </span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= (int) $statement['id'] ?>" target="_blank" rel="noopener" title="Ver o imprimir la nota"><i class="bi bi-printer" aria-hidden="true"></i><span class="visually-hidden">Ver nota</span></a>
                                    <?php if ($isOpen && $balance > 0): ?>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= $escape($basePath) ?>/cartera/cobros/enlaces?id=<?= (int) $statement['id'] ?>" title="Enlace de pago temporal y QR"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>Enlace / QR</a>
                                    <?php endif; ?>
                                    <?php if ($canWrite && $isOpen && $balance > 0): ?>
                                        <button class="btn btn-outline-success btn-sm" type="button" data-pay-statement="<?= (int) $statement['id'] ?>"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Registrar pago</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="notes-grid-pager" class="px-3 py-2 border-top"></div>
            <?php endif; ?>
        </div>

        <!-- 3. Pagos -->
        <div class="tab-pane fade" id="pane-pagos" role="tabpanel" aria-labelledby="tab-pagos" tabindex="0">
            <?php if ($canWrite && $openStatements !== []): ?>
                <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/pago" class="p-3 border-bottom" id="payment-form" data-money-form>
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="period" value="<?= $escape($period) ?>">
                    <h2 class="h6 mb-3">Registrar un pago recibido</h2>
                    <div class="row g-3">
                        <div class="col-lg-5">
                            <label for="payment-statement" class="form-label">Inmueble / nota</label>
                            <select id="payment-statement" name="statement_id" class="form-select" required>
                                <option value="">Selecciona el inmueble…</option>
                                <?php foreach ($openStatements as $statement): ?>
                                    <option value="<?= (int) $statement['id'] ?>" data-balance="<?= (int) $statement['balance_cents'] ?>">
                                        <?= $escape($statement['unit_name']) ?> · <?= $escape($statement['number']) ?> · <?= (int) $statement['balance_cents'] > 0 ? 'debe ' . $escape($short((int) $statement['balance_cents'])) : 'al día' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="payment-balance" class="form-text"></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="payment-amount-display" class="form-label">Valor recibido</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input id="payment-amount-display" class="form-control text-end" inputmode="decimal" autocomplete="off" required placeholder="0" data-money-for="payment-amount">
                            </div>
                            <input type="hidden" name="amount" id="payment-amount">
                            <button class="btn btn-link btn-sm p-0 mt-1" type="button" id="pay-full" hidden>Usar saldo completo</button>
                            <div id="payment-amount-help" class="form-text"></div>
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label for="payment-date" class="form-label">Fecha</label>
                            <input id="payment-date" name="received_on" type="date" class="form-control" required value="<?= $escape((new DateTimeImmutable('today'))->format('Y-m-d')) ?>" max="<?= $escape((new DateTimeImmutable('today'))->format('Y-m-d')) ?>">
                        </div>
                        <div class="col-lg-2">
                            <label for="payment-reference" class="form-label">Referencia</label>
                            <input id="payment-reference" name="reference" class="form-control" maxlength="80" placeholder="Opcional">
                        </div>
                        <div class="col-lg-10">
                            <label for="payment-notes" class="form-label visually-hidden">Notas</label>
                            <input id="payment-notes" name="notes" class="form-control form-control-sm" maxlength="255" placeholder="Notas (opcional): consignación, efectivo en oficina…">
                        </div>
                        <div class="col-lg-2 d-grid">
                            <button class="btn btn-success" type="submit" data-loading-label="Registrando…"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Registrar</button>
                        </div>
                    </div>
                </form>
            <?php elseif ($canWrite && $statements !== []): ?>
                <div class="alert alert-light border m-3 small">Las notas de <?= $escape($periodLabel) ?> ya trasladaron su saldo a un período posterior. Registra los pagos en la nota más reciente de cada inmueble.</div>
            <?php endif; ?>

            <?php if ($payments === []): ?>
                <div class="text-center text-secondary py-5"><i class="bi bi-cash-stack fs-1 d-block mb-2" aria-hidden="true"></i>No hay pagos registrados en las notas de <?= $escape($periodLabel) ?>.</div>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2 align-items-center p-3 border-bottom">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Filtrar pagos">
                        <input class="btn-check" type="radio" name="payment-state" id="payment-all" value="all" checked><label class="btn btn-outline-secondary" for="payment-all">Todos</label>
                        <input class="btn-check" type="radio" name="payment-state" id="payment-valid" value="valid"><label class="btn btn-outline-secondary" for="payment-valid">Vigentes</label>
                        <input class="btn-check" type="radio" name="payment-state" id="payment-voided" value="voided"><label class="btn btn-outline-secondary" for="payment-voided">Anulados</label>
                    </div>
                    <span class="small text-secondary">Pagos de las notas de <?= $escape($periodLabel) ?></span>
                    <input class="form-control form-control-sm ms-auto" style="max-width: 18rem" type="search" placeholder="Buscar inmueble, referencia o valor" aria-label="Buscar pago" data-table-filter="payments-table">
                    <div id="payments-grid-tools" class="d-flex flex-wrap gap-2"></div>
                </div>
                <div class="table-responsive" style="max-height: 60vh">
                    <table class="table table-sm table-hover align-middle mb-0" id="payments-table">
                        <thead class="table-light sticky-top"><tr>
                            <th scope="col" data-column="date" data-label="Fecha" data-sortable>Fecha</th>
                            <th scope="col" data-column="name" data-label="Inmueble" data-hideable="false" data-sortable>Inmueble</th>
                            <th scope="col" class="text-end" data-column="amount" data-label="Valor" data-sortable>Valor</th>
                            <th scope="col" data-column="reference" data-label="Referencia / notas">Referencia / notas</th>
                            <th scope="col" data-column="by" data-label="Registró" data-sortable>Registró</th>
                            <th scope="col" data-column="status" data-label="Estado" data-sortable>Estado</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <?php $isVoided = $payment['voided_at'] !== null; $amount = BillingService::moneyToCents($payment['amount']); ?>
                            <tr class="payment-row<?= $isVoided ? ' text-secondary' : '' ?>" data-state="<?= $isVoided ? 'voided' : 'valid' ?>"
                                data-search="<?= $escape(mb_strtolower($payment['unit_name'] . ' ' . $payment['reference'] . ' ' . $payment['notes'] . ' ' . $short($amount) . ' ' . $payment['received_on'], 'UTF-8')) ?>"
                                data-sort-date="<?= $escape($payment['received_on'] . ' ' . str_pad((string) (int) $payment['id'], 10, '0', STR_PAD_LEFT)) ?>"
                                data-sort-name="<?= $escape($payment['unit_name']) ?>" data-sort-amount="<?= $sortCents($amount) ?>">
                                <td class="text-nowrap"><?= $escape($payment['received_on']) ?>
                                    <a class="d-block small" target="_blank" rel="noopener" href="<?= $escape($basePath) ?>/cartera/cobros/comprobante?id=<?= (int) $payment['id'] ?>"><?= $escape(BillingService::receiptNumber((int) $payment['id'])) ?></a></td>
                                <td><?= $escape($payment['unit_name']) ?></td>
                                <td class="text-end"><?= $isVoided ? '<s>' . $short($amount) . '</s>' : $short($amount) ?></td>
                                <td class="small"><?= $escape($payment['reference']) ?><?= $payment['notes'] !== '' ? '<span class="d-block text-secondary">' . $escape($payment['notes']) . '</span>' : '' ?></td>
                                <td class="small"><?= $escape($payment['created_by_name'] ?? 'Pago en línea') ?></td>
                                <td>
                                    <?php if ($isVoided): ?>
                                        <span class="badge text-bg-danger">Anulado</span>
                                        <span class="d-block small"><?= $escape($payment['void_reason']) ?> · <?= $escape($payment['voided_by_name'] ?? '') ?></span>
                                    <?php elseif ($canVoid): ?>
                                        <span class="badge text-bg-success">Vigente</span>
                                        <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/comprobante/reenviar" class="d-inline">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="period" value="<?= $escape($period) ?>">
                                            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                            <button class="btn btn-link btn-sm p-0 ms-1" type="submit" title="Reenviar el comprobante por correo a las cuentas del inmueble">Reenviar</button>
                                        </form>
                                        <button class="btn btn-link btn-sm text-danger p-0 ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#void-<?= (int) $payment['id'] ?>">Anular…</button>
                                        <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/pago/anular" class="collapse mt-1" id="void-<?= (int) $payment['id'] ?>"
                                              data-confirm="¿Anular el pago de <?= $escape($short($amount)) ?> de <?= $escape($payment['unit_name']) ?>? El saldo de la nota aumentará en ese valor.">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="period" value="<?= $escape($period) ?>">
                                            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                            <div class="input-group input-group-sm">
                                                <input name="reason" class="form-control" minlength="5" maxlength="255" required placeholder="Motivo de la anulación" aria-label="Motivo de la anulación">
                                                <button class="btn btn-outline-danger" type="submit" data-loading-label="Anulando…">Anular</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">Vigente</span>
                                        <?php if ($canWrite): ?>
                                            <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/comprobante/reenviar" class="d-inline">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="period" value="<?= $escape($period) ?>">
                                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                                <button class="btn btn-link btn-sm p-0 ms-1" type="submit" title="Reenviar el comprobante por correo a las cuentas del inmueble">Reenviar</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="payments-grid-pager" class="px-3 py-2 border-top"></div>
            <?php endif; ?>
        </div>
    </div>

    <p class="small text-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>La emisión es única por inmueble y mes, y el consecutivo no se reutiliza. Los valores y datos bancarios quedan como copia del momento de emisión. La nota es un documento de cobro imprimible: no reemplaza la revisión contable ni es una factura electrónica.</p>
</section>

<?php if ($canWrite && is_array($preview)): ?>
    <!-- Agregar concepto -->
    <div class="modal fade" id="item-modal" tabindex="-1" aria-labelledby="item-modal-title" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?= $escape($basePath) ?>/cartera/cobros/concepto" data-money-form id="item-form"
                  data-single-action="<?= $escape($basePath) ?>/cartera/cobros/concepto" data-bulk-action="<?= $escape($basePath) ?>/cartera/cobros/concepto/lote">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="item-modal-title">Agregar concepto</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="period" value="<?= $escape($period) ?>">
                    <input type="hidden" name="unit_id" id="item-unit">
                    <div id="item-bulk-units"></div>
                    <p class="mb-3"><span class="text-secondary" id="item-unit-label">Inmueble:</span> <strong id="item-unit-name"></strong> · <?= $escape($periodLabel) ?></p>
                    <div class="mb-3">
                        <label for="item-category" class="form-label">Tipo de concepto</label>
                        <select id="item-category" name="category" class="form-select" required>
                            <?php foreach ($categories as $key => [$label, $help]): ?>
                                <option value="<?= $escape($key) ?>" data-help="<?= $escape($help) ?>" data-label="<?= $escape($label) ?>"><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="item-category-help" class="form-text"></div>
                    </div>
                    <div class="mb-3">
                        <label for="item-description" class="form-label">Descripción <span class="text-secondary small">(aparece en la nota)</span></label>
                        <input id="item-description" name="description" class="form-control" maxlength="160" required>
                    </div>
                    <div class="mb-1">
                        <label for="item-amount-display" class="form-label">Valor</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input id="item-amount-display" class="form-control text-end" inputmode="decimal" autocomplete="off" required placeholder="0" data-money-for="item-amount">
                            <span class="input-group-text">COP</span>
                        </div>
                        <input type="hidden" name="amount" id="item-amount">
                        <div class="form-text">Escribe el valor con puntos de miles, por ejemplo 45.000 o 45.000,50.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit" data-loading-label="Guardando…">Agregar concepto</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($readyToIssue || $canIssueSelected): ?>
        <!-- Confirmar emisión (todo el período o solo los inmuebles seleccionados) -->
        <div class="modal fade" id="issue-modal" tabindex="-1" aria-labelledby="issue-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <form class="modal-content" method="post" action="<?= $escape($basePath) ?>/cartera/cobros/emitir" id="issue-form">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="issue-modal-title" data-all="Emitir notas de <?= $escape($periodLabel) ?>">Emitir notas de <?= $escape($periodLabel) ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="period" value="<?= $escape($period) ?>">
                        <div id="issue-selected-units"></div>
                        <div class="alert alert-primary small py-2" id="issue-selected-notice" hidden></div>
                        <div class="row g-3">
                        <div class="col-lg-5">
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between flex-wrap gap-1"><span>Se enviarán desde</span><span><?= $senderHtml ?></span></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Notas a emitir</span><strong id="issue-count" data-all="<?= (int) $preview['new_count'] ?>"><?= (int) $preview['new_count'] ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Valor total</span><strong id="issue-total" data-all="<?= $escape($short($summary['to_issue'])) ?>"><?= $short($summary['to_issue']) ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Conceptos adicionales incluidos</span><strong id="issue-items" data-all="<?= $itemCount ?>"><?= $itemCount ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Pronto pago hasta el día</span><strong><?= empty($preview['early_discount_applies']) ? 'No aplica' : (int) $preview['discount_day'] ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Vencimiento el día</span><strong><?= (int) $preview['due_day'] ?></strong></li>
                        </ul>
                        <div class="alert alert-warning small mb-3"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Las notas emitidas <strong>no se pueden editar ni eliminar</strong>, y se enviarán por correo a cada residente. Las correcciones se hacen con conceptos en el mes siguiente.</div>
                        <?php if ($conceptChecklist !== []): ?>
                            <div class="small mb-3" id="issue-checklist-status" role="status"></div>
                        <?php endif; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="issue-confirm" required>
                            <label class="form-check-label" for="issue-confirm">Revisé los valores y los conceptos de este período.</label>
                        </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <h3 class="h6 mb-0 me-auto"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Vista previa del documento</h3>
                                <label class="visually-hidden" for="draft-unit">Inmueble de la vista previa</label>
                                <select class="form-select form-select-sm w-auto" id="draft-unit">
                                    <?php foreach ($previewUnits as $unit): ?>
                                        <option value="<?= $escape($unit['id']) ?>"><?= $escape($unit['name']) ?> · <?= $escape($short($unit['due'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Recorrer inmuebles">
                                    <button class="btn btn-outline-secondary" type="button" id="draft-prev" title="Inmueble anterior" aria-label="Inmueble anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" id="draft-next" title="Inmueble siguiente" aria-label="Inmueble siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary" id="draft-open" href="#" target="_blank" rel="noopener" title="Abrir en una pestaña nueva"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden">Abrir en una pestaña nueva</span></a>
                            </div>
                            <iframe id="draft-frame" title="Vista previa de la nota de cobro" class="w-100 border rounded bg-white" style="height: 60vh" sandbox
                                    data-base="<?= $escape($basePath) ?>/cartera/cobros/vista-previa?periodo=<?= $escape(rawurlencode($period)) ?>&amp;inmueble="></iframe>
                            <p class="small text-secondary mt-1 mb-0">Es el mismo documento que recibirá cada residente por correo, con sus valores. Nada se guarda ni se envía hasta que emitas.</p>
                        </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Revisar otra vez</button>
                        <button class="btn btn-primary" type="submit" data-loading-label="Emitiendo y enviando…" disabled id="issue-submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Emitir y enviar</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script src="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/js/data-grid.js?v=1"></script>
<script>
// Bootstrap se carga al final del layout: se espera a que el documento esté listo.
document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('billing-page');
    const money = (cents) => '$ ' + (cents / 100).toLocaleString('es-CO', { minimumFractionDigits: cents % 100 ? 2 : 0, maximumFractionDigits: 2 });

    // Pestañas con dirección propia (#preparar, #notas, #pagos) para volver al mismo lugar.
    const showTab = (name) => {
        const button = document.getElementById(`tab-${name}`);
        if (button) bootstrap.Tab.getOrCreateInstance(button).show();
    };
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((button) => button.addEventListener('shown.bs.tab', () => {
        history.replaceState(null, '', `#${button.id.replace('tab-', '')}`);
    }));
    showTab(['preparar', 'notas', 'pagos'].includes(location.hash.slice(1)) ? location.hash.slice(1) : page.dataset.defaultTab);

    // Valores en pesos: se escribe «250.000» o «250.000,50» y se envía «250000.00».
    const parseMoney = (text) => {
        const clean = String(text).replace(/[^\d,]/g, '');
        if (clean === '') return null;
        const [whole, fraction = ''] = clean.split(',');
        if (whole === '' || fraction.length > 2 || clean.split(',').length > 2) return null;
        return parseInt(whole, 10) * 100 + parseInt(fraction.padEnd(2, '0') || '0', 10);
    };
    document.querySelectorAll('[data-money-for]').forEach((input) => {
        const hidden = document.getElementById(input.dataset.moneyFor);
        const sync = () => {
            const cents = parseMoney(input.value);
            hidden.value = cents === null ? '' : `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
            input.setCustomValidity(cents === null || cents < 1 ? 'Escribe un valor mayor que cero, por ejemplo 45.000.' : '');
            input.dispatchEvent(new CustomEvent('money-change', { detail: cents }));
        };
        input.addEventListener('input', sync);
        input.addEventListener('blur', () => {
            const cents = parseMoney(input.value);
            if (cents !== null) input.value = money(cents).replace('$ ', '');
        });
        input.syncMoney = sync;
    });

    // Emitir solo los inmuebles seleccionados: solo cuentan los que están «Listos» (data-state="pending").
    const readyOnly = (rows) => rows.filter((row) => row.dataset.state === 'pending');
    let pendingIssueSelection = null;
    function updateIssueSelectedButton(selectedRows) {
        const button = document.getElementById('issue-selected-button');
        if (!button) return;
        const ready = readyOnly(selectedRows).length;
        document.getElementById('issue-selected-count').textContent = ready;
        button.disabled = ready === 0;
        button.title = selectedRows.length === 0
            ? 'Selecciona inmuebles en la tabla'
            : (ready === 0 ? 'Ninguno de los seleccionados está «Listo»' : `${ready} inmueble(s) listos para emitir`);
    }
    function openIssueSelected(rows) {
        if (readyOnly(rows).length === 0) {
            window.alert('Ninguno de los inmuebles seleccionados está «Listo» para emitir (ya emitidos o con pendientes).');
            return;
        }
        pendingIssueSelection = rows;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('issue-modal')).show();
    }
    // rows = null: todo el período (botón principal). Con filas: solo esas.
    function applyIssueMode(rows) {
        const ready = rows === null ? null : readyOnly(rows);
        const set = (id, value) => { const el = document.getElementById(id); el.textContent = value ?? el.dataset.all; };
        set('issue-modal-title', ready && `Emitir ${ready.length} nota(s) seleccionada(s)`);
        set('issue-count', ready && String(ready.length));
        set('issue-total', ready && money(ready.reduce((sum, row) => sum + Number(row.dataset.due), 0)));
        set('issue-items', ready && String(ready.reduce((sum, row) => sum + Number(row.dataset.items), 0)));
        document.getElementById('issue-selected-units').replaceChildren(...(ready ?? []).map((row) =>
            Object.assign(document.createElement('input'), { type: 'hidden', name: 'unit_ids[]', value: row.dataset.unitId })));
        const notice = document.getElementById('issue-selected-notice');
        notice.hidden = ready === null;
        if (ready) {
            const skipped = rows.length - ready.length;
            notice.textContent = `Solo se emitirán y enviarán las notas de los ${ready.length} inmueble(s) seleccionados`
                + (skipped > 0 ? `; se omiten ${skipped} que no están «Listos».` : '.')
                + ' Los demás quedan pendientes para emitirlos después.';
        }
        document.getElementById('issue-submit').innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>'
            + (ready ? `Emitir y enviar ${ready.length} seleccionada(s)` : 'Emitir y enviar');
        // La vista previa muestra solo los inmuebles que se van a emitir.
        const draftUnit = document.getElementById('draft-unit');
        if (draftUnit) {
            const ids = ready ? new Set(ready.map((row) => row.dataset.unitId)) : null;
            [...draftUnit.options].forEach((option) => {
                option.hidden = ids !== null && !ids.has(option.value);
                option.disabled = option.hidden;
            });
            if (draftUnit.selectedOptions[0]?.disabled) {
                draftUnit.selectedIndex = [...draftUnit.options].findIndex((option) => !option.disabled);
            }
        }
    }

    // Tablas de datos (como en Residentes): paginación, orden, columnas visibles y exportación CSV.
    // Búsqueda sin tildes ni mayúsculas; los montos se ordenan por su valor (data-sort-*), no por el texto.
    const normalize = (text) => String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const searchFor = (tableId) => normalize(document.querySelector(`[data-table-filter="${tableId}"]`)?.value ?? '');
    const sortFromData = (row, key) => {
        const value = row.dataset[`sort${key.charAt(0).toUpperCase()}${key.slice(1)}`];
        return value === undefined ? undefined : value;
    };
    const checked = (name) => document.querySelector(`[name="${name}"]:checked`)?.value ?? 'all';
    const grids = {};

    // 1. Preparar y emitir. Las filas de detalle de conceptos siguen a su inmueble al paginar u ordenar.
    const previewTable = document.getElementById('billing-preview-table');
    if (previewTable && previewTable.querySelector('.billing-row')) {
        const details = new Map([...previewTable.querySelectorAll('tr[data-detail-for]')].map((row) => [row.dataset.detailFor, row]));
        const filterInput = document.getElementById('billing-filter');
        const bulkActions = [];
        if (document.getElementById('item-modal')) {
            bulkActions.push({ label: 'Agregar concepto', icon: 'bi-plus-lg', className: 'btn-outline-primary', run: (rows) => openItemModal(rows) });
        }
        if (document.getElementById('issue-selected-button')) {
            bulkActions.push({ label: 'Emitir y enviar seleccionados', icon: 'bi-send', className: 'btn-primary', run: (rows) => openIssueSelected(rows) });
        }
        grids.preview = new DataGrid(previewTable, {
            storageKey: 'billing-preview',
            rowSelector: '.billing-row',
            filter: (row) => {
                const state = checked('row-state');
                return (state === 'all' || row.dataset.state === state) && normalize(row.dataset.search).includes(normalize(filterInput?.value ?? ''));
            },
            sortValue: sortFromData,
            selectable: bulkActions.length > 0,
            bulkActions,
            toolbar: document.getElementById('billing-grid-tools'),
            bulkBar: document.getElementById('billing-grid-bulk'),
            pager: document.getElementById('billing-grid-pager'),
            exportName: `cartera-preparar-<?= $escape($period) ?>`,
            onChange: (grid) => {
                updateIssueSelectedButton(grid.selected());
                grid.rows().forEach((row) => {
                    const detail = details.get(row.dataset.unitId);
                    if (!detail) return;
                    if (row.nextElementSibling !== detail) row.after(detail);
                    detail.hidden = row.hidden;
                });
            },
        });
        document.getElementById('issue-selected-button')?.addEventListener('click', () => openIssueSelected(grids.preview.selected()));
        filterInput?.addEventListener('input', () => grids.preview.refresh(true));
        document.querySelectorAll('[name="row-state"]').forEach((radio) => radio.addEventListener('change', () => grids.preview.refresh(true)));
    }

    // 2. Notas emitidas.
    const notesTable = document.getElementById('notes-table');
    if (notesTable) {
        grids.notes = new DataGrid(notesTable, {
            storageKey: 'billing-notes',
            rowSelector: '.note-row',
            filter: (row) => {
                const state = checked('note-state');
                return (state === 'all' || row.dataset.state.split(' ').includes(state)) && normalize(row.dataset.search).includes(searchFor('notes-table'));
            },
            sortValue: sortFromData,
            toolbar: document.getElementById('notes-grid-tools'),
            pager: document.getElementById('notes-grid-pager'),
            exportName: `cartera-notas-<?= $escape($period) ?>`,
        });
        document.querySelector('[data-table-filter="notes-table"]')?.addEventListener('input', () => grids.notes.refresh(true));
        document.querySelectorAll('[name="note-state"]').forEach((radio) => radio.addEventListener('change', () => grids.notes.refresh(true)));
    }

    // 3. Pagos.
    const paymentsTable = document.getElementById('payments-table');
    if (paymentsTable) {
        grids.payments = new DataGrid(paymentsTable, {
            storageKey: 'billing-payments',
            rowSelector: '.payment-row',
            filter: (row) => {
                const state = checked('payment-state');
                return (state === 'all' || row.dataset.state === state) && normalize(row.dataset.search).includes(searchFor('payments-table'));
            },
            sortValue: sortFromData,
            toolbar: document.getElementById('payments-grid-tools'),
            pager: document.getElementById('payments-grid-pager'),
            exportName: `cartera-pagos-<?= $escape($period) ?>`,
        });
        document.querySelector('[data-table-filter="payments-table"]')?.addEventListener('input', () => grids.payments.refresh(true));
        document.querySelectorAll('[name="payment-state"]').forEach((radio) => radio.addEventListener('change', () => grids.payments.refresh(true)));
    }

    // Agregar concepto: a un inmueble (botón de la fila) o a los seleccionados (acción en lote).
    const itemModalElement = document.getElementById('item-modal');
    let openItemModal = () => {};
    if (itemModalElement) {
        const itemModal = bootstrap.Modal.getOrCreateInstance(itemModalElement);
        const itemForm = document.getElementById('item-form');
        const bulkUnits = document.getElementById('item-bulk-units');
        const singleUnit = document.getElementById('item-unit');
        const category = document.getElementById('item-category');
        const description = document.getElementById('item-description');
        const help = document.getElementById('item-category-help');
        const periodLabel = <?= json_encode($periodLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let suggested = '';
        const updateCategory = () => {
            const option = category.selectedOptions[0];
            help.textContent = option.dataset.help;
            if (description.value === '' || description.value === suggested) {
                suggested = `${option.dataset.label} · ${periodLabel}`;
                description.value = suggested;
            }
        };
        category.addEventListener('change', updateCategory);
        // units: [{ id, name }]. Con más de uno se envía al endpoint de lote.
        openItemModal = (rowsOrUnits) => {
            const units = rowsOrUnits.map((item) => (item instanceof HTMLElement
                ? { id: item.dataset.unitId, name: item.dataset.unitName, issued: item.dataset.issued === '1' }
                : item)).filter((unit) => !unit.issued);
            const skipped = rowsOrUnits.length - units.length;
            if (units.length === 0) {
                window.alert('Los inmuebles seleccionados ya tienen la nota emitida: no se les pueden agregar conceptos.');
                return;
            }
            const bulk = units.length > 1 || rowsOrUnits[0] instanceof HTMLElement;
            itemForm.action = bulk ? itemForm.dataset.bulkAction : itemForm.dataset.singleAction;
            singleUnit.disabled = bulk;
            singleUnit.value = bulk ? '' : units[0].id;
            bulkUnits.replaceChildren(...(bulk ? units.map((unit) => Object.assign(document.createElement('input'), { type: 'hidden', name: 'unit_ids[]', value: unit.id })) : []));
            document.getElementById('item-unit-label').textContent = bulk ? 'Inmuebles:' : 'Inmueble:';
            document.getElementById('item-unit-name').textContent = bulk
                ? `${units.length} seleccionado(s)${units.length <= 3 ? ` (${units.map((unit) => unit.name).join(', ')})` : ''}${skipped > 0 ? ` · se omiten ${skipped} ya emitido(s)` : ''}`
                : units[0].name;
            description.value = '';
            suggested = '';
            document.getElementById('item-amount-display').value = '';
            document.getElementById('item-amount').value = '';
            updateCategory();
            itemModal.show();
        };
        document.querySelectorAll('[data-add-item]').forEach((button) => button.addEventListener('click', () => {
            openItemModal([{ id: button.dataset.unitId, name: button.dataset.unitName, issued: false }]);
        }));
        itemModalElement.addEventListener('shown.bs.modal', () => document.getElementById('item-amount-display').focus());
    }

    // Registrar pago: muestra el saldo de la nota y avisa si quedará saldo a favor.
    const paymentSelect = document.getElementById('payment-statement');
    if (paymentSelect) {
        const display = document.getElementById('payment-amount-display');
        const balanceText = document.getElementById('payment-balance');
        const amountHelp = document.getElementById('payment-amount-help');
        const payFull = document.getElementById('pay-full');
        const balance = () => parseInt(paymentSelect.selectedOptions[0]?.dataset.balance ?? '0', 10);
        const refresh = () => {
            const owed = balance();
            const hasStatement = paymentSelect.value !== '';
            balanceText.innerHTML = !hasStatement ? '' : owed > 0 ? `Saldo pendiente: <strong>${money(owed)}</strong>` : '<span class="text-success">Esta nota no tiene saldo pendiente.</span>';
            payFull.hidden = !hasStatement || owed <= 0;
            const cents = parseMoney(display.value);
            amountHelp.className = 'form-text';
            amountHelp.textContent = '';
            if (hasStatement && cents !== null && cents > owed) {
                amountHelp.className = 'form-text text-warning';
                amountHelp.textContent = `Supera el saldo: quedará ${money(cents - Math.max(owed, 0))} a favor del residente.`;
            } else if (hasStatement && cents !== null && cents < owed) {
                amountHelp.textContent = `Pago parcial: quedará pendiente ${money(owed - cents)}.`;
            }
        };
        paymentSelect.addEventListener('change', refresh);
        display.addEventListener('money-change', refresh);
        payFull.addEventListener('click', () => {
            display.value = money(balance()).replace('$ ', '');
            display.syncMoney();
        });
        document.querySelectorAll('[data-pay-statement]').forEach((button) => button.addEventListener('click', () => {
            paymentSelect.value = button.dataset.payStatement;
            showTab('pagos');
            refresh();
            display.focus();
        }));
        document.getElementById('payment-form').addEventListener('submit', (event) => {
            const cents = parseMoney(display.value);
            if (cents !== null && cents > balance() && !window.confirm(`El valor supera el saldo de la nota. Quedará ${money(cents - Math.max(balance(), 0))} a favor del residente. ¿Registrar de todos modos?`)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    }

    // Lista de chequeo de conceptos: se recuerda por período en este navegador y se reinicia si cambian los valores.
    const checklist = document.getElementById('concept-checklist');
    const conceptChecks = [...document.querySelectorAll('[data-concept-check]')];
    const checklistKey = checklist ? `billing-checklist:${checklist.dataset.period}` : null;
    const readChecklist = () => {
        try {
            const saved = JSON.parse(localStorage.getItem(checklistKey) || 'null');
            return saved && saved.signature === checklist.dataset.signature ? saved.checked : [];
        } catch { return []; }
    };
    const saveChecklist = () => {
        try {
            localStorage.setItem(checklistKey, JSON.stringify({ signature: checklist.dataset.signature, checked: conceptChecks.filter((c) => c.checked).map((c) => c.value) }));
        } catch { /* navegador sin almacenamiento: la lista funciona igual en esta visita */ }
    };
    const pendingConcepts = () => conceptChecks.filter((c) => !c.checked).map((c) => c.labels[0].querySelector('strong').textContent);

    // Emisión: se habilita al marcar la revisión y con todos los conceptos revisados.
    const issueConfirm = document.getElementById('issue-confirm');
    const updateIssueState = () => {
        const pending = pendingConcepts();
        const progress = document.getElementById('concept-progress');
        if (progress) {
            progress.textContent = `${conceptChecks.length - pending.length} de ${conceptChecks.length} revisados`;
            progress.className = `badge rounded-pill ${pending.length === 0 ? 'text-bg-success' : 'text-bg-secondary'}`;
        }
        const status = document.getElementById('issue-checklist-status');
        if (status) {
            status.className = `small mb-3 ${pending.length === 0 ? 'text-success' : 'text-warning-emphasis'}`;
            status.innerHTML = '';
            const icon = document.createElement('i');
            icon.className = `bi ${pending.length === 0 ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'} me-1`;
            icon.setAttribute('aria-hidden', 'true');
            status.append(icon, pending.length === 0
                ? 'Todos los conceptos están revisados.'
                : `Falta revisar en Preparar: ${pending.join(', ')}.`);
        }
        const submit = document.getElementById('issue-submit');
        if (submit) submit.disabled = !(issueConfirm?.checked && pending.length === 0);
    };
    if (checklist) {
        const saved = readChecklist();
        conceptChecks.forEach((check) => {
            check.checked = saved.includes(check.value);
            check.addEventListener('change', () => { saveChecklist(); updateIssueState(); });
        });
        document.getElementById('concept-check-all').addEventListener('click', () => {
            conceptChecks.forEach((check) => { check.checked = true; });
            saveChecklist();
            updateIssueState();
        });
    }
    issueConfirm?.addEventListener('change', updateIssueState);
    updateIssueState();

    // Vista previa del documento en el modal de emisión: se carga al abrir y al cambiar de inmueble.
    const draftFrame = document.getElementById('draft-frame');
    const draftUnit = document.getElementById('draft-unit');
    if (draftFrame && draftUnit) {
        const loadDraft = () => {
            const url = draftFrame.dataset.base + encodeURIComponent(draftUnit.value);
            if (draftFrame.getAttribute('src') !== url) draftFrame.setAttribute('src', url);
            document.getElementById('draft-open').href = url;
            document.getElementById('draft-prev').disabled = draftUnit.selectedIndex <= 0;
            document.getElementById('draft-next').disabled = draftUnit.selectedIndex >= draftUnit.options.length - 1;
        };
        const step = (delta) => {
            draftUnit.selectedIndex = Math.min(Math.max(0, draftUnit.selectedIndex + delta), draftUnit.options.length - 1);
            loadDraft();
        };
        draftUnit.addEventListener('change', loadDraft);
        document.getElementById('draft-prev').addEventListener('click', () => step(-1));
        document.getElementById('draft-next').addEventListener('click', () => step(1));
        document.getElementById('issue-modal').addEventListener('show.bs.modal', (event) => {
            // El botón principal (relatedTarget) emite todo; la acción de selección abre el modal sin relatedTarget.
            applyIssueMode(event.relatedTarget ? null : pendingIssueSelection);
            pendingIssueSelection = null;
            updateIssueState();
            loadDraft();
        });
    }

    // Confirmaciones y estado «procesando» para evitar envíos dobles.
    document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
    document.querySelectorAll('form').forEach((form) => form.addEventListener('submit', (event) => {
        if (event.defaultPrevented || form.method.toLowerCase() !== 'post') return;
        const button = form.querySelector('button[type="submit"][data-loading-label]');
        if (button) {
            setTimeout(() => {
                button.disabled = true;
                button.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>${button.dataset.loadingLabel}`;
            });
        }
    }));
});
</script>
