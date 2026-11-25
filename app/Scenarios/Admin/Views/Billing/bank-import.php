<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$dateTime = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-bank me-2" aria-hidden="true"></i>Importar extracto bancario</h1>
        <p class="text-secondary mb-0">Carga los movimientos de la cuenta de la copropiedad. Se toman solo los ingresos: los que traen la referencia de pago de un inmueble se registran como pago de su nota, los que coinciden con un pago reportado lo aprueban y el resto queda en Consignaciones por identificar. Antes de aplicar nada verás la propuesta de cada movimiento para confirmarla.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-7">
            <?php if ($canWrite): ?>
                <div class="card">
                    <div class="card-header"><h2 class="card-title h6 mb-0">Cargar archivo</h2></div>
                    <div class="card-body">
                        <form method="post" action="<?= $escape($basePath) ?>/cartera/extractos/cargar" enctype="multipart/form-data">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <label class="form-label" for="statement-file">Extracto (Excel .xlsx, CSV o TXT · máx. 3 MB)</label>
                            <input class="form-control" type="file" id="statement-file" name="statement" accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                            <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i>Cargar y revisar</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card mt-3">
                <div class="card-header"><h2 class="card-title h6 mb-0">Importaciones recientes</h2></div>
                <div class="card-body p-0">
                    <?php if ($imports === []): ?>
                        <p class="text-secondary p-3 mb-0">Aún no se ha importado ningún extracto.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th scope="col">Archivo</th><th scope="col">Cargado</th><th scope="col" class="text-end">Ingresos</th><th scope="col">Resultado</th></tr></thead>
                                <tbody>
                                <?php foreach ($imports as $import): ?>
                                    <tr>
                                        <td><a href="<?= $escape($basePath) ?>/cartera/extractos/revisar?id=<?= (int) $import['id'] ?>"><?= $escape($import['file_name']) ?></a><div class="small text-secondary"><?= $escape($import['format']) ?></div></td>
                                        <td class="small"><?= $escape($dateTime($import['created_at'])) ?><?= $import['uploaded_by_name'] ? '<br>' . $escape($import['uploaded_by_name']) : '' ?></td>
                                        <td class="text-end small"><?= (int) $import['movements_count'] ?> · <?= $escape(BillingService::money(BillingService::moneyToCents($import['total_amount']))) ?></td>
                                        <td class="small">
                                            <?php if ($import['status'] === 'draft'): ?>
                                                <span class="badge text-bg-warning">Sin confirmar</span>
                                            <?php else: ?>
                                                <?= (int) $import['applied'] ?> pagos · <?= (int) $import['unidentified'] ?> por identificar · <?= (int) $import['omitted'] ?> omitidos<?= (int) $import['failed'] > 0 ? ' · <span class="text-danger">' . (int) $import['failed'] . ' con error</span>' : '' ?>
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
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header"><h2 class="card-title h6 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>¿Qué archivo cargo?</h2></div>
                <div class="card-body small">
                    <p><strong>AV Villas · movimientos de la cuenta.</strong> En la banca virtual de empresas, consulta los movimientos de la cuenta de la copropiedad, elige el rango de fechas y descárgalos en <strong>Excel</strong> o <strong>CSV</strong>. El extracto mensual en PDF no sirve porque no trae los movimientos en columnas.</p>
                    <p>El archivo debe tener una fila de títulos con la <strong>fecha</strong> y el <strong>valor</strong> (o columnas de crédito y débito). Si trae descripción, referencia o documento, mejor: allí aparece la referencia de pago que escriben los residentes.</p>
                    <p><strong>Archivo de recaudo (Asobancaria 2001).</strong> Si la copropiedad tiene un convenio de recaudo con referencia con el banco, carga el archivo .txt de recaudo: cada pago trae la referencia y se identifica solo.</p>
                    <p class="mb-0"><strong>Seguro de cargar dos veces:</strong> los movimientos ya importados se reconocen y se omiten. Los abonos de Bold, Wompi y otras pasarelas también se omiten, porque esos pagos ya quedaron registrados al pagar en línea.</p>
                </div>
            </div>
        </div>
    </div>
</section>
