<?php

declare(strict_types=1);

use App\Modules\Billing\BillingSearchService;
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
// Resalta lo buscado sin romper el escape del texto.
$highlight = static function (string $text) use ($escape, $query): string {
    if ($query === '') {
        return $escape($text);
    }
    $pattern = '/' . preg_quote($query, '/') . '/iu';
    $parts = preg_split($pattern, $text, -1);
    preg_match_all($pattern, $text, $matches);
    if ($parts === false || count($parts) === 1) {
        return $escape($text);
    }
    $html = '';
    foreach ($parts as $index => $part) {
        $html .= $escape($part);
        if (isset($matches[0][$index])) {
            $html .= '<mark class="px-0">' . $escape($matches[0][$index]) . '</mark>';
        }
    }
    return $html;
};
$ageTones = ['al_dia' => 'success', 'corriente' => 'info', '1_30' => 'warning', '31_60' => 'warning', '61_90' => 'danger', 'mas_90' => 'danger'];
$categories = [
    'opening_balance' => 'Saldo inicial / ajuste', 'interest' => 'Interés moratorio', 'legal' => 'Cobro jurídico',
    'fine' => 'Multa', 'retroactive' => 'Retroactivo', 'extra' => 'Cuota extra', 'other' => 'Otro cobro',
];
$tooShort = $query !== '' && mb_strlen($query, 'UTF-8') < BillingSearchService::MIN_LENGTH;
$total = count($results['units']) + count($results['statements']) + count($results['payments']) + count($results['items']);
$examples = ['Torre 2', 'Apartamento 01', 'NC-202611', '350.000', '@gmail.com'];
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Buscar en cartera</h1>
        <p class="text-secondary mb-0">Busca en todos los meses: inmuebles, residentes, correos, notas de cobro, pagos y conceptos.</p>
    </div>

    <form method="get" action="<?= $escape($basePath) ?>/cartera/buscar" class="card border-0 shadow-sm mb-3" role="search">
        <div class="card-body">
            <label for="billing-search" class="visually-hidden">Buscar en cartera</label>
            <div class="input-group input-group-lg">
                <span class="input-group-text bg-transparent"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input id="billing-search" name="q" type="search" class="form-control" value="<?= $escape($query) ?>" maxlength="100" autocomplete="off" autofocus
                       placeholder="Inmueble, residente, correo, NC-…, referencia de pago o valor" aria-describedby="search-tips">
                <button class="btn btn-primary px-4" type="submit">Buscar</button>
            </div>
            <div id="search-tips" class="d-flex flex-wrap align-items-center gap-2 mt-2 small text-secondary">
                <span>Prueba:</span>
                <?php foreach ($examples as $example): ?>
                    <a class="badge rounded-pill text-bg-light border text-decoration-none fw-normal" href="<?= $escape($basePath) ?>/cartera/buscar?q=<?= $escape(rawurlencode($example)) ?>"><?= $escape($example) ?></a>
                <?php endforeach; ?>
                <span class="ms-md-auto">Un valor como <code>350.000</code> también busca notas y pagos por ese monto exacto.</span>
            </div>
        </div>
    </form>

    <?php if ($query === ''): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-secondary py-5">
            <i class="bi bi-search fs-1 d-block mb-2" aria-hidden="true"></i>
            Escribe lo que buscas. Por ejemplo, el nombre de un residente para ver todas sus notas y pagos.
        </div></div>
    <?php elseif ($tooShort): ?>
        <div class="alert alert-info">Escribe al menos <?= BillingSearchService::MIN_LENGTH ?> caracteres.</div>
    <?php elseif ($total === 0): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-secondary py-5">
            <i class="bi bi-emoji-neutral fs-1 d-block mb-2" aria-hidden="true"></i>
            No se encontró nada para «<?= $escape($query) ?>».
            <div class="small mt-2">Revisa la ortografía, usa solo una parte del nombre (por ejemplo «Piso 4») o busca por el correo del residente.</div>
        </div></div>
    <?php else: ?>
        <p class="text-secondary small mb-2" aria-live="polite">
            <?= $total ?> resultado(s) para «<strong><?= $escape($query) ?></strong>»<?= $results['amount_cents'] !== null ? ' · incluye coincidencias por valor ' . $escape($short($results['amount_cents'])) : '' ?>
        </p>
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Ir a resultados">
            <?php foreach (['units' => ['Inmuebles', 'bi-house-door'], 'statements' => ['Notas', 'bi-receipt'], 'payments' => ['Pagos', 'bi-cash-coin'], 'items' => ['Conceptos', 'bi-tag']] as $key => [$label, $icon]): ?>
                <?php if ($results[$key] !== []): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="#resultados-<?= $key ?>"><i class="bi <?= $icon ?> me-1" aria-hidden="true"></i><?= $label ?> <span class="badge text-bg-secondary ms-1"><?= count($results[$key]) ?></span></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <?php
        $unitsWithNotes = array_values(array_filter($results['units'], static fn (array $u): bool => $u['statement_id'] !== null));
        $unitsWithoutNotes = array_values(array_filter($results['units'], static fn (array $u): bool => $u['statement_id'] === null));
        ?>
        <?php if ($results['units'] !== []): ?>
            <h2 class="h6 text-uppercase text-secondary fw-semibold mt-4 mb-2" id="resultados-units">Inmuebles · estado actual</h2>
            <?php if ($unitsWithoutNotes !== []): ?>
                <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
                    <span class="text-secondary me-1"><?= count($unitsWithoutNotes) ?> inmueble(s) sin notas de cobro todavía:</span>
                    <?php foreach ($unitsWithoutNotes as $unit): ?>
                        <span class="badge text-bg-light border fw-normal"><?= $highlight($unit['unit_name']) ?></span>
                    <?php endforeach; ?>
                </div></div>
            <?php endif; ?>
            <div class="row g-3 mb-3">
                <?php foreach ($unitsWithNotes as $unit): ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between gap-2">
                                    <h3 class="h6 mb-1"><?= $highlight($unit['unit_name']) ?></h3>
                                    <?php if ($unit['statement_id'] !== null): ?>
                                        <span class="badge text-bg-<?= $ageTones[$unit['age']] ?> align-self-start"><?= $escape(ReportService::AGE_LABELS[$unit['age']]) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($unit['statement_id'] === null): ?>
                                    <p class="small text-secondary mb-0">Aún no tiene notas de cobro.</p>
                                <?php else: ?>
                                    <p class="small text-secondary mb-2"><?= $highlight($unit['recipients'] !== '' ? $unit['recipients'] : 'Sin residente') ?></p>
                                    <div class="d-flex justify-content-between align-items-end">
                                        <div>
                                            <div class="small text-secondary">Saldo actual</div>
                                            <div class="h5 mb-0 <?= $unit['balance'] > 0 ? ($unit['past_due'] ? 'text-danger' : '') : 'text-success' ?>">
                                                <?= $unit['balance'] < 0 ? 'A favor ' . $short(-$unit['balance']) : $short($unit['balance']) ?>
                                            </div>
                                        </div>
                                        <div class="small text-end text-secondary">Última nota<br><a class="font-monospace" href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= (int) $unit['statement_id'] ?>" target="_blank" rel="noopener"><?= $escape($unit['number']) ?></a></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($unit['statement_id'] !== null): ?>
                                <div class="card-footer bg-transparent d-flex gap-2">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/buscar?q=<?= $escape(rawurlencode($unit['unit_name'])) ?>#resultados-statements">Historial</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($unit['period']) ?>#notas">Ver mes</a>
                                    <?php if ($canWrite && $unit['balance'] > 0): ?>
                                        <a class="btn btn-sm btn-outline-success ms-auto" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($unit['period']) ?>#pagos"><i class="bi bi-cash-coin" aria-hidden="true"></i> Registrar pago</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($results['statements'] !== []): ?>
            <div class="card border-0 shadow-sm mb-3" id="resultados-statements">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Notas de cobro</h2></div>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-billing-search-1">
                    <table class="table table-hover align-middle mb-0">
                        <caption id="tbl-billing-search-1" class="visually-hidden">Notas de cobro</caption>
                        <thead class="table-light"><tr><th scope="col">Nota</th><th scope="col">Período</th><th scope="col">Inmueble</th><th scope="col">Residente(s)</th><th scope="col" class="text-end">Total</th><th scope="col" class="text-end">Pagado</th><th scope="col" class="text-end">Saldo</th><th scope="col"></th></tr></thead>
                        <tbody>
                        <?php foreach ($results['statements'] as $statement): ?>
                            <tr>
                                <td class="font-monospace small"><?= $highlight($statement['number']) ?></td>
                                <td class="small"><?= $escape($statement['period']) ?></td>
                                <td><?= $highlight($statement['unit_name']) ?></td>
                                <td class="small"><?= $highlight($statement['recipients']) ?></td>
                                <td class="text-end"><?= $short(max(0, $statement['total'])) ?></td>
                                <td class="text-end"><?= $short($statement['paid']) ?></td>
                                <td class="text-end fw-semibold">
                                    <?php if ($statement['superseded_by'] !== null): ?>
                                        <span class="small text-secondary fw-normal" title="El saldo pasó a la nota de <?= $escape($statement['superseded_by']) ?>">→ <?= $escape($statement['superseded_by']) ?></span>
                                    <?php elseif ($statement['balance'] <= 0): ?>
                                        <span class="badge text-bg-success"><?= $statement['balance'] < 0 ? 'A favor ' . $short(-$statement['balance']) : 'Pagada' ?></span>
                                    <?php else: ?>
                                        <?= $short($statement['balance']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/cobros/nota?id=<?= $statement['id'] ?>" target="_blank" rel="noopener" title="Ver o imprimir"><i class="bi bi-printer" aria-hidden="true"></i><span class="visually-hidden">Ver nota</span></a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($statement['period']) ?>#notas" title="Abrir el mes en Cobros"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span class="visually-hidden">Abrir mes</span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($results['truncated']): ?>
                    <div class="card-footer bg-transparent small text-secondary">Se muestran las 60 notas más recientes. Escribe algo más específico para acotar.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($results['payments'] !== []): ?>
            <div class="card border-0 shadow-sm mb-3" id="resultados-payments">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Pagos</h2></div>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-billing-search-2">
                    <table class="table table-hover align-middle mb-0">
                        <caption id="tbl-billing-search-2" class="visually-hidden">Pagos</caption>
                        <thead class="table-light"><tr><th scope="col">Fecha</th><th scope="col">Inmueble</th><th scope="col">Nota</th><th scope="col" class="text-end">Valor</th><th scope="col">Referencia / notas</th><th scope="col">Registró</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($results['payments'] as $payment): ?>
                            <tr<?= $payment['voided'] ? ' class="text-secondary"' : '' ?>>
                                <td class="small text-nowrap"><?= $escape($payment['received_on']) ?></td>
                                <td><?= $highlight($payment['unit_name']) ?></td>
                                <td class="small"><a class="font-monospace" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($payment['period']) ?>#pagos"><?= $escape($payment['statement_number']) ?></a></td>
                                <td class="text-end"><?= $payment['voided'] ? '<s>' . $short($payment['amount']) . '</s>' : $short($payment['amount']) ?></td>
                                <td class="small"><?= $highlight($payment['reference']) ?><?= $payment['notes'] !== '' ? '<span class="d-block text-secondary">' . $highlight($payment['notes']) . '</span>' : '' ?></td>
                                <td class="small"><?= $escape($payment['created_by'] !== '' ? $payment['created_by'] : 'Pago en línea') ?></td>
                                <td><?= $payment['voided'] ? '<span class="badge text-bg-danger" title="' . $escape($payment['void_reason']) . '">Anulado</span>' : '<span class="badge text-bg-success">Vigente</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($results['items'] !== []): ?>
            <div class="card border-0 shadow-sm mb-3" id="resultados-items">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0"><i class="bi bi-tag me-1" aria-hidden="true"></i>Conceptos adicionales</h2></div>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-billing-search-3">
                    <table class="table table-hover align-middle mb-0">
                        <caption id="tbl-billing-search-3" class="visually-hidden">Conceptos adicionales</caption>
                        <thead class="table-light"><tr><th scope="col">Período</th><th scope="col">Inmueble</th><th scope="col">Tipo</th><th scope="col">Descripción</th><th scope="col" class="text-end">Valor</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($results['items'] as $item): ?>
                            <tr>
                                <td class="small"><a href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape($item['period']) ?>#preparar"><?= $escape($item['period']) ?></a></td>
                                <td><?= $highlight($item['unit_name']) ?></td>
                                <td class="small"><?= $escape($categories[$item['category']] ?? $item['category']) ?></td>
                                <td><?= $highlight($item['description']) ?></td>
                                <td class="text-end"><?= $short($item['amount']) ?></td>
                                <td><?= $item['issued'] ? '<span class="badge text-bg-secondary">En nota emitida</span>' : '<span class="badge text-bg-primary">Pendiente de emitir</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
