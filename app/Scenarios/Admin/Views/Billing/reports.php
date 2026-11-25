<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;
use App\Modules\Billing\ReportService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$short = static function (int $cents): string {
    $text = '$ ' . ($cents < 0 ? '-' : '') . number_format(intdiv(abs($cents), 100), 0, ',', '.');
    return abs($cents) % 100 !== 0 ? $text . ',' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT) : $text;
};
$ageTones = ['al_dia' => 'success', 'corriente' => 'info', '1_30' => 'warning', '31_60' => 'warning', '61_90' => 'danger', 'mas_90' => 'danger'];
$ageColors = ['al_dia' => '#198754', 'corriente' => '#0dcaf0', '1_30' => '#ffc107', '31_60' => '#fd7e14', '61_90' => '#dc3545', 'mas_90' => '#842029'];
$monthNames = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$monthLabel = static fn (string $ym): string => $monthNames[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 2, 2);

$receivable = array_sum(array_map(static fn (array $row): int => $row['amount'], $aging));
$overdueKeys = ['1_30', '31_60', '61_90', 'mas_90'];
$overdueUnits = array_sum(array_map(static fn (string $key): int => $aging[$key]['count'], $overdueKeys));
$overdueAmount = array_sum(array_map(static fn (string $key): int => $aging[$key]['amount'], $overdueKeys));
$totalUnits = array_sum(array_map(static fn (array $row): int => $row['count'], $aging));
$upToDate = $aging['al_dia']['count'];
$lastMonth = $collections[count($collections) - 1] ?? ['month' => $currentMonth, 'total' => 0, 'payments' => 0];
$previousMonth = $collections[count($collections) - 2] ?? ['total' => 0];
$maxCollection = max(1, ...array_map(static fn (array $row): int => $row['total'], $collections));
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Reportes de cartera</h1>
            <p class="text-secondary mb-0">Estado actual de cada inmueble según su nota más reciente (que ya incluye el saldo de meses anteriores).</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
        <form method="get" action="<?= $escape($basePath) ?>/cartera/buscar" role="search">
            <label class="visually-hidden" for="global-billing-search">Buscar en toda la cartera</label>
            <div class="input-group">
                <span class="input-group-text bg-transparent"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input id="global-billing-search" name="q" type="search" class="form-control" style="width: 15rem" maxlength="100" placeholder="Buscar en todos los meses…" title="Inmueble, residente, correo, NC-…, referencia o valor">
            </div>
        </form>
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1" aria-hidden="true"></i>Exportar</button>
            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 17rem">
                <a class="btn btn-sm btn-outline-secondary w-100 mb-3" href="<?= $escape($basePath) ?>/cartera/reportes/exportar?tipo=cartera"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Cartera por inmueble (CSV)</a>
                <form method="get" action="<?= $escape($basePath) ?>/cartera/reportes/exportar">
                    <input type="hidden" name="tipo" value="pagos">
                    <label class="form-label small" for="export-month">Pagos recibidos en el mes</label>
                    <div class="input-group input-group-sm">
                        <input id="export-month" class="form-control" type="month" name="mes" required value="<?= $escape($currentMonth) ?>" max="<?= $escape($currentMonth) ?>">
                        <button class="btn btn-outline-secondary" type="submit">CSV</button>
                    </div>
                </form>
            </div>
        </div>
        </div>
    </div>

    <?php if ($latePayments !== []): ?>
        <div class="alert alert-warning" role="alert">
            <strong><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= count($latePayments) ?> pago(s) requieren ajuste.</strong>
            Se registraron después de emitir la nota siguiente, así que no se reflejan en el saldo trasladado. Registra un concepto de <em>ajuste de saldo</em> en el período vigente del inmueble.
            <ul class="mb-0 mt-2 small">
                <?php foreach ($latePayments as $late): ?>
                    <li><?= $escape($late['unit_name']) ?> · <?= $short(BillingService::moneyToCents($late['amount'])) ?> en la nota de <?= $escape($late['period']) ?> →
                        <a href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($late['newer_period']) ?>#preparar">ajustar en <?= $escape($late['newer_period']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <?php
        $kpis = [
            ['Total por cobrar', $short($receivable), $totalUnits . ' inmueble(s) con nota', 'bi-wallet2', 'text-primary'],
            ['En mora', $short($overdueAmount), $overdueUnits . ' inmueble(s) con saldo vencido', 'bi-exclamation-octagon', $overdueUnits > 0 ? 'text-danger' : 'text-success'],
            ['Al día', $totalUnits > 0 ? round($upToDate * 100 / $totalUnits) . ' %' : '—', $upToDate . ' de ' . $totalUnits . ' inmuebles', 'bi-check-circle', 'text-success'],
            ['Recaudo ' . $monthLabel($lastMonth['month']), $short($lastMonth['total']), 'Mes anterior: ' . $short($previousMonth['total']), 'bi-cash-stack', 'text-success'],
        ];
        ?>
        <?php foreach ($kpis as [$label, $value, $hint, $icon, $tone]): ?>
            <div class="col-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm"><div class="card-body">
                    <div class="d-flex justify-content-between"><span class="small text-secondary"><?= $escape($label) ?></span><i class="bi <?= $icon ?> <?= $tone ?>" aria-hidden="true"></i></div>
                    <div class="h4 mb-0 mt-1"><?= $escape($value) ?></div>
                    <div class="small text-secondary"><?= $escape($hint) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">Antigüedad de saldos</h2></div>
                <div class="card-body">
                    <?php if ($receivable > 0): ?>
                        <div class="d-flex rounded overflow-hidden mb-3" style="height: 1.25rem" role="img" aria-label="Distribución del saldo por antigüedad">
                            <?php foreach ($aging as $key => $bucket): ?>
                                <?php if ($bucket['amount'] > 0): ?>
                                    <a href="<?= $escape($basePath) ?>/cartera/reportes?antiguedad=<?= $escape($key) ?>" style="width: <?= round($bucket['amount'] * 100 / $receivable, 2) ?>%; background: <?= $ageColors[$key] ?>"
                                       title="<?= $escape(ReportService::AGE_LABELS[$key] . ': ' . $short($bucket['amount'])) ?>"></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($aging as $key => $bucket): ?>
                            <a class="list-group-item list-group-item-action d-flex align-items-center gap-2 px-0<?= $selectedAge === $key ? ' active rounded px-2' : '' ?>"
                               href="<?= $escape($basePath) ?>/cartera/reportes<?= $selectedAge === $key ? '' : '?antiguedad=' . $escape($key) ?>"<?= $selectedAge === $key ? ' aria-current="true"' : '' ?>>
                                <span class="rounded-circle flex-shrink-0" style="width: .75rem; height: .75rem; background: <?= $ageColors[$key] ?>" aria-hidden="true"></span>
                                <span class="flex-grow-1"><?= $escape(ReportService::AGE_LABELS[$key]) ?></span>
                                <span class="small <?= $selectedAge === $key ? '' : 'text-secondary' ?>"><?= (int) $bucket['count'] ?> inm.</span>
                                <span class="fw-semibold text-end" style="min-width: 8rem"><?= $short($bucket['amount']) ?></span>
                                <span class="small text-end <?= $selectedAge === $key ? '' : 'text-secondary' ?>" style="min-width: 3rem"><?= $receivable > 0 ? round($bucket['amount'] * 100 / $receivable) : 0 ?> %</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mb-0 mt-2">Haz clic en un rango para ver solo esos inmuebles. La antigüedad cuenta los meses consecutivos con saldo arrastrado, más el mes actual si ya venció.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">Recaudo de los últimos 12 meses</h2></div>
                <div class="card-body">
                    <?php if (array_sum(array_column($collections, 'total')) === 0): ?>
                        <div class="text-center text-secondary py-5"><i class="bi bi-bar-chart fs-1 d-block mb-2" aria-hidden="true"></i>Aún no hay pagos registrados en los últimos 12 meses.</div>
                    <?php else: ?>
                    <div class="d-flex align-items-end gap-1" style="height: 11rem" role="img" aria-label="Recaudo mensual">
                        <?php foreach ($collections as $month): ?>
                            <div class="flex-fill d-flex flex-column justify-content-end align-items-center h-100" title="<?= $escape($monthLabel($month['month']) . ': ' . $short($month['total']) . ' en ' . (int) $month['payments'] . ' pago(s)') ?>">
                                <div class="w-100 rounded-top <?= $month['month'] === $currentMonth ? 'bg-primary' : 'bg-primary-subtle' ?>" style="height: <?= max(2, round($month['total'] * 100 / $maxCollection)) ?>%"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-1 mt-1">
                        <?php foreach ($collections as $month): ?>
                            <span class="flex-fill text-center text-secondary" style="font-size: .65rem"><?= $escape($monthLabel($month['month'])) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mb-0 mt-2">Pagos vigentes por mes en que se recibieron. Pasa el cursor sobre una barra para ver el valor.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
            <h2 class="card-title h6 mb-0 me-auto">
                Estado por inmueble
                <?php if ($selectedAge !== ''): ?>
                    <span class="badge text-bg-<?= $ageTones[$selectedAge] ?> ms-1"><?= $escape(ReportService::AGE_LABELS[$selectedAge]) ?></span>
                    <a class="small ms-1" href="<?= $escape($basePath) ?>/cartera/reportes">Quitar filtro</a>
                <?php endif; ?>
            </h2>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="only-debt">
                <label class="form-check-label small" for="only-debt">Solo con saldo</label>
            </div>
            <select id="portfolio-sort" class="form-select form-select-sm" style="width: auto" aria-label="Ordenar">
                <option value="name">Ordenar por inmueble</option>
                <option value="balance">Mayor saldo primero</option>
                <option value="age">Más antiguo primero</option>
            </select>
            <input id="portfolio-filter" type="search" class="form-control form-control-sm" style="max-width: 15rem" placeholder="Buscar inmueble o residente" aria-label="Buscar inmueble o residente">
        </div>
        <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-billing-reports-1">
            <table class="table table-hover align-middle mb-0" id="portfolio-table">
                <caption id="tbl-billing-reports-1" class="visually-hidden">Recaudo de los últimos 12 meses</caption>
                <thead class="table-light"><tr><th scope="col">Inmueble</th><th scope="col">Última nota</th><th scope="col" class="text-end">Total</th><th scope="col" class="text-end">Pagado</th><th scope="col" class="text-end">Saldo</th><th scope="col">Vence</th><th scope="col">Antigüedad</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php $ageOrder = array_flip(array_keys(ReportService::AGE_LABELS)); ?>
                <?php foreach ($portfolio as $row): ?>
                    <tr data-search="<?= $escape(mb_strtolower($row['unit_name'] . ' ' . $row['recipients'], 'UTF-8')) ?>"
                        data-name="<?= $escape(mb_strtolower($row['unit_name'], 'UTF-8')) ?>" data-balance="<?= (int) $row['balance'] ?>" data-age="<?= (int) $ageOrder[$row['age']] ?>">
                        <td><span class="fw-semibold"><?= $escape($row['unit_name']) ?></span><span class="d-block small text-secondary"><?= $escape($row['recipients']) ?></span></td>
                        <td class="small"><a href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= (int) $row['statement_id'] ?>" target="_blank" rel="noopener" class="font-monospace"><?= $escape($row['number']) ?></a></td>
                        <td class="text-end"><?= $short(max(0, $row['total_due'])) ?></td>
                        <td class="text-end"><?= $short($row['payments_total']) ?></td>
                        <td class="text-end fw-semibold">
                            <?php if ($row['balance'] < 0): ?><span class="text-success">A favor <?= $short(-$row['balance']) ?></span>
                            <?php elseif ($row['balance'] === 0): ?><span class="text-success">$ 0</span>
                            <?php else: ?><?= $short($row['balance']) ?><?php endif; ?>
                        </td>
                        <td class="small<?= $row['past_due'] ? ' text-danger' : '' ?>"><?= $escape($row['payment_due_date']) ?></td>
                        <td><span class="badge text-bg-<?= $ageTones[$row['age']] ?>"><?= $escape(ReportService::AGE_LABELS[$row['age']]) ?></span></td>
                        <td class="text-end">
                            <?php if ($row['balance'] > 0): ?>
                                <a class="btn btn-outline-success btn-sm text-nowrap" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($row['period']) ?>#pagos"><i class="bi bi-cash-coin" aria-hidden="true"></i> Pago</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($portfolio === []): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-5">No hay notas emitidas<?= $selectedAge !== '' ? ' en este rango' : '' ?>.</td></tr>
                <?php endif; ?>
                <tr id="no-portfolio-results" hidden><td colspan="8" class="text-center text-secondary py-4">Ningún inmueble coincide con el filtro.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
(() => {
    const body = document.querySelector('#portfolio-table tbody');
    const rows = [...body.querySelectorAll('tr[data-search]')];
    const filter = document.getElementById('portfolio-filter');
    const onlyDebt = document.getElementById('only-debt');
    const sort = document.getElementById('portfolio-sort');
    const apply = () => {
        const query = filter.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            const show = row.dataset.search.includes(query) && (!onlyDebt.checked || parseInt(row.dataset.balance, 10) > 0);
            row.hidden = !show;
            if (show) visible++;
        });
        document.getElementById('no-portfolio-results').hidden = visible > 0 || rows.length === 0;
    };
    const order = () => {
        const by = sort.value;
        rows.sort((a, b) => by === 'balance' ? b.dataset.balance - a.dataset.balance
            : by === 'age' ? (b.dataset.age - a.dataset.age) || (b.dataset.balance - a.dataset.balance)
            : a.dataset.name.localeCompare(b.dataset.name, 'es', { numeric: true }));
        rows.forEach((row) => body.insertBefore(row, document.getElementById('no-portfolio-results')));
    };
    filter.addEventListener('input', apply);
    onlyDebt.addEventListener('change', apply);
    sort.addEventListener('change', order);
})();
</script>
