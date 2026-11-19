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

    <?php require __DIR__ . '/conformation-steps.php'; ?>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($mode === 'wizard'): ?>
        <?php
        $step = $steps[$stepIndex];
        $maximum = $step['key'] === 'units_per_floor' ? 99 : 5000;
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
                        <div class="progress mb-4" style="height: .5rem" role="progressbar" aria-label="Progreso del asistente" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                        </div>
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
                                    <input id="composition-value" name="value" class="form-control text-center fs-5" type="number" inputmode="numeric" min="1" max="<?= $maximum ?>" step="1" required autofocus
                                           value="<?= $escape($answers[$step['key']] ?? '') ?>" aria-describedby="composition-limit">
                                    <button class="btn btn-outline-secondary" type="button" data-step="1" aria-label="Sumar uno">+</button>
                                </div>
                                <div id="composition-limit" class="form-text mb-3">
                                    <?= $step['key'] === 'units_per_floor'
                                        ? 'Entre 1 y 99: los apartamentos se numeran de 01 a 99.'
                                        : 'Número entero entre 1 y 5.000.' ?>
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
        <script>
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
                const a = { ...answers };
                if (Number.isInteger(value) && value > 0) a[field] = value;
                const shown = document.querySelector(`[data-answer="${field}"]`);
                if (shown && input) shown.textContent = Number.isInteger(value) && value > 0 ? value : '—';
                let count = null;
                if (housing === 'casa' && a.houses) {
                    count = a.houses;
                    detail.textContent = `Casa 1 … Casa ${a.houses}`;
                } else if (a.towers && a.floors_per_tower && a.units_per_floor) {
                    const apartments = a.towers * a.floors_per_tower * a.units_per_floor;
                    count = apartments + (housing === 'mixto' ? (a.commercial_properties || 0) : 0);
                    detail.textContent = `${format(apartments)} apartamentos (${a.towers} × ${a.floors_per_tower} × ${a.units_per_floor})`
                        + (housing === 'mixto' ? ` + ${format(a.commercial_properties || 0)} comerciales` : '');
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
                const next = Math.min(max, Math.max(1, (parseInt(input.value, 10) || 0) + parseInt(button.dataset.step, 10)));
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
                'Torre ' . ($towerNomenclature === 'alphabetic' ? 'A' : '1') . ' - Piso 1 - Apartamento 01',
                'Torre ' . ($towerNomenclature === 'alphabetic' ? 'A' : '1') . ' - Piso 1 - Apartamento ' . str_pad((string) ($answers['units_per_floor'] ?? 1), 2, '0', STR_PAD_LEFT),
                '…',
            ];
        if ($housingType === 'mixto') {
            $exampleNames[] = 'Inmueble comercial 1';
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

        <?php if ($canWrite): ?>
            <form id="composition-units-form" method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/finalizar" novalidate>
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
                            <div class="progress" style="height: .4rem" role="progressbar" aria-label="Suma de coeficientes"><div id="coefficient-bar" class="progress-bar" style="width: 0%"></div></div>
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

                <div id="error-summary" class="alert alert-danger" hidden role="alert">
                    <strong>Corrige estos datos antes de guardar:</strong>
                    <ul id="error-list" class="mb-0 mt-2"></ul>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
                        <h2 class="card-title h6 mb-0 me-auto">Lista de inmuebles</h2>
                        <input id="unit-filter" class="form-control form-control-sm" style="max-width: 16rem" type="search" placeholder="Filtrar: Torre 2, comercial…" aria-label="Filtrar inmuebles">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Mostrar">
                            <input class="btn-check" type="radio" name="row-view" id="view-all" value="all" checked><label class="btn btn-outline-secondary" for="view-all">Todos</label>
                            <input class="btn-check" type="radio" name="row-view" id="view-errors" value="errors"><label class="btn btn-outline-secondary" for="view-errors">Con errores</label>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-magic me-1" aria-hidden="true"></i>Coeficientes</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#paste-modal"><i class="bi bi-clipboard me-2" aria-hidden="true"></i>Pegar desde Excel…</button></li>
                                <li><button class="dropdown-item" type="button" id="distribute-equally"><i class="bi bi-distribute-vertical me-2" aria-hidden="true"></i>Repartir 100 % en partes iguales</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button class="dropdown-item text-danger" type="button" id="clear-coefficients"><i class="bi bi-eraser me-2" aria-hidden="true"></i>Borrar coeficientes visibles</button></li>
                            </ul>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 62vh">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr><th scope="col" class="text-secondary" style="width: 3.5rem">#</th><th scope="col" style="width: 22%">Categoría</th><th scope="col">Nombre del inmueble</th><th scope="col" style="width: 11rem">Coeficiente (%)</th><th scope="col" style="width: 3rem"><span class="visually-hidden">Acciones</span></th></tr>
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

            <div class="modal fade" id="paste-modal" tabindex="-1" aria-labelledby="paste-modal-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="paste-modal-title">Pegar coeficientes desde Excel</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small">Copia la columna de coeficientes del reglamento o de Excel (un valor por línea) y pégala aquí. Se aplicarán <strong>en orden a las filas visibles</strong>; filtra primero si quieres llenar solo una torre.</p>
                            <p class="small text-secondary">Se aceptan comas o puntos decimales y el signo %: <code>1,2500</code>, <code>1.25</code>, <code>0,85%</code>.</p>
                            <textarea id="paste-values" class="form-control font-monospace" rows="10" aria-describedby="paste-status"></textarea>
                            <div id="paste-status" class="small mt-2" aria-live="polite"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="apply-paste" disabled>Aplicar coeficientes</button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            (() => {
                const SCALE = 10000; // los coeficientes se manejan en diezmilésimas para evitar errores de redondeo
                const TOLERANCE = 100; // ±0,01 %
                const form = document.getElementById('composition-units-form');
                const list = document.getElementById('composition-unit-list');
                const filter = document.getElementById('unit-filter');
                const submit = document.getElementById('composition-submit');
                const dirtyIndicator = document.getElementById('dirty-indicator');
                const rows = () => [...list.querySelectorAll('.composition-unit')];
                const visibleRows = () => rows().filter((row) => !row.hidden);
                const field = (row, name) => row.querySelector(`[data-unit-field="${name}"]`);
                let dirty = false;

                // "1,25 %" → 12500 diezmilésimas; null si no es válido.
                const parseCoefficient = (raw) => {
                    const text = String(raw).trim().replace('%', '').replace(/\s/g, '').replace(',', '.');
                    const match = /^(\d{1,3})(?:\.(\d{1,4}))?$/.exec(text);
                    if (!match) return null;
                    const value = parseInt(match[1], 10) * SCALE + parseInt((match[2] || '').padEnd(4, '0'), 10);
                    return value >= 1 && value <= 100 * SCALE ? value : null;
                };
                const formatCoefficient = (value) => `${Math.floor(value / SCALE)}.${String(value % SCALE).padStart(4, '0')}`;
                const formatPercent = (value) => (value / SCALE).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
                const apartmentPattern = /(?:^| - )Apartamento (?:0[1-9]|[1-9][0-9])$/;
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
                            rowErrors.push('debe terminar en «Apartamento 01» a «99»');
                        }
                        const parsed = parseCoefficient(coefficient.value);
                        const coefficientOk = parsed !== null;
                        if (!coefficientOk) rowErrors.push(coefficient.value.trim() === '' ? 'falta el coeficiente' : 'coeficiente inválido (>0, hasta 100, 4 decimales)');
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
                    bar.style.width = `${Math.min(100, total / SCALE)}%`;
                    bar.className = `progress-bar ${sumOk ? 'bg-success' : diff > 0 ? 'bg-danger' : 'bg-warning'}`;
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
                            error.row.scrollIntoView({ block: 'center' });
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
                    return { errors, sumOk, total };
                };

                let validateTimer;
                const scheduleValidate = () => {
                    clearTimeout(validateTimer);
                    validateTimer = setTimeout(validate, 120);
                };

                const applyFilter = () => {
                    const query = filter.value.trim().toLowerCase();
                    const onlyErrors = document.getElementById('view-errors').checked;
                    rows().forEach((row) => {
                        const text = `${field(row, 'category').value} ${field(row, 'name').value}`.toLowerCase();
                        row.hidden = (query !== '' && !text.includes(query)) || (onlyErrors && row.dataset.hasError !== '1');
                    });
                };

                list.addEventListener('input', (event) => {
                    const row = event.target.closest('.composition-unit');
                    if (row) row.dataset.touched = '1';
                    markDirty();
                    scheduleValidate();
                });
                // Al salir del campo se normaliza: «1,25» → «1.2500».
                list.addEventListener('change', (event) => {
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

                list.addEventListener('click', (event) => {
                    const button = event.target.closest('.remove-composition-unit');
                    if (!button) return;
                    const row = button.closest('.composition-unit');
                    const name = field(row, 'name').value.trim() || 'este inmueble';
                    if (!window.confirm(`¿Quitar «${name}» de la estructura?${row.dataset.unitId ? ' Si tiene usuarios asignados, esa asignación también se eliminará al guardar.' : ''}`)) return;
                    row.remove();
                    markDirty();
                    validate();
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
                    applyFilter();
                    markDirty();
                    validate();
                    row.scrollIntoView({ block: 'center' });
                    field(row, 'name').focus();
                });

                document.getElementById('distribute-equally').addEventListener('click', () => {
                    const all = rows();
                    if (all.length === 0) return;
                    const filled = all.filter((row) => field(row, 'coefficient').value.trim() !== '').length;
                    const base = Math.floor(100 * SCALE / all.length);
                    const remainder = 100 * SCALE - base * all.length;
                    const message = `Se asignará el mismo coeficiente (${formatPercent(base)} %) a los ${all.length} inmuebles`
                        + (remainder > 0 ? `; los primeros ${remainder} reciben 0,0001 adicional para que la suma sea exactamente 100 %.` : '.')
                        + (filled > 0 ? `\n\nSe reemplazarán ${filled} coeficientes ya escritos.` : '')
                        + '\n\nÚsalo solo si el reglamento asigna el mismo coeficiente a todos. ¿Continuar?';
                    if (!window.confirm(message)) return;
                    all.forEach((row, index) => {
                        field(row, 'coefficient').value = formatCoefficient(base + (index < remainder ? 1 : 0));
                    });
                    markDirty();
                    validate();
                });

                document.getElementById('clear-coefficients').addEventListener('click', () => {
                    const targets = visibleRows();
                    if (targets.length === 0 || !window.confirm(`¿Borrar el coeficiente de ${targets.length} inmueble(s) visibles?`)) return;
                    targets.forEach((row) => { field(row, 'coefficient').value = ''; });
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
                    const targets = visibleRows().length;
                    const invalid = values.map((v, i) => [i + 1, v]).filter(([, v]) => parseCoefficient(v) === null);
                    if (values.length === 0) {
                        pasteStatus.textContent = '';
                        pasteStatus.className = 'small mt-2';
                    } else if (invalid.length > 0) {
                        pasteStatus.textContent = `Línea ${invalid[0][0]} no es un coeficiente válido: «${invalid[0][1]}».`;
                        pasteStatus.className = 'small mt-2 text-danger';
                    } else {
                        const sum = values.reduce((acc, v) => acc + parseCoefficient(v), 0);
                        pasteStatus.textContent = `${values.length} valores para ${targets} filas visibles · suman ${formatPercent(sum)} %`
                            + (values.length !== targets ? ' · ⚠ la cantidad no coincide' : '');
                        pasteStatus.className = `small mt-2 ${values.length === targets ? 'text-success' : 'text-warning'}`;
                    }
                    applyPaste.disabled = values.length === 0 || invalid.length > 0;
                });
                applyPaste.addEventListener('click', () => {
                    const values = pastedValues();
                    const targets = visibleRows();
                    if (values.length !== targets.length
                        && !window.confirm(`Pegaste ${values.length} valores pero hay ${targets.length} filas visibles. Se llenarán ${Math.min(values.length, targets.length)} filas en orden. ¿Continuar?`)) {
                        return;
                    }
                    targets.slice(0, values.length).forEach((row, i) => {
                        field(row, 'coefficient').value = formatCoefficient(parseCoefficient(values[i]));
                    });
                    pasteArea.value = '';
                    pasteArea.dispatchEvent(new Event('input'));
                    bootstrap.Modal.getInstance(document.getElementById('paste-modal'))?.hide();
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

                <?php if ($restoredDraft): ?>form.dataset.showErrors = '1'; markDirty();<?php endif; ?>
                validate();
            })();
            </script>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive" style="max-height: 70vh">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light sticky-top"><tr><th>#</th><th>Categoría</th><th>Nombre del inmueble</th><th class="text-end">Coeficiente (%)</th></tr></thead>
                        <tbody>
                            <?php foreach ($units as $index => $unit): ?>
                                <tr><td class="text-secondary"><?= $index + 1 ?></td><td><?= $escape($unit['category'] ?? '') ?></td><td><?= $escape($unit['name'] ?? '') ?></td><td class="text-end"><?= isset($unit['coefficient']) && $unit['coefficient'] !== '' ? $escape(number_format((float) $unit['coefficient'], 4, ',', '.')) : '<span class="text-warning">Pendiente</span>' ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
