<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$json = static fn (mixed $value): string => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: 'null';
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.conformacion', 2);
$housingLabels = ['casa' => 'Casas', 'torres_apartamento' => 'Torres de apartamentos', 'mixto' => 'Mixto'];
$housingLabel = $housingLabels[$housingType] ?? 'Copropiedad';
$answerLabels = [
    'houses' => 'Casas',
    'towers' => 'Torres',
    'floors_per_tower' => 'Pisos por torre',
    'units_per_floor' => 'Apartamentos por piso',
    'commercial_properties' => 'Inmuebles comerciales',
    'parking_spots' => 'Parqueaderos privados',
    'storage_units' => 'Bodegas privadas',
];
$billingLocked = $billingUnitIds !== [];
$categories = \App\Modules\Configuration\ConfigurationController::UNIT_CATEGORIES;
$currentStep = 2;
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Conformación de la copropiedad</h1>
            <p class="text-secondary mb-0">Paso 2 de 3 · Define los inmuebles de tipo <strong><?= $escape($housingLabel) ?></strong> y su coeficiente de copropiedad.</p>
        </div>
        <?php if ($mode === 'structure' && $completed): ?>
            <a class="btn btn-outline-primary" href="<?= $escape($basePath) ?>/inmuebles/asignaciones">Ir a asignación <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <?php endif; ?>
    </div>

    <?php
    // «Reiniciar» aparece en el paso 2 cuando ya hay algo guardado (respuestas o inmuebles).
    $conformationResetTarget = $canWrite && ($structureExists || $answers !== []) ? 'reset-composition-modal' : null;
    require __DIR__ . '/conformation-steps.php';
    ?>
    <?php if ($conformationResetTarget !== null): ?>
        <?php $isSuperadmin = \App\Core\Authorization::isSuperadmin($actor); ?>
        <dialog class="modal" id="reset-composition-modal" aria-labelledby="reset-composition-title">
            <div class="modal-dialog">
                <form class="modal-content" method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/reiniciar">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="reset-composition-title">Reiniciar la conformación</h2>
                        <button type="button" class="btn-close" command="close" commandfor="reset-composition-modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($billingLocked): ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
                                No se puede reiniciar: ya hay notas de cobro emitidas para estos inmuebles y su cartera quedaría desconectada.
                                Corrige, agrega o quita inmuebles directamente en la lista.
                            </div>
                        <?php elseif (!$isSuperadmin): ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                                Solo el perfil Superadmin puede reiniciar la conformación, porque borra toda la estructura.
                                Si solo necesitas ajustar algunos inmuebles, hazlo en la lista sin reiniciar.
                            </div>
                        <?php else: ?>
                            <p>Se volverá a empezar desde la primera pregunta del asistente. Se borrarán:</p>
                            <ul class="small">
                                <li>Las cantidades respondidas en el asistente.</li>
                                <?php if ($structureExists): ?>
                                    <li>Los <?= count($units) ?> inmuebles con sus nombres editados y coeficientes.</li>
                                <?php endif; ?>
                                <?php if ($assignmentCount > 0): ?>
                                    <li>Las <?= (int) $assignmentCount ?> asignaciones vigentes a usuarios (quedan cerradas en Trazabilidad).</li>
                                <?php endif; ?>
                            </ul>
                            <p class="small text-secondary">La caracterización (tipo de vivienda y nomenclatura) se conserva.</p>
                            <label for="reset-confirm-text" class="form-label small">Para continuar, escribe <strong>REINICIAR</strong></label>
                            <input id="reset-confirm-text" name="confirm_text" class="form-control" style="max-width: 14rem" autocomplete="off" spellcheck="false" required pattern="[Rr][Ee][Ii][Nn][Ii][Cc][Ii][Aa][Rr]">
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" command="close" commandfor="reset-composition-modal">Cancelar</button>
                        <?php if ($isSuperadmin && !$billingLocked): ?>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reiniciar conformación</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </dialog>
    <?php endif; ?>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($mode === 'wizard'): ?>
        <?php
        $step = $steps[$stepIndex];
        $maximum = $step['key'] === 'units_per_floor' ? 99 : 5000;
        $minimum = (int) ($step['min'] ?? 1);
        $editingExisting = $structureExists;
        ?>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="card-title h6 mb-0"><?= $editingExisting ? 'Cambiar una cantidad' : 'Asistente de conformación' ?></h2>
                        <span class="small text-secondary">Pregunta <?= $stepIndex + 1 ?> de <?= count($steps) ?></span>
                    </div>
                    <div class="card-body">
                        <progress class="app-progress mb-4" style="--progress-height: .5rem" aria-label="Progreso del asistente" value="<?= $progress ?>" max="100"></progress>
                        <?php if ($editingExisting && $billingLocked): ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
                                Las cantidades no se pueden cambiar porque ya hay notas de cobro emitidas: regenerar la estructura desconectaría la cartera.
                                Agrega o corrige inmuebles directamente en la <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/conformacion">lista de inmuebles</a>.
                            </div>
                        <?php elseif ($canWrite): ?>
                            <form id="composition-step-form" method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/paso">
                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="field" value="<?= $escape($step['key']) ?>">
                                <label for="composition-value" class="form-label fs-5 fw-semibold"><?= $escape($step['label']) ?></label>
                                <p class="text-secondary"><?= $escape($step['help']) ?></p>
                                <div class="input-group mb-2" style="max-width: 16rem">
                                    <button class="btn btn-outline-secondary" type="button" data-step="-1" aria-label="Restar uno">−</button>
                                    <input id="composition-value" name="value" class="form-control text-center fs-5" type="number" inputmode="numeric" min="<?= $minimum ?>" max="<?= $maximum ?>" step="1" required autofocus
                                           value="<?= $escape($answers[$step['key']] ?? '') ?>" aria-describedby="composition-limit">
                                    <button class="btn btn-outline-secondary" type="button" data-step="1" aria-label="Sumar uno">+</button>
                                </div>
                                <div id="composition-limit" class="form-text mb-3">
                                    <?= $step['key'] === 'units_per_floor'
                                        ? 'Entre 1 y 99 apartamentos por piso.'
                                        : 'Número entero entre ' . $minimum . ' y 5.000.' ?>
                                </div>

                                <?php if ($editingExisting): ?>
                                    <div id="regenerate-warning" class="border border-danger rounded p-3 mb-3" hidden>
                                        <p class="text-danger fw-semibold mb-2"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Cambiar esta cantidad regenera todos los inmuebles</p>
                                        <p class="small mb-2">Se perderán los <?= count($units) ?> inmuebles actuales con sus nombres editados y coeficientes<?= $assignmentCount > 0 ? ', y las ' . (int) $assignmentCount . ' asignaciones a usuarios' : '' ?>.
                                            Si solo necesitas agregar o quitar algunos inmuebles, hazlo en la lista sin cambiar esta cantidad.</p>
                                        <label for="confirm-text" class="form-label small">Para continuar, escribe <strong>REINICIAR</strong></label>
                                        <input id="confirm-text" name="confirm_text" class="form-control form-control-sm" style="max-width: 14rem" autocomplete="off" spellcheck="false">
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <?php if ($editingExisting): ?>
                                        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/conformacion">Cancelar y volver a la lista</a>
                                    <?php elseif ($stepIndex > 0): ?>
                                        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/conformacion?paso=<?= $stepIndex - 1 ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Anterior</a>
                                    <?php else: ?>
                                        <span></span>
                                    <?php endif; ?>
                                    <button id="composition-step-submit" class="btn btn-primary" type="submit">
                                        <?= $editingExisting ? 'Guardar cantidad' : ($stepIndex + 1 === count($steps) ? 'Guardar y revisar' : 'Siguiente') ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="fw-semibold mb-1"><?= $escape($step['label']) ?></p>
                            <p class="mb-0">Cantidad registrada: <strong><?= $escape($answers[$step['key']] ?? 'Pendiente') ?></strong></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">Resumen</h2></div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($steps as $index => $summaryStep): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center<?= $index === $stepIndex ? ' bg-primary-subtle' : '' ?>">
                                <span><?= $escape($answerLabels[$summaryStep['key']] ?? $summaryStep['label']) ?></span>
                                <strong data-answer="<?= $escape($summaryStep['key']) ?>"><?= array_key_exists($summaryStep['key'], $answers) ? $escape($answers[$summaryStep['key']]) : '—' ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="card-body">
                        <div class="small text-secondary">Inmuebles que se generarán</div>
                        <div id="estimated-total" class="h3 mb-1">—</div>
                        <div id="estimated-detail" class="small text-secondary"></div>
                    </div>
                </div>
            </div>
        </div>
        <script nonce="<?= \App\Core\Csp::nonce() ?>">
        (() => {
            const housing = <?= $json($housingType) ?>;
            const answers = <?= $json((object) $answers) ?>;
            const field = <?= $json($step['key']) ?>;
            const input = document.getElementById('composition-value');
            const total = document.getElementById('estimated-total');
            const detail = document.getElementById('estimated-detail');
            const warning = document.getElementById('regenerate-warning');
            const confirmInput = document.getElementById('confirm-text');
            const submit = document.getElementById('composition-step-submit');
            const original = answers[field] ?? null;
            const format = (n) => n.toLocaleString('es-CO');
            const update = () => {
                const value = parseInt(input?.value ?? '', 10);
                const minimum = parseInt(input?.min ?? '1', 10);
                const valid = Number.isInteger(value) && value >= minimum;
                const a = { ...answers };
                if (valid) a[field] = value;
                const shown = document.querySelector(`[data-answer="${field}"]`);
                if (shown && input) shown.textContent = valid ? value : '—';
                const extras = (a.parking_spots || 0) + (a.storage_units || 0);
                const extrasText = extras > 0
                    ? ` + ${format(a.parking_spots || 0)} parqueaderos + ${format(a.storage_units || 0)} bodegas`
                    : '';
                let count = null;
                if (housing === 'casa' && a.houses) {
                    count = a.houses + extras;
                    detail.textContent = `Casa 1 … Casa ${a.houses}${extrasText}`;
                } else if (a.towers && a.floors_per_tower && a.units_per_floor) {
                    const apartments = a.towers * a.floors_per_tower * a.units_per_floor;
                    count = apartments + (housing === 'mixto' ? (a.commercial_properties || 0) : 0) + extras;
                    detail.textContent = `${format(apartments)} apartamentos (${a.towers} × ${a.floors_per_tower} × ${a.units_per_floor})`
                        + (housing === 'mixto' ? ` + ${format(a.commercial_properties || 0)} comerciales` : '')
                        + extrasText;
                } else {
                    detail.textContent = 'Se calcula al completar todas las preguntas.';
                }
                total.textContent = count === null ? '—' : format(count);
                total.classList.toggle('text-danger', count !== null && count > 5000);
                if (count !== null && count > 5000) detail.textContent += ' · Supera el máximo de 5.000.';
                if (warning && input) {
                    const changed = Number.isInteger(value) && value !== original;
                    warning.hidden = !changed;
                    confirmInput.required = changed;
                    submit.disabled = changed && confirmInput.value.trim().toUpperCase() !== 'REINICIAR';
                    submit.classList.toggle('btn-danger', changed);
                }
            };
            document.querySelectorAll('[data-step]').forEach((button) => button.addEventListener('click', () => {
                const max = parseInt(input.max, 10);
                const min = parseInt(input.min, 10) || 0;
                const next = Math.min(max, Math.max(min, (parseInt(input.value, 10) || 0) + parseInt(button.dataset.step, 10)));
                input.value = next;
                update();
            }));
            input?.addEventListener('input', update);
            confirmInput?.addEventListener('input', update);
            update();
        })();
        </script>

    <?php elseif ($mode === 'answers_review'): ?>
        <?php
        $exampleNames = $housingType === 'casa'
            ? ['Casa 1', 'Casa 2', '…', 'Casa ' . ($answers['houses'] ?? '')]
            : [
                ...\App\Modules\Configuration\UnitNomenclature::examples(
                    $towerNomenclature,
                    $apartmentNomenclature,
                    (int) ($answers['units_per_floor'] ?? 2)
                ),
                '…',
            ];
        if ($housingType === 'mixto') {
            $exampleNames[] = 'Inmueble comercial 1';
        }
        if (($answers['parking_spots'] ?? 0) > 0) {
            $exampleNames[] = 'Parqueadero 1';
        }
        if (($answers['storage_units'] ?? 0) > 0) {
            $exampleNames[] = 'Bodega 1';
        }
        ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">Revisa antes de generar</h2></div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <ul class="list-group">
                            <?php foreach ($steps as $index => $reviewStep): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= $escape($answerLabels[$reviewStep['key']] ?? $reviewStep['label']) ?></span>
                                    <span>
                                        <strong><?= $escape($answers[$reviewStep['key']] ?? '') ?></strong>
                                        <?php if ($canWrite): ?>
                                            <a class="ms-2 small" href="<?= $escape($basePath) ?>/configuracion/conformacion?paso=<?= $index ?>">Cambiar</a>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                            <li class="list-group-item d-flex justify-content-between bg-body-tertiary">
                                <strong>Total de inmuebles</strong><strong><?= $escape(number_format((int) $estimatedUnitCount, 0, ',', '.')) ?></strong>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <p class="small text-secondary mb-2">Así se verán los nombres (podrás editarlos después):</p>
                        <div class="bg-body-tertiary rounded p-3 font-monospace small">
                            <?php foreach ($exampleNames as $exampleName): ?><div><?= $escape($exampleName) ?></div><?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($canWrite): ?>
                <div class="card-footer bg-transparent d-flex justify-content-end">
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/generar">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button class="btn btn-primary" type="submit">Generar la lista de inmuebles <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php if ($restoredDraft): ?>
            <div class="alert alert-warning" role="status"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Se recuperaron los cambios que escribiste. Corrige los errores marcados y vuelve a guardar.</div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center small">
                <span class="text-secondary">Generada con:</span>
                <?php foreach ($steps as $index => $reviewStep): ?>
                    <span class="badge rounded-pill text-bg-light border fw-normal"><?= $escape($answerLabels[$reviewStep['key']] ?? $reviewStep['label']) ?>: <strong><?= $escape($answers[$reviewStep['key']] ?? '—') ?></strong></span>
                <?php endforeach; ?>
                <?php if ($billingLocked): ?>
                    <span class="ms-auto text-secondary"><i class="bi bi-lock" aria-hidden="true"></i> Cantidades bloqueadas: hay notas de cobro emitidas</span>
                <?php elseif (\App\Core\Authorization::isSuperadmin($actor)): ?>
                    <a class="ms-auto" href="<?= $escape($basePath) ?>/configuracion/conformacion?paso=0">Cambiar cantidades…</a>
                <?php elseif ($canWrite): ?>
                    <span class="ms-auto text-secondary" title="Cambiar una cantidad regenera toda la estructura"><i class="bi bi-shield-lock" aria-hidden="true"></i> Solo Superadmin puede cambiar las cantidades</span>
                <?php endif; ?>
            </div>
        </div>

        <script src="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/js/data-grid.js?v=1"></script>
        <?php
        // Desglose del coeficiente por categoría (Apartamento, Parqueadero, Inmueble comercial…). Se dibuja igual en la
        // vista editable y en la de solo lectura; en la editable se recalcula mientras se escribe.
        $feeSettings = is_array($feeSettings ?? null) ? $feeSettings : ['amount' => null, 'basis' => 'base'];
        $breakdownCard = static function (bool $editable) use ($escape, $feeSettings): void { ?>
            <div class="card border-0 shadow-sm mb-3" id="category-breakdown">
                <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
                    <h2 class="card-title h6 mb-0 me-auto"><i class="bi bi-pie-chart me-1" aria-hidden="true"></i>Coeficiente por categoría</h2>
                    <span class="small text-secondary">
                        <?php if ($feeSettings['amount'] !== null): ?>
                            Cuota estimada con <?= $feeSettings['basis'] === 'budget' ? 'el presupuesto mensual' : 'la base mensual' ?> de Configuración / Financiera.
                        <?php else: ?>
                            Configura la cuota en Configuración / Financiera para ver cuánto aporta cada categoría.
                        <?php endif; ?>
                    </span>
                </div>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-configuration-composition-1">
                    <table class="table table-sm align-middle mb-0">
                        <caption id="tbl-configuration-composition-1" class="visually-hidden">Coeficiente por categoría</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Categoría</th>
                                <th scope="col" class="text-end">Inmuebles</th>
                                <th scope="col" class="text-end">Suma del coeficiente</th>
                                <th scope="col" style="min-width: 9rem">Participación</th>
                                <th scope="col" class="text-end">Coeficiente por inmueble</th>
                                <?php if ($feeSettings['amount'] !== null): ?><th scope="col" class="text-end">Cuota mensual ≈</th><?php endif; ?>
                                <th scope="col" class="text-end"><span class="visually-hidden">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody id="category-breakdown-body"></tbody>
                        <tfoot class="table-light fw-semibold" id="category-breakdown-foot"></tfoot>
                    </table>
                </div>
                <?php if ($editable): ?>
                    <div class="card-footer bg-transparent small text-secondary">
                        «Seleccionar» marca todos los inmuebles de la categoría en la lista: luego usa <em>Asignar coeficiente</em> o <em>Repartir un total</em> para fijar su valor de una vez.
                    </div>
                <?php endif; ?>
            </div>
        <?php };
        ?>
        <script nonce="<?= \App\Core\Csp::nonce() ?>">
        // Cálculo del desglose: entradas [{ category, value }] con value en diezmilésimas (1 % = 10000) o null.
        window.CompositionBreakdown = (() => {
            const SCALE = 10000;
            const fee = <?= json_encode($feeSettings['amount'] !== null ? (float) $feeSettings['amount'] : null) ?>;
            const basis = <?= json_encode($feeSettings['basis']) ?>;
            const percent = (value) => (value / SCALE).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
            const money = (value) => '$ ' + Math.round(value).toLocaleString('es-CO');
            const cell = (text, className = '') => Object.assign(document.createElement('td'), { className, textContent: text });
            return {
                render(entries, { onView, onSelect } = {}) {
                    const body = document.getElementById('category-breakdown-body');
                    const foot = document.getElementById('category-breakdown-foot');
                    if (!body) return;
                    const groups = new Map();
                    entries.forEach(({ category, value }) => {
                        const key = category.trim() || 'Sin categoría';
                        const group = groups.get(key) || { count: 0, sum: 0, missing: 0, min: null, max: null };
                        group.count++;
                        if (value === null) {
                            group.missing++;
                        } else {
                            group.sum += value;
                            group.min = group.min === null ? value : Math.min(group.min, value);
                            group.max = group.max === null ? value : Math.max(group.max, value);
                        }
                        groups.set(key, group);
                    });
                    const total = [...groups.values()].reduce((sum, group) => sum + group.sum, 0);
                    const monthly = (sum) => fee === null ? null : (basis === 'budget' ? (total > 0 ? fee * sum / total : 0) : fee * sum / (100 * SCALE));
                    const rows = [...groups.entries()].sort((a, b) => b[1].sum - a[1].sum || a[0].localeCompare(b[0], 'es'));
                    body.replaceChildren(...rows.map(([category, group]) => {
                        const tr = document.createElement('tr');
                        const share = total > 0 ? (group.sum / total) * 100 : 0;
                        const shareCell = document.createElement('td');
                        const shareText = `${share.toLocaleString('es-CO', { maximumFractionDigits: 2 })} %`;
                        const shareBar = Object.assign(document.createElement('progress'), { className: 'app-progress flex-grow-1', max: 100, value: share });
                        shareBar.style.setProperty('--progress-height', '.45rem');
                        shareBar.setAttribute('aria-label', `Participación: ${shareText}`);
                        const shareLabel = Object.assign(document.createElement('span'), { className: 'small text-secondary text-nowrap', textContent: shareText });
                        const shareWrap = Object.assign(document.createElement('div'), { className: 'd-flex align-items-center gap-2' });
                        shareWrap.append(shareBar, shareLabel);
                        shareCell.append(shareWrap);
                        const perUnit = group.min === null ? '—'
                            : group.min === group.max ? `${percent(group.min)} % c/u` : `${percent(group.min)} – ${percent(group.max)} %`;
                        const perUnitCell = cell(perUnit, 'text-end text-nowrap');
                        if (group.missing > 0) {
                            const warn = Object.assign(document.createElement('span'), { className: 'd-block small text-warning', textContent: `${group.missing} sin coeficiente` });
                            perUnitCell.append(warn);
                        }
                        tr.append(cell(category, 'fw-semibold'), cell(group.count.toLocaleString('es-CO'), 'text-end'), cell(`${percent(group.sum)} %`, 'text-end text-nowrap'), shareCell, perUnitCell);
                        if (fee !== null) tr.append(cell(money(monthly(group.sum)), 'text-end text-nowrap'));
                        const actions = cell('', 'text-end text-nowrap');
                        const button = (label, icon, handler) => {
                            const b = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn-link btn-sm p-0 ms-2' });
                            b.innerHTML = `<i class="bi ${icon} me-1" aria-hidden="true"></i>`;
                            b.append(label);
                            b.setAttribute('aria-label', `${label} ${category}`);
                            b.addEventListener('click', () => handler(category));
                            return b;
                        };
                        if (onView) actions.append(button('Ver', 'bi-funnel', onView));
                        if (onSelect) actions.append(button('Seleccionar', 'bi-check2-square', onSelect));
                        tr.append(actions);
                        return tr;
                    }));
                    const units = entries.length;
                    const totalRow = document.createElement('tr');
                    totalRow.append(cell('Total'), cell(units.toLocaleString('es-CO'), 'text-end'), cell(`${percent(total)} %`, 'text-end text-nowrap'), cell(''), cell(''));
                    if (fee !== null) totalRow.append(cell(money(groups.size ? monthly(total) : 0), 'text-end text-nowrap'));
                    totalRow.append(cell(''));
                    foot.replaceChildren(totalRow);
                },
                // Opciones del filtro por categoría, conservando la elegida.
                fillFilter(select, categories) {
                    if (!select) return;
                    const current = select.value;
                    const sorted = [...new Set(categories.map((c) => c.trim() || 'Sin categoría'))].sort((a, b) => a.localeCompare(b, 'es'));
                    select.replaceChildren(new Option('Todas las categorías', ''), ...sorted.map((c) => new Option(c, c)));
                    select.value = sorted.includes(current) ? current : '';
                },
            };
        })();
        </script>
        <?php if ($canWrite): ?>
            <form id="composition-units-form" method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/finalizar" novalidate autocomplete="off">
                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                <input id="composition-units-json" type="hidden" name="units_json" value="">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <div class="small text-secondary">Inmuebles</div>
                            <div class="h4 mb-0" id="units-total"><?= count($units) ?></div>
                            <div class="small text-secondary" id="units-by-category"></div>
                        </div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <div class="d-flex justify-content-between"><span class="small text-secondary">Suma de coeficientes</span><span id="coefficient-badge" class="badge"></span></div>
                            <div class="h4 mb-1"><span id="coefficient-total">0</span> %</div>
                            <progress id="coefficient-bar" class="app-progress" aria-label="Suma de coeficientes" value="0" max="100"></progress>
                            <div class="small text-secondary mt-1" id="coefficient-hint">Deben sumar 100 %.</div>
                        </div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <div class="small text-secondary">Revisión</div>
                            <div class="h4 mb-0" id="error-total">0</div>
                            <div class="small text-secondary">fila(s) con algo por corregir</div>
                        </div></div>
                    </div>
                </div>

                <?php $breakdownCard(true); ?>

                <div id="error-summary" class="alert alert-danger" hidden role="alert">
                    <strong>Corrige estos datos antes de guardar:</strong>
                    <ul id="error-list" class="mb-0 mt-2"></ul>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
                        <h2 class="card-title h6 mb-0 me-auto">Lista de inmuebles</h2>
                        <input id="unit-filter" class="form-control form-control-sm" style="max-width: 16rem" type="search" placeholder="Filtrar: Torre 2, comercial…" aria-label="Filtrar inmuebles">
                        <select id="unit-category-filter" class="form-select form-select-sm w-auto" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option></select>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Mostrar">
                            <input class="btn-check" type="radio" name="row-view" id="view-all" value="all" checked><label class="btn btn-outline-secondary" for="view-all">Todos</label>
                            <input class="btn-check" type="radio" name="row-view" id="view-errors" value="errors"><label class="btn btn-outline-secondary" for="view-errors">Con errores</label>
                        </div>
                        <?php
                        // Menú «Coeficientes»: cada opción actúa sobre las filas filtradas (todas las páginas).
                        $magicItem = static function (string $id, string $icon, string $tone, string $title, string $descriptionId, string $extra = '') use ($escape): void { ?>
                            <li>
                                <button class="dropdown-item d-flex gap-3 align-items-start py-2 text-wrap" type="button" id="<?= $escape($id) ?>" <?= $extra ?>>
                                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 bg-<?= $escape($tone) ?>-subtle text-<?= $escape($tone) ?>" style="width: 2.1rem; height: 2.1rem" aria-hidden="true"><i class="bi <?= $escape($icon) ?>"></i></span>
                                    <span class="lh-sm">
                                        <span class="d-block fw-semibold"><?= $escape($title) ?></span>
                                        <span class="d-block small text-secondary mt-1" id="<?= $escape($descriptionId) ?>"></span>
                                    </span>
                                </button>
                            </li>
                        <?php }; ?>
                        <div class="dropdown" id="magic-menu">
                            <button popovertarget="magic-menu-list" class="btn btn-sm btn-primary dropdown-toggle d-inline-flex align-items-center gap-1" type="button" id="magic-button">
                                <i class="bi bi-magic" aria-hidden="true"></i>Coeficientes
                                <span class="badge rounded-pill text-bg-light" id="magic-badge" title="Inmuebles a los que se aplica"></span>
                            </button>
                            <ul id="magic-menu-list" popover class="dropdown-menu dropdown-menu-end shadow border-0 p-0 overflow-hidden" style="width: min(25rem, 92vw); z-index: 1030" aria-labelledby="magic-button">
                                <li class="px-3 py-2 bg-primary-subtle border-bottom">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-bullseye text-primary" aria-hidden="true"></i>
                                        <span class="small">Se aplica a <strong id="magic-scope-count">0</strong> inmueble(s)</span>
                                    </div>
                                    <div class="small text-secondary mt-1" id="magic-scope-detail"></div>
                                    <div class="row g-2 small mt-1">
                                        <div class="col-6"><span class="text-secondary d-block">Suman ahora</span><strong id="magic-scope-sum">0 %</strong></div>
                                        <div class="col-6"><span class="text-secondary d-block">Sin coeficiente</span><strong id="magic-scope-missing">0</strong></div>
                                    </div>
                                </li>
                                <?php $magicItem('magic-assign', 'bi-123', 'primary', 'Mismo coeficiente a cada uno', 'magic-assign-text'); ?>
                                <?php $magicItem('magic-distribute', 'bi-distribute-vertical', 'primary', 'Repartir un total en partes iguales', 'magic-distribute-text'); ?>
                                <?php $magicItem('magic-fill', 'bi-puzzle', 'success', 'Completar lo que falta', 'magic-fill-text'); ?>
                                <?php $magicItem('magic-paste', 'bi-clipboard', 'secondary', 'Pegar una columna desde Excel', 'magic-paste-text', 'command="show-modal" commandfor="paste-modal"'); ?>
                                <li><hr class="dropdown-divider my-0"></li>
                                <?php $magicItem('clear-coefficients', 'bi-eraser', 'danger', 'Borrar coeficientes', 'magic-clear-text'); ?>
                                <li class="px-3 py-2 bg-body-tertiary border-top small text-secondary">
                                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Usa la búsqueda, la categoría o «Con errores» para elegir a cuáles se aplica.
                                </li>
                            </ul>
                        </div>
                        <div id="unit-grid-tools" class="d-flex flex-wrap gap-2"></div>
                    </div>
                    <div id="unit-grid-bulk" class="px-3 py-2 bg-primary-subtle border-bottom" hidden></div>
                    <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-configuration-composition-2" style="max-height: 62vh">
                        <table id="composition-unit-table" class="table table-sm table-hover align-middle mb-0">
                            <caption id="tbl-configuration-composition-2" class="visually-hidden">Lista de inmuebles</caption>
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th scope="col" class="text-secondary" style="width: 4rem" data-column="position" data-label="#" data-sortable>#</th>
                                    <th scope="col" style="width: 22%" data-column="category" data-label="Categoría" data-sortable>Categoría</th>
                                    <th scope="col" data-column="name" data-label="Nombre del inmueble" data-hideable="false" data-sortable>Nombre del inmueble</th>
                                    <th scope="col" style="width: 11rem" data-column="coefficient" data-label="Coeficiente (%)" data-sortable>Coeficiente (%)</th>
                                    <th scope="col" style="width: 3rem" data-export="false"><span class="visually-hidden">Acciones</span></th>
                                </tr>
                            </thead>
                            <tbody id="composition-unit-list">
                                <?php foreach ($units as $index => $unit): ?>
                                    <?php $unitId = (string) ($unit['id'] ?? ''); $hasHistory = isset($billingUnitIds[$unitId]); ?>
                                    <tr class="composition-unit" data-unit-id="<?= $escape($unitId) ?>" data-locked="<?= $hasHistory ? '1' : '0' ?>">
                                        <td class="text-secondary small row-number"><?= $index + 1 ?></td>
                                        <td><input data-unit-field="category" class="form-control form-control-sm" list="unit-categories" maxlength="80" value="<?= $escape($unit['category'] ?? '') ?>" aria-label="Categoría"></td>
                                        <td><input data-unit-field="name" class="form-control form-control-sm" maxlength="160" value="<?= $escape($unit['name'] ?? '') ?>" aria-label="Nombre del inmueble"></td>
                                        <td><input data-unit-field="coefficient" class="form-control form-control-sm text-end" inputmode="decimal" value="<?= $escape($unit['coefficient'] ?? '') ?>" placeholder="—" aria-label="Coeficiente"></td>
                                        <td class="text-end">
                                            <?php if ($hasHistory): ?>
                                                <span class="text-secondary" title="Tiene notas de cobro: no se puede quitar"><i class="bi bi-lock" aria-hidden="true"></i><span class="visually-hidden">Tiene notas de cobro</span></span>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-link text-danger p-0 remove-composition-unit" type="button" title="Quitar inmueble" aria-label="Quitar inmueble"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="unit-grid-pager" class="px-3 py-2 border-top"></div>
                    <div class="card-footer bg-transparent d-flex flex-wrap gap-2 align-items-center">
                        <button id="add-composition-unit" class="btn btn-outline-primary btn-sm" type="button"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Agregar inmueble</button>
                        <span id="dirty-indicator" class="small text-warning ms-2" hidden><i class="bi bi-circle-fill me-1" style="font-size: .5rem" aria-hidden="true"></i>Cambios sin guardar</span>
                        <button id="composition-submit" class="btn btn-primary ms-auto" type="submit"><?= $completed ? 'Guardar cambios' : 'Guardar estructura' ?></button>
                    </div>
                </div>
                <datalist id="unit-categories">
                    <?php foreach ($categories as $category): ?><option value="<?= $escape($category) ?>"></option><?php endforeach; ?>
                </datalist>
            </form>

            <dialog class="modal" id="paste-modal" aria-labelledby="paste-modal-title">
                <div class="modal-dialog modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="paste-modal-title">Pegar coeficientes desde Excel</h2>
                            <button type="button" class="btn-close" command="close" commandfor="paste-modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small">Copia la columna de coeficientes del reglamento o de Excel (un valor por línea) y pégala aquí. Se aplicarán <strong>en orden a las filas filtradas</strong> (todas las páginas, en el orden en que se ven); filtra primero si quieres llenar solo una torre.</p>
                            <p class="small text-secondary">Se aceptan comas o puntos decimales y el signo %: <code>1,2500</code>, <code>1.25</code>, <code>0,85%</code>.</p>
                            <textarea id="paste-values" class="form-control font-monospace" rows="10" aria-describedby="paste-status"></textarea>
                            <div id="paste-status" class="small mt-2" aria-live="polite"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" command="close" commandfor="paste-modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="apply-paste" disabled>Aplicar coeficientes</button>
                        </div>
                    </div>
                </div>
            </dialog>

            <dialog class="modal" id="bulk-value-modal" aria-labelledby="bulk-value-title">
                <div class="modal-dialog">
                    <form class="modal-content" id="bulk-value-form" novalidate>
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="bulk-value-title"></h2>
                            <button type="button" class="btn-close" command="close" commandfor="bulk-value-modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label" for="bulk-value-input" id="bulk-value-label"></label>
                            <input class="form-control" id="bulk-value-input" autocomplete="off" aria-describedby="bulk-value-hint">
                            <div class="invalid-feedback" id="bulk-value-error"></div>
                            <div class="form-text" id="bulk-value-hint"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" command="close" commandfor="bulk-value-modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Aplicar</button>
                        </div>
                    </form>
                </div>
            </dialog>

            <script nonce="<?= \App\Core\Csp::nonce() ?>">
            (() => {
                const SCALE = 10000; // los coeficientes se manejan en diezmilésimas para evitar errores de redondeo
                const TOLERANCE = 100; // ±0,01 %
                const form = document.getElementById('composition-units-form');
                const list = document.getElementById('composition-unit-list');
                const filter = document.getElementById('unit-filter');
                const submit = document.getElementById('composition-submit');
                const dirtyIndicator = document.getElementById('dirty-indicator');
                const field = (row, name) => row.querySelector(`[data-unit-field="${name}"]`);
                // Al volver con «Atrás» o recargar, el navegador puede rellenar los campos con lo que se escribió
                // antes sin guardar. La tabla debe mostrar lo guardado (lo que usa Cartera): se descarta lo rellenado.
                let discardedTotal = 0;
                const discardRestoredValues = () => {
                    let discarded = 0;
                    list.querySelectorAll('[data-unit-field]').forEach((input) => {
                        if (input.value !== input.defaultValue) {
                            input.value = input.defaultValue;
                            discarded++;
                        }
                    });
                    if (discarded > 0) {
                        discardedTotal += discarded;
                        let notice = document.getElementById('discarded-notice');
                        if (!notice) {
                            notice = document.createElement('div');
                            notice.id = 'discarded-notice';
                            notice.className = 'alert alert-info small d-flex gap-2 align-items-start';
                            notice.setAttribute('role', 'status');
                            form.prepend(notice);
                        }
                        notice.innerHTML = '<i class="bi bi-info-circle mt-1" aria-hidden="true"></i>';
                        notice.append(`Se descartaron ${discardedTotal} valor(es) escritos en una visita anterior que no se guardaron. La tabla muestra lo guardado, que es lo que usa Cartera.`);
                    }
                    return discarded;
                };
                discardRestoredValues();
                window.addEventListener('pageshow', (event) => {
                    if (event.persisted) {
                        location.reload(); // copia guardada por «Atrás»: se pide la estructura vigente
                    } else if (!dirty && discardRestoredValues() > 0) {
                        validate();
                        grid.refresh();
                    }
                });
                let dirty = false;
                let grid = null;
                // Orden original de la estructura (el orden visual puede cambiar al ordenar por columna).
                const rows = () => grid.rowsInOrder();
                // Filas que coinciden con el filtro, en todas las páginas y en el orden en que se ven.
                const filteredRows = () => grid.matching();

                // "1,25 %" → 12500 diezmilésimas; null si no es válido.
                const parseCoefficient = (raw) => {
                    const text = String(raw).trim().replace('%', '').replace(/\s/g, '').replace(',', '.');
                    const match = /^(\d{1,3})(?:\.(\d{1,4}))?$/.exec(text);
                    if (!match) return null;
                    const value = parseInt(match[1], 10) * SCALE + parseInt((match[2] || '').padEnd(4, '0'), 10);
                    return value >= 0 && value <= 100 * SCALE ? value : null; // 0 % es válido (no paga cuota)
                };
                const formatCoefficient = (value) => `${Math.floor(value / SCALE)}.${String(value % SCALE).padStart(4, '0')}`;
                const formatPercent = (value) => (value / SCALE).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
                const apartmentPattern = /(?:^| - )Apartamento [0-9A-Za-z][0-9A-Za-z-]{0,9}$/;
                const knownCategories = <?= $json($categories) ?>;

                const markDirty = () => {
                    dirty = true;
                    dirtyIndicator.hidden = false;
                };

                const validate = () => {
                    const all = rows();
                    const names = new Map();
                    const errors = [];
                    const byCategory = {};
                    let total = 0;
                    all.forEach((row, index) => {
                        row.querySelector('.row-number').textContent = index + 1;
                        const category = field(row, 'category');
                        const name = field(row, 'name');
                        const coefficient = field(row, 'coefficient');
                        const rowErrors = [];
                        const nameValue = name.value.trim().replace(/\s+/g, ' ');
                        const known = knownCategories.find((c) => c.toLowerCase() === category.value.trim().toLowerCase());
                        const categoryValue = known ?? category.value.trim();

                        const categoryOk = categoryValue !== '';
                        if (!categoryOk) rowErrors.push('falta la categoría');
                        let nameOk = nameValue !== '';
                        if (!nameOk) rowErrors.push('falta el nombre');
                        const key = nameValue.toLowerCase();
                        if (nameOk && names.has(key)) {
                            nameOk = false;
                            rowErrors.push(`nombre repetido (fila ${names.get(key) + 1})`);
                        } else if (nameOk) {
                            names.set(key, index);
                        }
                        if (nameOk && categoryValue === 'Apartamento' && !apartmentPattern.test(nameValue)) {
                            nameOk = false;
                            rowErrors.push('debe terminar en «Apartamento» y su número (ej. Apartamento 101)');
                        }
                        const parsed = parseCoefficient(coefficient.value);
                        const coefficientOk = parsed !== null;
                        if (!coefficientOk) rowErrors.push(coefficient.value.trim() === '' ? 'falta el coeficiente' : 'coeficiente inválido (entre 0 y 100, 4 decimales)');
                        else total += parsed;

                        const show = form.dataset.showErrors === '1' || row.dataset.touched === '1';
                        category.classList.toggle('is-invalid', show && !categoryOk);
                        name.classList.toggle('is-invalid', show && !nameOk);
                        coefficient.classList.toggle('is-invalid', show && !coefficientOk);
                        row.dataset.hasError = rowErrors.length > 0 ? '1' : '0';
                        if (rowErrors.length > 0) errors.push({ row, index, label: nameValue || `Fila ${index + 1}`, text: rowErrors.join(', ') });
                        if (categoryOk) byCategory[categoryValue] = (byCategory[categoryValue] || 0) + 1;
                    });

                    document.getElementById('units-total').textContent = all.length.toLocaleString('es-CO');
                    document.getElementById('units-by-category').textContent = Object.entries(byCategory).map(([c, n]) => `${n} ${c}`).join(' · ');
                    document.getElementById('error-total').textContent = errors.length;
                    document.getElementById('error-total').classList.toggle('text-danger', errors.length > 0);

                    const diff = total - 100 * SCALE;
                    const sumOk = Math.abs(diff) <= TOLERANCE;
                    const badge = document.getElementById('coefficient-badge');
                    const bar = document.getElementById('coefficient-bar');
                    document.getElementById('coefficient-total').textContent = formatPercent(total);
                    bar.value = Math.min(100, total / SCALE);
                    bar.className = `app-progress ${sumOk ? 'is-success' : diff > 0 ? 'is-danger' : 'is-warning'}`;
                    badge.className = `badge ${sumOk ? 'text-bg-success' : 'text-bg-warning'}`;
                    badge.textContent = sumOk ? 'Correcto' : diff > 0 ? 'Sobra' : 'Falta';
                    document.getElementById('coefficient-hint').textContent = sumOk
                        ? 'La suma es 100 %. La cuota se reparte completa.'
                        : `${diff > 0 ? 'Sobran' : 'Faltan'} ${formatPercent(Math.abs(diff))} puntos para llegar a 100 %.`;

                    const summary = document.getElementById('error-summary');
                    const errorList = document.getElementById('error-list');
                    errorList.replaceChildren();
                    errors.slice(0, 8).forEach((error) => {
                        const item = document.createElement('li');
                        const link = document.createElement('button');
                        link.type = 'button';
                        link.className = 'btn btn-link btn-sm p-0 align-baseline';
                        link.textContent = `Fila ${error.index + 1} · ${error.label}`;
                        link.addEventListener('click', () => {
                            filter.value = '';
                            document.getElementById('view-all').checked = true;
                            applyFilter();
                            grid.showRow(error.row);
                            error.row.querySelector('.is-invalid')?.focus();
                        });
                        item.append(link, `: ${error.text}`);
                        errorList.append(item);
                    });
                    if (errors.length > 8) {
                        const more = document.createElement('li');
                        more.textContent = `y ${errors.length - 8} más (usa «Con errores» para verlas).`;
                        errorList.append(more);
                    }
                    summary.hidden = errors.length === 0 || !form.dataset.showErrors;
                    submit.disabled = all.length === 0;
                    if (grid) { renderBreakdown(); updateMagicMenu(); } // grid existe desde el arranque; validate() solo corre después
                    return { errors, sumOk, total };
                };

                let validateTimer;
                const scheduleValidate = () => {
                    clearTimeout(validateTimer);
                    validateTimer = setTimeout(validate, 120);
                };

                const categoryFilter = document.getElementById('unit-category-filter');
                const rowCategory = (row) => field(row, 'category').value.trim() || 'Sin categoría';
                const matchesFilter = (row) => {
                    const query = filter.value.trim().toLowerCase();
                    const onlyErrors = document.getElementById('view-errors').checked;
                    const text = `${field(row, 'category').value} ${field(row, 'name').value}`.toLowerCase();
                    return (query === '' || text.includes(query)) && (!onlyErrors || row.dataset.hasError === '1')
                        && (categoryFilter.value === '' || rowCategory(row) === categoryFilter.value);
                };
                categoryFilter.addEventListener('change', () => applyFilter());
                // Desglose por categoría: «Ver» filtra la lista; «Seleccionar» marca la categoría para las acciones en lote.
                const showCategory = (category) => {
                    categoryFilter.value = category;
                    applyFilter();
                    document.getElementById('composition-unit-table').scrollIntoView({ block: 'start', behavior: 'smooth' });
                };
                const selectCategory = (category) => {
                    grid.clearSelection();
                    rows().filter((row) => rowCategory(row) === category).forEach((row) => grid.setSelected(row, true));
                    showCategory(category);
                    grid.update();
                };
                const renderBreakdown = () => {
                    const all = rows();
                    window.CompositionBreakdown.render(
                        all.map((row) => ({ category: field(row, 'category').value, value: parseCoefficient(field(row, 'coefficient').value) })),
                        { onView: showCategory, onSelect: selectCategory }
                    );
                    window.CompositionBreakdown.fillFilter(categoryFilter, all.map((row) => field(row, 'category').value));
                };
                const applyFilter = () => { grid.refresh(true); updateMagicMenu(); };

                list.addEventListener('input', (event) => {
                    if (event.target.dataset.gridSelect) return; // marcar una fila no es un cambio de datos
                    const row = event.target.closest('.composition-unit');
                    if (row) row.dataset.touched = '1';
                    markDirty();
                    scheduleValidate();
                });
                // Al salir del campo se normaliza: «1,25» → «1.2500».
                list.addEventListener('change', (event) => {
                    if (event.target.dataset.gridSelect) return;
                    if (event.target.dataset.unitField === 'coefficient') {
                        const parsed = parseCoefficient(event.target.value);
                        if (parsed !== null) event.target.value = formatCoefficient(parsed);
                    }
                    if (event.target.dataset.unitField === 'category') {
                        const known = knownCategories.find((c) => c.toLowerCase() === event.target.value.trim().toLowerCase());
                        if (known) event.target.value = known;
                    }
                    validate();
                });
                filter.addEventListener('input', applyFilter);
                document.querySelectorAll('[name="row-view"]').forEach((radio) => radio.addEventListener('change', () => {
                    if (radio.value === 'errors') form.dataset.showErrors = '1';
                    validate();
                    applyFilter();
                }));

                list.addEventListener('click', async (event) => {
                    const button = event.target.closest('.remove-composition-unit');
                    if (!button) return;
                    const row = button.closest('.composition-unit');
                    const name = field(row, 'name').value.trim() || 'este inmueble';
                    if (!(await appConfirm(`¿Quitar «${name}» de la estructura?${row.dataset.unitId ? ' Si tiene usuarios asignados, esa asignación también se eliminará al guardar.' : ''}`))) return;
                    row.remove();
                    markDirty();
                    validate();
                    grid.refresh();
                });

                document.getElementById('add-composition-unit').addEventListener('click', () => {
                    if (rows().length >= 5000) {
                        window.alert('La estructura admite hasta 5.000 inmuebles.');
                        return;
                    }
                    const last = rows().at(-1);
                    const row = document.createElement('tr');
                    row.className = 'composition-unit';
                    row.dataset.unitId = '';
                    row.dataset.locked = '0';
                    row.innerHTML = `<td class="text-secondary small row-number"></td>
                        <td><input data-unit-field="category" class="form-control form-control-sm" list="unit-categories" maxlength="80" aria-label="Categoría"></td>
                        <td><input data-unit-field="name" class="form-control form-control-sm" maxlength="160" aria-label="Nombre del inmueble"></td>
                        <td><input data-unit-field="coefficient" class="form-control form-control-sm text-end" inputmode="decimal" placeholder="—" aria-label="Coeficiente"></td>
                        <td class="text-end"><button class="btn btn-sm btn-link text-danger p-0 remove-composition-unit" type="button" title="Quitar inmueble" aria-label="Quitar inmueble"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>`;
                    field(row, 'category').value = last ? field(last, 'category').value : '';
                    list.append(row);
                    filter.value = '';
                    document.getElementById('view-all').checked = true;
                    markDirty();
                    validate();
                    grid.showRow(row);
                    field(row, 'name').focus();
                });

                // Menú «Coeficientes»: siempre actúa sobre las filas filtradas (todas las páginas).
                const coefficientOf = (row) => parseCoefficient(field(row, 'coefficient').value);
                const sumOf = (list) => list.reduce((sum, row) => sum + (coefficientOf(row) ?? 0), 0);
                const magicScope = () => {
                    const all = rows();
                    const targets = filteredRows();
                    const others = all.filter((row) => !targets.includes(row));
                    const missing = targets.filter((row) => coefficientOf(row) === null);
                    return {
                        all, targets, missing,
                        filtered: targets.length !== all.length,
                        // Lo que les toca a los filtrados para que el total sea 100 % con lo que ya tienen los demás.
                        room: 100 * SCALE - sumOf(others),
                        // Lo que falta para 100 % contando todo lo escrito.
                        gap: 100 * SCALE - sumOf(all),
                    };
                };
                const scopeLabel = () => {
                    const parts = [];
                    if (categoryFilter.value) parts.push(`categoría «${categoryFilter.value}»`);
                    if (filter.value.trim()) parts.push(`búsqueda «${filter.value.trim()}»`);
                    if (document.getElementById('view-errors').checked) parts.push('solo filas con errores');
                    return parts.length ? `Filtrados por ${parts.join(', ')} · todas las páginas` : 'Todos los inmuebles (sin filtro)';
                };
                const setItem = (id, enabled, text) => {
                    const item = document.getElementById(id);
                    item.disabled = !enabled;
                    item.classList.toggle('opacity-50', !enabled);
                    document.getElementById(item.querySelector('[id]').id).textContent = text;
                };
                const updateMagicMenu = () => {
                    const { all, targets, missing, filtered, room, gap } = magicScope();
                    const n = targets.length;
                    const badge = document.getElementById('magic-badge');
                    badge.textContent = filtered ? `${n} de ${all.length}` : String(n);
                    badge.className = `badge rounded-pill ${filtered ? 'text-bg-warning' : 'text-bg-light'}`;
                    document.getElementById('magic-scope-count').textContent = n.toLocaleString('es-CO');
                    document.getElementById('magic-scope-detail').textContent = scopeLabel();
                    document.getElementById('magic-scope-sum').textContent = `${formatPercent(sumOf(targets))} %`;
                    document.getElementById('magic-scope-missing').textContent = missing.length.toLocaleString('es-CO');
                    setItem('magic-assign', n > 0, `Escribe un valor (p. ej. 0,5 %) y se pone en los ${n} inmueble(s).`);
                    setItem('magic-distribute', n > 0, room > 0
                        ? `Divide un porcentaje entre los ${n}. Sugerido: ${formatPercent(room)} % para que el total quede en 100 %.`
                        : `Divide un porcentaje entre los ${n} inmueble(s).`);
                    setItem('magic-fill', missing.length > 0 && gap > 0, missing.length === 0
                        ? 'Todos los filtrados ya tienen coeficiente.'
                        : gap > 0 ? `Reparte lo que falta para 100 % (${formatPercent(gap)} %) entre los ${missing.length} sin coeficiente.`
                            : `Hay ${missing.length} sin coeficiente, pero la suma ya llega a 100 %.`);
                    setItem('magic-paste', n > 0, `Un valor por línea, aplicado en orden a los ${n} inmueble(s) como se ven.`);
                    setItem('clear-coefficients', n > 0 && missing.length < n, `Deja vacío el coeficiente de los ${n} inmueble(s).`);
                };
                document.getElementById('magic-menu-list').addEventListener('beforetoggle', (event) => {
                    if (event.newState === 'open') updateMagicMenu();
                });
                // Reparte total (diezmilésimas) en partes iguales; el residuo de 0,0001 va a los primeros.
                const spread = (targets, total) => {
                    // Con 0 % permitido siempre alcanza: si el total es muy pequeño, algunos quedan en 0.
                    const base = Math.floor(total / targets.length);
                    const remainder = total - base * targets.length;
                    targets.forEach((row, index) => {
                        field(row, 'coefficient').value = formatCoefficient(base + (index < remainder ? 1 : 0));
                        row.dataset.touched = '1';
                    });
                    return null;
                };
                document.getElementById('magic-assign').addEventListener('click', () => {
                    const { targets, filtered } = magicScope();
                    askValue({
                        title: `Mismo coeficiente a ${targets.length} inmueble(s)${filtered ? ' filtrados' : ''}`,
                        label: 'Coeficiente para cada uno (%)',
                        hint: `${scopeLabel()}. Ejemplo: 0,5000. Reemplaza lo que tengan escrito.`,
                        inputmode: 'decimal',
                        apply: (raw) => {
                            const parsed = parseCoefficient(raw);
                            if (parsed === null) return 'Coeficiente inválido: entre 0 y 100, con máximo 4 decimales.';
                            targets.forEach((row) => { field(row, 'coefficient').value = formatCoefficient(parsed); row.dataset.touched = '1'; });
                            return null;
                        },
                    });
                });
                document.getElementById('magic-distribute').addEventListener('click', () => {
                    const { targets, filtered, room } = magicScope();
                    askValue({
                        title: `Repartir entre ${targets.length} inmueble(s)${filtered ? ' filtrados' : ''}`,
                        label: 'Porcentaje total a repartir (%)',
                        hint: `${scopeLabel()}. `
                            + (room > 0 ? `Con ${formatPercent(room)} % la suma total queda en 100 %. ` : '')
                            + 'Se reparte en partes iguales; el residuo de 0,0001 va a los primeros.',
                        value: room > 0 ? formatPercent(room) : '',
                        inputmode: 'decimal',
                        apply: (raw) => {
                            const total = parseCoefficient(raw);
                            if (total === null) return 'Porcentaje inválido: entre 0 y 100, con máximo 4 decimales.';
                            return spread(targets, total);
                        },
                    });
                });
                document.getElementById('magic-fill').addEventListener('click', async () => {
                    const { missing, gap } = magicScope();
                    if (missing.length === 0 || gap <= 0) return;
                    if (!(await appConfirm(`Se repartirán ${formatPercent(gap)} % en partes iguales entre los ${missing.length} inmueble(s) filtrados que no tienen coeficiente, para que la suma llegue a 100 %. Los que ya tienen valor no cambian. ¿Continuar?`))) return;
                    const problem = spread(missing, gap);
                    if (problem) { window.alert(problem); return; }
                    markDirty();
                    validate();
                    grid.refresh();
                });
                document.getElementById('clear-coefficients').addEventListener('click', async () => {
                    const { targets, filtered } = magicScope();
                    if (targets.length === 0 || !(await appConfirm(`¿Borrar el coeficiente de ${targets.length} inmueble(s)${filtered ? ' filtrados (todas las páginas)' : ''}?`))) return;
                    targets.forEach((row) => { field(row, 'coefficient').value = ''; row.dataset.touched = '1'; });
                    markDirty();
                    validate();
                });

                // Pegar una columna de Excel: se valida todo antes de aplicar.
                const pasteArea = document.getElementById('paste-values');
                const pasteStatus = document.getElementById('paste-status');
                const applyPaste = document.getElementById('apply-paste');
                const pastedValues = () => pasteArea.value.split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '');
                pasteArea.addEventListener('input', () => {
                    const values = pastedValues();
                    const targets = filteredRows().length;
                    const invalid = values.map((v, i) => [i + 1, v]).filter(([, v]) => parseCoefficient(v) === null);
                    if (values.length === 0) {
                        pasteStatus.textContent = '';
                        pasteStatus.className = 'small mt-2';
                    } else if (invalid.length > 0) {
                        pasteStatus.textContent = `Línea ${invalid[0][0]} no es un coeficiente válido: «${invalid[0][1]}».`;
                        pasteStatus.className = 'small mt-2 text-danger';
                    } else {
                        const sum = values.reduce((acc, v) => acc + parseCoefficient(v), 0);
                        pasteStatus.textContent = `${values.length} valores para ${targets} filas filtradas · suman ${formatPercent(sum)} %`
                            + (values.length !== targets ? ' · ⚠ la cantidad no coincide' : '');
                        pasteStatus.className = `small mt-2 ${values.length === targets ? 'text-success' : 'text-warning'}`;
                    }
                    applyPaste.disabled = values.length === 0 || invalid.length > 0;
                });
                applyPaste.addEventListener('click', async () => {
                    const values = pastedValues();
                    const targets = filteredRows();
                    if (values.length !== targets.length
                        && !(await appConfirm(`Pegaste ${values.length} valores pero hay ${targets.length} filas filtradas. Se llenarán ${Math.min(values.length, targets.length)} filas en orden. ¿Continuar?`))) {
                        return;
                    }
                    targets.slice(0, values.length).forEach((row, i) => {
                        field(row, 'coefficient').value = formatCoefficient(parseCoefficient(values[i]));
                    });
                    pasteArea.value = '';
                    pasteArea.dispatchEvent(new Event('input'));
                    document.getElementById('paste-modal').close();
                    markDirty();
                    validate();
                });

                form.addEventListener('submit', (event) => {
                    form.dataset.showErrors = '1';
                    const { errors, sumOk, total } = validate();
                    if (errors.length > 0) {
                        event.preventDefault();
                        document.getElementById('error-summary').scrollIntoView({ block: 'start' });
                        return;
                    }
                    if (!sumOk) {
                        event.preventDefault();
                        window.alert(`Los coeficientes suman ${formatPercent(total)} % y deben sumar 100 %. Revisa los valores o pega la columna completa desde el reglamento.`);
                        return;
                    }
                    document.getElementById('composition-units-json').value = JSON.stringify(rows().map((row) => ({
                        id: row.dataset.unitId || '',
                        category: field(row, 'category').value.trim(),
                        name: field(row, 'name').value.trim().replace(/\s+/g, ' '),
                        coefficient: formatCoefficient(parseCoefficient(field(row, 'coefficient').value)),
                    })));
                    dirty = false;
                    submit.disabled = true;
                    submit.textContent = 'Guardando…';
                });
                window.addEventListener('beforeunload', (event) => {
                    if (dirty) event.preventDefault();
                });

                // Ventana para pedir un valor que se aplica a las filas seleccionadas.
                const bulkModal = document.getElementById('bulk-value-modal');
                const bulkForm = document.getElementById('bulk-value-form');
                const bulkInput = document.getElementById('bulk-value-input');
                const bulkError = document.getElementById('bulk-value-error');
                let bulkApply = null;
                const askValue = ({ title, label, hint, value = '', list = '', inputmode = 'text', apply }) => {
                    document.getElementById('bulk-value-title').textContent = title;
                    document.getElementById('bulk-value-label').textContent = label;
                    document.getElementById('bulk-value-hint').textContent = hint;
                    bulkInput.value = value;
                    bulkInput.inputMode = inputmode;
                    if (list) bulkInput.setAttribute('list', list); else bulkInput.removeAttribute('list');
                    bulkInput.classList.remove('is-invalid');
                    bulkApply = apply;
                    bulkModal.showModal();
                    bulkInput.focus();
                };
                bulkForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    const problem = bulkApply ? bulkApply(bulkInput.value) : null;
                    if (problem) {
                        bulkError.textContent = problem;
                        bulkInput.classList.add('is-invalid');
                        return;
                    }
                    bulkModal.close();
                    markDirty();
                    validate();
                    grid.refresh();
                });
                const touch = (row) => { row.dataset.touched = '1'; };

                grid = new DataGrid(document.getElementById('composition-unit-table'), {
                    storageKey: 'composition-units',
                    rowSelector: '.composition-unit',
                    filter: matchesFilter,
                    sortValue: (row, key) => (key === 'position' ? row.dataset.gridOrder : undefined),
                    selectable: true,
                    toolbar: document.getElementById('unit-grid-tools'),
                    bulkBar: document.getElementById('unit-grid-bulk'),
                    pager: document.getElementById('unit-grid-pager'),
                    exportName: 'inmuebles',
                    bulkActions: [
                        {
                            label: 'Cambiar categoría', icon: 'bi-tag',
                            run: (selected) => askValue({
                                title: `Cambiar categoría de ${selected.length} inmueble(s)`,
                                label: 'Nueva categoría',
                                hint: 'Elige una de la lista o escribe una nueva.',
                                list: 'unit-categories',
                                apply: (raw) => {
                                    const text = raw.trim();
                                    if (text === '' || text.length > 80) return 'Escribe una categoría de hasta 80 caracteres.';
                                    const value = knownCategories.find((c) => c.toLowerCase() === text.toLowerCase()) ?? text;
                                    selected.forEach((row) => { field(row, 'category').value = value; touch(row); });
                                    return null;
                                },
                            }),
                        },
                        {
                            label: 'Asignar coeficiente', icon: 'bi-percent',
                            run: (selected) => askValue({
                                title: `Asignar coeficiente a ${selected.length} inmueble(s)`,
                                label: 'Coeficiente para cada uno (%)',
                                hint: 'Ejemplo: 0,8500. Se escribe el mismo valor en cada inmueble seleccionado.',
                                inputmode: 'decimal',
                                apply: (raw) => {
                                    const parsed = parseCoefficient(raw);
                                    if (parsed === null) return 'Coeficiente inválido: entre 0 y 100, con máximo 4 decimales.';
                                    selected.forEach((row) => { field(row, 'coefficient').value = formatCoefficient(parsed); touch(row); });
                                    return null;
                                },
                            }),
                        },
                        {
                            label: 'Repartir un total', icon: 'bi-distribute-vertical',
                            run: (selected) => askValue({
                                title: `Repartir un porcentaje entre ${selected.length} inmueble(s)`,
                                label: 'Porcentaje total a repartir (%)',
                                hint: 'Ejemplo: si los parqueaderos suman 12 %, escribe 12. Se reparte en partes iguales y el residuo de 0,0001 va a los primeros.',
                                inputmode: 'decimal',
                                apply: (raw) => {
                                    const total = parseCoefficient(raw);
                                    if (total === null) return 'Porcentaje inválido: entre 0 y 100, con máximo 4 decimales.';
                                    const base = Math.floor(total / selected.length);
                                    const remainder = total - base * selected.length;
                                    selected.forEach((row, index) => {
                                        field(row, 'coefficient').value = formatCoefficient(base + (index < remainder ? 1 : 0));
                                        touch(row);
                                    });
                                    return null;
                                },
                            }),
                        },
                        {
                            label: 'Borrar coeficiente', icon: 'bi-eraser', className: 'btn-outline-secondary',
                            run: async (selected) => {
                                if (!(await appConfirm(`¿Borrar el coeficiente de ${selected.length} inmueble(s) seleccionados?`))) return;
                                selected.forEach((row) => { field(row, 'coefficient').value = ''; touch(row); });
                                markDirty();
                                validate();
                                grid.refresh();
                            },
                        },
                        {
                            label: 'Quitar', icon: 'bi-trash', className: 'btn-outline-danger',
                            run: async (selected) => {
                                const removable = selected.filter((row) => row.dataset.locked !== '1');
                                const locked = selected.length - removable.length;
                                if (removable.length === 0) {
                                    window.alert('Los inmuebles seleccionados tienen notas de cobro y no se pueden quitar.');
                                    return;
                                }
                                const message = `¿Quitar ${removable.length} inmueble(s) de la estructura?`
                                    + (locked > 0 ? `\n\n${locked} se conservarán porque tienen notas de cobro.` : '')
                                    + '\n\nSi tienen usuarios asignados, esas asignaciones también se eliminarán al guardar.';
                                if (!(await appConfirm(message))) return;
                                removable.forEach((row) => row.remove());
                                markDirty();
                                validate();
                                grid.refresh();
                            },
                        },
                    ],
                });

                <?php if ($restoredDraft): ?>form.dataset.showErrors = '1'; markDirty();<?php endif; ?>
                validate();
                grid.refresh();
            })();
            </script>
        <?php else: ?>
            <?php $breakdownCard(false); ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
                    <h2 class="card-title h6 mb-0 me-auto">Lista de inmuebles</h2>
                    <input id="unit-filter" class="form-control form-control-sm" style="max-width: 16rem" type="search" placeholder="Filtrar: Torre 2, comercial…" aria-label="Filtrar inmuebles">
                    <select id="unit-category-filter" class="form-select form-select-sm w-auto" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option></select>
                    <div id="unit-grid-tools" class="d-flex flex-wrap gap-2"></div>
                </div>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-configuration-composition-3" style="max-height: 70vh">
                    <table id="composition-unit-table" class="table table-sm table-striped mb-0">
                        <caption id="tbl-configuration-composition-3" class="visually-hidden">Lista de inmuebles</caption>
                        <thead class="table-light sticky-top">
                            <tr>
                                <th scope="col" data-column="position" data-label="#" data-sortable>#</th>
                                <th scope="col" data-column="category" data-label="Categoría" data-sortable>Categoría</th>
                                <th scope="col" data-column="name" data-label="Nombre del inmueble" data-hideable="false" data-sortable>Nombre del inmueble</th>
                                <th scope="col" class="text-end" data-column="coefficient" data-label="Coeficiente (%)" data-sortable>Coeficiente (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($units as $index => $unit): ?>
                                <tr data-category="<?= $escape(trim((string) ($unit['category'] ?? '')) ?: 'Sin categoría') ?>" data-coefficient="<?= $escape((string) ($unit['coefficient'] ?? '')) ?>"><td class="text-secondary"><?= $index + 1 ?></td><td><?= $escape($unit['category'] ?? '') ?></td><td><?= $escape($unit['name'] ?? '') ?></td><td class="text-end"><?= isset($unit['coefficient']) && $unit['coefficient'] !== '' ? $escape(number_format((float) $unit['coefficient'], 4, ',', '.')) : '<span class="text-warning">Pendiente</span>' ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="unit-grid-pager" class="px-3 py-2 border-top"></div>
            </div>
            <script nonce="<?= \App\Core\Csp::nonce() ?>">
            (() => {
                const filter = document.getElementById('unit-filter');
                const categoryFilter = document.getElementById('unit-category-filter');
                const table = document.getElementById('composition-unit-table');
                const grid = new DataGrid(table, {
                    storageKey: 'composition-units-read',
                    filter: (row) => (filter.value.trim() === '' || row.textContent.toLowerCase().includes(filter.value.trim().toLowerCase()))
                        && (categoryFilter.value === '' || row.dataset.category === categoryFilter.value),
                    sortValue: (row, key) => (key === 'position' ? row.dataset.gridOrder : undefined),
                    toolbar: document.getElementById('unit-grid-tools'),
                    pager: document.getElementById('unit-grid-pager'),
                    exportName: 'inmuebles',
                });
                filter.addEventListener('input', () => grid.refresh(true));
                categoryFilter.addEventListener('change', () => grid.refresh(true));
                // "12.5" o "12.5000" → diezmilésimas; null si falta.
                const scaled = (text) => {
                    const match = /^(\d{1,3})(?:\.(\d{1,4}))?$/.exec(String(text).trim());
                    return match ? parseInt(match[1], 10) * 10000 + parseInt((match[2] || '').padEnd(4, '0'), 10) : null;
                };
                const rows = grid.rowsInOrder();
                window.CompositionBreakdown.render(
                    rows.map((row) => ({ category: row.dataset.category, value: scaled(row.dataset.coefficient) })),
                    { onView: (category) => { categoryFilter.value = category; grid.refresh(true); table.scrollIntoView({ block: 'start', behavior: 'smooth' }); } }
                );
                window.CompositionBreakdown.fillFilter(categoryFilter, rows.map((row) => row.dataset.category));
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</section>
