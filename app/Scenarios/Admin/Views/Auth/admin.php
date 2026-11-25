<?php

declare(strict_types=1);

use App\Core\Authorization;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\ReportService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$currency = static fn (int $cents): string => BillingService::money($cents);
$dashboard = is_array($dashboard ?? null) ? $dashboard : null;
$viewer = Authorization::currentUser() ?? [];
$can = static fn (string $module): bool => $viewer !== [] && Authorization::hasPermission($viewer, $module, 1);

// Saludo y fecha en español sin depender de la extensión intl.
$now = new DateTimeImmutable('now');
$hour = (int) $now->format('G');
$greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
$weekdays = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$today = $weekdays[(int) $now->format('w')] . ', ' . $now->format('j') . ' de ' . $months[(int) $now->format('n') - 1] . ' de ' . $now->format('Y');

// Accesos directos: solo los módulos que el rol puede abrir (cobros y pagos ya están en la cabecera).
$shortcuts = array_values(array_filter([
    ['cartera.cobros', '/cartera/reportes', 'bi-graph-up', 'Reportes de cartera', 'Antigüedad, recaudo y exportes'],
    ['mensajes', '/mensajes', 'bi-envelope', 'Mensajes', 'Comunicados a los residentes'],
    ['inmuebles.asignaciones', '/inmuebles/asignaciones', 'bi-person-vcard', 'Asignación de inmuebles', 'Usuarios por inmueble'],
    ['configuracion.equipo', '/configuracion/equipo', 'bi-people', 'Equipo', 'Cuentas de la aplicación'],
    ['configuracion.parametrizacion', '/configuracion/parametrizacion', 'bi-building-gear', 'Parametrización', 'Datos de la copropiedad'],
    ['configuracion.financiera', '/configuracion/financiera', 'bi-sliders', 'Configuración financiera', 'Cuota, descuento y mora'],
    ['configuracion.correo', '/configuracion/correo', 'bi-envelope-at', 'Correo', 'Servidor SMTP y pruebas'],
], static fn (array $shortcut): bool => $can($shortcut[0])));

// Pendientes ordenados por gravedad.
$alerts = [];
if ($dashboard !== null) {
    $units = is_array($dashboard['units']) ? $dashboard['units'] : null;
    if ($dashboard['failed_deliveries'] > 0) {
        $alerts[] = ['danger', 'bi-envelope-x', $dashboard['failed_deliveries'] . ' correo(s) de notas de cobro fallaron.', 'Reintentar envío', '/cartera/cobros'];
    }
    if ($dashboard['late_payments'] !== []) {
        $alerts[] = ['warning', 'bi-arrow-left-right', count($dashboard['late_payments']) . ' pago(s) llegaron después de trasladar el saldo y necesitan ajuste.', 'Revisar pagos', '/cartera/reportes'];
    }
    if ($dashboard['pending_deliveries'] > 0) {
        $alerts[] = ['warning', 'bi-hourglass-split', $dashboard['pending_deliveries'] . ' correo(s) de notas siguen pendientes de envío.', 'Enviar ahora', '/cartera/cobros'];
    }
    if ($units !== null && $units['missing_coefficient'] > 0) {
        $alerts[] = ['warning', 'bi-percent', $units['missing_coefficient'] . ' inmueble(s) sin coeficiente: la emisión quedará bloqueada.', 'Completar conformación', '/configuracion/conformacion'];
    }
    if ($units !== null && $units['unassigned'] > 0) {
        $alerts[] = ['info', 'bi-person-dash', $units['unassigned'] . ' inmueble(s) sin usuarios activos no recibirán notas.', 'Asignar usuarios', '/inmuebles/asignaciones'];
    }
    if ($dashboard['issued_this_period'] === 0) {
        $alerts[] = ['info', 'bi-calendar-plus', 'Aún no se han emitido las notas del período ' . $dashboard['current_period'] . '.', 'Emitir notas', '/cartera/cobros?periodo=' . $dashboard['current_period']];
    }

    $collections = $dashboard['collections'];
    $previousMonth = count($collections) >= 2 ? (int) $collections[count($collections) - 2]['total'] : 0;
    $issuedTarget = $units !== null ? (int) $units['assigned'] : (int) $dashboard['units_billed'];
    $issuedProgress = $issuedTarget > 0 ? min(100, (int) round($dashboard['issued_this_period'] * 100 / $issuedTarget)) : 0;
    $arrearsShare = $dashboard['units_billed'] > 0 ? (int) round($dashboard['units_in_arrears'] * 100 / $dashboard['units_billed']) : 0;
}
$ageTone = ['al_dia' => 'success', 'corriente' => 'secondary', '1_30' => 'warning', '31_60' => 'warning', '61_90' => 'danger', 'mas_90' => 'danger'];

