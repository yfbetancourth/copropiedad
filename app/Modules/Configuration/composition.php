<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.conformacion', 2);
$housingLabels = [
    'casa' => 'Casa',
    'torres_apartamento' => 'Torres de apartamentos',
    'mixto' => 'Mixto',
];
$housingLabel = $housingLabels[$housingType] ?? 'Copropiedad';

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Conformación de la copropiedad</h1>
        <p class="text-secondary mb-0">Completa los pasos para organizar los bienes de tipo <?= $escape($housingLabel) ?>.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($mode === 'wizard'): ?>
        <?php $step = $steps[$stepIndex]; ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Asistente de conformacion</h2>
                <span class="text-secondary">Paso <?= $stepIndex + 1 ?> de <?= count($steps) ?></span>
            </div>
            <div class="card-body">
                <div class="progress mb-4" role="progressbar" aria-label="Progreso del asistente" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: <?= $progress ?>%"><?= $progress ?>%</div>
                </div>
                <p class="mb-1 fw-semibold"><?= $escape($step['label']) ?></p>
                <p class="text-secondary"><?= $escape($step['help']) ?></p>
                <?php if ($canWrite): ?>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/paso">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="field" value="<?= $escape($step['key']) ?>">
                        <div class="mb-3">
                            <label for="composition-value" class="form-label">Cantidad</label>
                            <?php $maximum = $step['key'] === 'units_per_floor' ? 99 : 5000; ?>
                            <input id="composition-value" name="value" class="form-control" type="number" inputmode="numeric" min="1" max="<?= $maximum ?>" step="1" required value="<?= $escape($answers[$step['key']] ?? '') ?>" aria-describedby="composition-limit">
                            <div id="composition-limit" class="form-text">
                                <?php if ($step['key'] === 'units_per_floor'): ?>
                                    Ingresa de 1 a 99 apartamentos por piso. Su nomenclatura numerica ira de 01 a 99; 00 no es valido.
                                <?php else: ?>
                                    Escribe un numero entero positivo. La estructura final admite hasta 5.000 inmuebles.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <?php if ($stepIndex > 0): ?>
                                <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/conformacion?paso=<?= $stepIndex - 1 ?>">Paso anterior</a>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit">Guardar y continuar</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="mb-0">Cantidad registrada: <strong><?= $escape($answers[$step['key']] ?? 'Pendiente') ?></strong></p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($mode === 'answers_review'): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Revisa las cantidades</h2></div>
            <div class="card-body">
                <p>Confirma que los datos sean correctos. Despues generaremos una lista inicial de inmuebles que podras editar.</p>
                <dl class="row mb-0">
                    <?php foreach ($steps as $index => $reviewStep): ?>
                        <dt class="col-sm-8"><?= $escape($reviewStep['label']) ?></dt>
                        <dd class="col-sm-4">
                            <?= $escape($answers[$reviewStep['key']] ?? '') ?>
                            <?php if ($canWrite): ?>
                                <a class="ms-2" href="<?= $escape($basePath) ?>/configuracion/conformacion?paso=<?= $index ?>">Cambiar</a>
                            <?php endif; ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
                <p class="small text-secondary mt-3 mb-0">Se generaran hasta 5.000 inmuebles. La cantidad de inmuebles comerciales es independiente de las viviendas.</p>
            </div>
            <?php if ($canWrite): ?>
                <div class="card-footer d-flex justify-content-end">
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/generar">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button class="btn btn-primary" type="submit">Generar estructura para revisar</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Estructura de bienes</h2>
                <?php if ($completed): ?>
                    <span class="badge text-bg-success">Guardada</span>
                <?php else: ?>
                    <span class="badge text-bg-warning">Pendiente de confirmar</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p>Revisa los nombres y las categorias. Puedes agregar, quitar o corregir inmuebles antes de guardar.</p>
                <div class="mb-3">
                    <?php foreach ($steps as $index => $reviewStep): ?>
                        <span class="badge text-bg-light me-1"><?= $escape($reviewStep['label']) ?> <?= $escape($answers[$reviewStep['key']] ?? '') ?></span>
                        <?php if ($canWrite): ?>
                            <a class="small me-2" href="<?= $escape($basePath) ?>/configuracion/conformacion?paso=<?= $index ?>">Cambiar</a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($canWrite): ?>
                    <form id="composition-units-form" method="post" action="<?= $escape($basePath) ?>/configuracion/conformacion/finalizar">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input id="composition-units-json" type="hidden" name="units_json" value="">
                        <div id="composition-unit-list" class="vstack gap-2">
                            <?php foreach ($units as $index => $unit): ?>
                                <div class="row g-2 align-items-end composition-unit">
                                    <div class="col-md-4">
                                        <label class="form-label" for="unit-category-<?= $index ?>">Categoria</label>
                                        <input id="unit-category-<?= $index ?>" data-unit-field="category" class="form-control" type="text" maxlength="80" required value="<?= $escape($unit['category'] ?? '') ?>">
                                    </div>
                                    <div class="col-md">
                                        <label class="form-label" for="unit-name-<?= $index ?>">Nombre del inmueble</label>
                                        <input id="unit-name-<?= $index ?>" data-unit-field="name" class="form-control" type="text" maxlength="160" required value="<?= $escape($unit['name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-auto">
                                        <button class="btn btn-outline-danger remove-composition-unit" type="button" aria-label="Quitar inmueble">Quitar</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button id="add-composition-unit" class="btn btn-outline-primary" type="button">Agregar inmueble</button>
                            <button class="btn btn-primary ms-md-auto" type="submit"><?= $completed ? 'Guardar cambios' : 'Finalizar y guardar estructura' ?></button>
                        </div>
                    </form>
                    <template id="composition-unit-template">
                        <div class="row g-2 align-items-end composition-unit">
                            <div class="col-md-4">
                                <label class="form-label">Categoria</label>
                                <input class="form-control unit-category" type="text" maxlength="80" required>
                            </div>
                            <div class="col-md">
                                <label class="form-label">Nombre del inmueble</label>
                                <input class="form-control unit-name" type="text" maxlength="160" required>
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-outline-danger remove-composition-unit" type="button" aria-label="Quitar inmueble">Quitar</button>
                            </div>
                        </div>
                    </template>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>Categoria</th><th>Nombre del inmueble</th></tr></thead>
                            <tbody>
                                <?php foreach ($units as $unit): ?>
                                    <tr><td><?= $escape($unit['category'] ?? '') ?></td><td><?= $escape($unit['name'] ?? '') ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canWrite): ?>
            <script>
            (() => {
                const list = document.getElementById('composition-unit-list');
                const template = document.getElementById('composition-unit-template');
                const addButton = document.getElementById('add-composition-unit');
                const form = document.getElementById('composition-units-form');
                let nextIndex = list.querySelectorAll('.composition-unit').length;
                const reindexRows = () => {
                    list.querySelectorAll('.composition-unit').forEach((row, index) => {
                        const category = row.querySelector('[data-unit-field="category"]');
                        const name = row.querySelector('[data-unit-field="name"]');
                        category.id = `unit-category-${index}`;
                        name.id = `unit-name-${index}`;
                        row.querySelectorAll('label')[0].htmlFor = category.id;
                        row.querySelectorAll('label')[1].htmlFor = name.id;
                    });
                    nextIndex = list.querySelectorAll('.composition-unit').length;
                };
                addButton.addEventListener('click', () => {
                    if (list.querySelectorAll('.composition-unit').length >= 5000) {
                        window.alert('La estructura admite hasta 5.000 inmuebles.');
                        return;
                    }
                    const row = template.content.firstElementChild.cloneNode(true);
                    row.querySelector('.unit-category').dataset.unitField = 'category';
                    row.querySelector('.unit-name').dataset.unitField = 'name';
                    row.querySelector('.unit-category').id = `unit-category-${nextIndex}`;
                    row.querySelector('.unit-name').id = `unit-name-${nextIndex}`;
                    row.querySelectorAll('label')[0].htmlFor = `unit-category-${nextIndex}`;
                    row.querySelectorAll('label')[1].htmlFor = `unit-name-${nextIndex}`;
                    list.appendChild(row);
                    nextIndex++;
                });
                list.addEventListener('click', (event) => {
                    if (event.target.closest('.remove-composition-unit')) {
                        event.target.closest('.composition-unit').remove();
                        reindexRows();
                    }
                });
                form.addEventListener('submit', () => {
                    const units = [...list.querySelectorAll('.composition-unit')].map((row) => ({
                        category: row.querySelector('[data-unit-field="category"]').value.trim(),
                        name: row.querySelector('[data-unit-field="name"]').value.trim(),
                    }));
                    document.getElementById('composition-units-json').value = JSON.stringify(units);
                });
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</section>