?>
<section class="content">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <p class="text-secondary small text-uppercase fw-semibold mb-1"><?= $escape($propertyName) ?> · <?= $escape(ucfirst($today)) ?></p>
                <h1 class="h3 mb-1"><?= $escape($greeting) ?>, <?= $escape($username) ?></h1>
                <p class="text-secondary mb-0">
                    <?php if ($dashboard === null): ?>
                        Elige un módulo para empezar.
                    <?php elseif ($alerts === []): ?>
                        <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>Todo está al día en el período <?= $escape($dashboard['current_period']) ?>.
                    <?php else: ?>
                        <i class="bi bi-exclamation-circle-fill text-warning me-1" aria-hidden="true"></i>Tienes <?= count($alerts) ?> pendiente(s) por revisar.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($can('cartera.cobros')): ?>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary" href="<?= $escape($basePath) ?>/cartera/cobros"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Ir a cobros</a>
                    <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/cartera/cobros#registrar-pago"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Registrar pago</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($dashboard !== null): ?>
        <h2 class="visually-hidden">Indicadores de cartera</h2>
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small">Cartera por cobrar</span>
                            <i class="bi bi-wallet2 fs-5 text-primary" aria-hidden="true"></i>
                        </div>
                        <p class="h4 mb-1"><?= $currency($dashboard['receivable']) ?></p>
                        <p class="small text-secondary mb-0">
                            <?= $dashboard['credit'] > 0 ? 'Saldos a favor: ' . $escape($currency($dashboard['credit'])) : 'Sin saldos a favor' ?>
                        </p>
                    </div>
                    <a class="card-footer small text-decoration-none bg-transparent" href="<?= $escape($basePath) ?>/cartera/reportes">Ver reporte <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small">Recaudo del mes</span>
                            <i class="bi bi-cash-stack fs-5 text-success" aria-hidden="true"></i>
                        </div>
                        <p class="h4 mb-1"><?= $currency($dashboard['collected_this_month']) ?></p>
                        <p class="small text-secondary mb-0">Mes anterior: <?= $currency($previousMonth) ?></p>
                    </div>
                    <a class="card-footer small text-decoration-none bg-transparent" href="<?= $escape($basePath) ?>/cartera/reportes/exportar?tipo=pagos&amp;mes=<?= $escape($now->format('Y-m')) ?>">Descargar pagos (CSV) <i class="bi bi-download" aria-hidden="true"></i></a>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small">Inmuebles en mora</span>
                            <i class="bi bi-exclamation-octagon fs-5 text-danger" aria-hidden="true"></i>
                        </div>
                        <p class="h4 mb-1"><?= (int) $dashboard['units_in_arrears'] ?> <small class="fs-6 text-secondary fw-normal">de <?= (int) $dashboard['units_billed'] ?></small></p>
                        <progress class="app-progress is-danger mt-2" style="--progress-height: .375rem" aria-label="Porcentaje de inmuebles en mora" value="<?= $arrearsShare ?>" max="100"></progress>
                    </div>
                    <a class="card-footer small text-decoration-none bg-transparent" href="<?= $escape($basePath) ?>/cartera/reportes">Ver morosos <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small">Notas emitidas · <?= $escape($dashboard['current_period']) ?></span>
                            <i class="bi bi-receipt fs-5 text-secondary" aria-hidden="true"></i>
                        </div>
                        <p class="h4 mb-1"><?= (int) $dashboard['issued_this_period'] ?> <small class="fs-6 text-secondary fw-normal">de <?= $issuedTarget ?></small></p>
                        <progress class="app-progress mt-2" style="--progress-height: .375rem" aria-label="Avance de emisión del período" value="<?= $issuedProgress ?>" max="100"></progress>
                    </div>
                    <a class="card-footer small text-decoration-none bg-transparent" href="<?= $escape($basePath) ?>/cartera/cobros">Ir a cobros <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="card-title h6 mb-0">Pendientes</h2>
                        <?php if ($alerts !== []): ?><span class="badge rounded-pill text-bg-warning"><?= count($alerts) ?></span><?php endif; ?>
                    </div>
                    <?php if ($alerts === []): ?>
                        <div class="card-body text-center text-secondary py-5">
                            <i class="bi bi-check2-circle fs-1 text-success d-block mb-2" aria-hidden="true"></i>
                            No hay pendientes operativos.
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($alerts as [$tone, $icon, $text, $action, $link]): ?>
                                <li class="list-group-item d-flex align-items-start gap-3 py-3">
                                    <i class="bi <?= $escape($icon) ?> fs-5 text-<?= $escape($tone) ?>" aria-hidden="true"></i>
                                    <div class="flex-grow-1">
                                        <p class="mb-1"><?= $escape($text) ?></p>
                                        <a class="small fw-semibold text-decoration-none" href="<?= $escape($basePath . $link) ?>"><?= $escape($action) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="card-title h6 mb-0">Mayores saldos pendientes</h2>
                        <a class="small text-decoration-none" href="<?= $escape($basePath) ?>/cartera/reportes">Ver todos</a>
                    </div>
                    <?php if ($dashboard['top_debtors'] === []): ?>
                        <div class="card-body text-center text-secondary py-5">
                            <i class="bi bi-emoji-smile fs-1 text-success d-block mb-2" aria-hidden="true"></i>
                            Sin saldos pendientes.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-auth-admin-1">
                            <table class="table table-hover align-middle mb-0">
                                <caption id="tbl-auth-admin-1" class="visually-hidden">Mayores saldos pendientes</caption>
                                <thead class="table-light"><tr><th scope="col">Inmueble</th><th scope="col">Antigüedad</th><th scope="col" class="text-end">Saldo</th></tr></thead>
                                <tbody>
                                <?php foreach ($dashboard['top_debtors'] as $debtor): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= $escape($debtor['unit_name']) ?></td>
                                        <td><span class="badge text-bg-<?= $escape($ageTone[$debtor['age']] ?? 'secondary') ?>"><?= $escape(ReportService::AGE_LABELS[$debtor['age']] ?? $debtor['age']) ?></span></td>
                                        <td class="text-end"><?= $currency($debtor['balance']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($shortcuts !== []): ?>
        <h2 class="h6 text-secondary text-uppercase fw-semibold mb-3">Accesos directos</h2>
        <div class="row g-3">
            <?php foreach ($shortcuts as [, $link, $icon, $label, $hint]): ?>
                <div class="col-sm-6 col-lg-4 col-xxl-3">
                    <a class="card h-100 border-0 shadow-sm text-decoration-none text-body" href="<?= $escape($basePath . $link) ?>">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="rounded-3 bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 2.5rem; height: 2.5rem" aria-hidden="true">
                                <i class="bi <?= $escape($icon) ?> fs-5"></i>
                            </span>
                            <span>
                                <span class="d-block fw-semibold"><?= $escape($label) ?></span>
                                <span class="d-block small text-secondary"><?= $escape($hint) ?></span>
                            </span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($dashboard === null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-secondary">Tu acceso administrativo está activo. Usa el menú lateral para entrar a los módulos que tienes habilitados.</div>
        </div>
    <?php endif; ?>
</section>
