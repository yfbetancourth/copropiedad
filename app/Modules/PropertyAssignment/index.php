<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'inmuebles.asignaciones', 2);
$hasStructure = $units !== [];
$assignedUnitLookup = array_fill_keys($assignedUnitIds, true);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Asignación de inmuebles</h1>
        <p class="text-secondary mb-0">Elige un usuario y asígnale uno o varios inmuebles de la copropiedad.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$hasStructure): ?>
        <div class="alert alert-info" role="status">
            Primero debes finalizar la estructura de inmuebles.
            <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/conformacion">Ir a Conformación de la copropiedad</a>
        </div>
    <?php elseif ($users === []): ?>
        <div class="alert alert-info" role="status">
            No hay usuarios disponibles para asignar. Crea primero las cuentas en
            <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/equipo">Configuración / Equipo</a>.
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Usuarios</h2></div>
                    <div class="card-body">
                        <form method="get" action="<?= $escape($basePath) ?>/inmuebles/asignaciones">
                            <label for="assignment-user" class="form-label">Selecciona un usuario para administrar sus inmuebles</label>
                            <select id="assignment-user" name="usuario" class="form-select" required>
                                <option value="">Selecciona un usuario</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= (int) $user['id'] ?>" <?= $selectedUserId === (int) $user['id'] ? 'selected' : '' ?>>
                                        <?= $escape($user['username']) ?><?= !(bool) $user['is_active'] ? ' (inactivo)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary mt-2" type="submit">Ver asignaciones</button>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead><tr><th>Usuario</th><th>Inmuebles</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <?= $escape($user['username']) ?>
                                        <?php if (!(bool) $user['is_active']): ?><span class="badge text-bg-secondary">Inactivo</span><?php endif; ?>
                                    </td>
                                    <td><?= (int) $user['assigned_count'] ?></td>
                                    <td><a class="btn btn-outline-primary btn-sm" href="<?= $escape($basePath) ?>/inmuebles/asignaciones?usuario=<?= (int) $user['id'] ?>"><?= $canWrite ? 'Editar' : 'Ver' ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <?php if (is_array($selectedUser)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Inmuebles de <?= $escape($selectedUser['username']) ?></h2>
                            <span class="badge text-bg-light"><?= count($assignedUnitIds) ?> asignados</span>
                        </div>
                        <div class="card-body">
                            <?php if ($canWrite): ?>
                                <form id="unit-assignment-form" method="post" action="<?= $escape($basePath) ?>/inmuebles/asignaciones/guardar">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                                    <input id="unit-ids-json" type="hidden" name="unit_ids_json" value="">
                                    <p class="text-secondary">Marca todos los inmuebles que pertenecen a este usuario. Puedes usar el buscador para encontrarlos rápidamente.</p>
                                    <div class="mb-3">
                                        <label for="unit-search" class="form-label">Buscar por nombre o categoría</label>
                                        <input id="unit-search" class="form-control" type="search" autocomplete="off" placeholder="Ejemplo: Torre A, apartamento 01 o local">
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <button id="select-visible-units" class="btn btn-outline-secondary btn-sm" type="button">Seleccionar visibles</button>
                                        <button id="clear-visible-units" class="btn btn-outline-secondary btn-sm" type="button">Quitar selección visible</button>
                                        <span class="text-secondary align-self-center ms-auto"><span id="selected-unit-count"><?= count($assignedUnitIds) ?></span> seleccionados</span>
                                    </div>
                                    <div id="unit-assignment-list" class="border rounded p-2" style="max-height: 55vh; overflow-y: auto;">
                                        <?php foreach ($units as $unit): ?>
                                            <?php $unitId = (string) ($unit['id'] ?? ''); ?>
                                            <label class="form-check d-flex gap-2 align-items-start unit-assignment-row py-1" data-search="<?= $escape(strtolower(($unit['category'] ?? '') . ' ' . ($unit['name'] ?? ''))) ?>">
                                                <input class="form-check-input unit-assignment-check" type="checkbox" value="<?= $escape($unitId) ?>" <?= isset($assignedUnitLookup[$unitId]) ? 'checked' : '' ?>>
                                                <span class="form-check-label">
                                                    <span class="fw-semibold"><?= $escape($unit['name'] ?? '') ?></span>
                                                    <span class="text-secondary"> · <?= $escape($unit['category'] ?? '') ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button class="btn btn-primary mt-3" type="submit">Guardar asignaciones</button>
                                </form>
                            <?php else: ?>
                                <p class="text-secondary">Inmuebles asignados a este usuario:</p>
                                <ul class="mb-0">
                                    <?php foreach ($units as $unit): ?>
                                        <?php if (isset($assignedUnitLookup[(string) ($unit['id'] ?? '')])): ?>
                                            <li><?= $escape($unit['name'] ?? '') ?> · <?= $escape($unit['category'] ?? '') ?></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($assignedUnitIds === []): ?><li>No tiene inmuebles asignados.</li><?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-secondary">Selecciona un usuario para ver o editar los inmuebles que tiene asignados.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php if ($canWrite && is_array($selectedUser) && $hasStructure): ?>
    <script>
    (() => {
        const form = document.getElementById('unit-assignment-form');
        const search = document.getElementById('unit-search');
        const rows = [...document.querySelectorAll('.unit-assignment-row')];
        const checkboxes = [...document.querySelectorAll('.unit-assignment-check')];
        const count = document.getElementById('selected-unit-count');
        const updateCount = () => {
            count.textContent = String(checkboxes.filter((checkbox) => checkbox.checked).length);
        };
        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            rows.forEach((row) => {
                row.hidden = query !== '' && !row.dataset.search.includes(query);
            });
        });
        document.getElementById('select-visible-units').addEventListener('click', () => {
            rows.forEach((row) => {
                if (!row.hidden) {
                    row.querySelector('.unit-assignment-check').checked = true;
                }
            });
            updateCount();
        });
        document.getElementById('clear-visible-units').addEventListener('click', () => {
            rows.forEach((row) => {
                if (!row.hidden) {
                    row.querySelector('.unit-assignment-check').checked = false;
                }
            });
            updateCount();
        });
        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateCount));
        form.addEventListener('submit', () => {
            document.getElementById('unit-ids-json').value = JSON.stringify(
                checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value)
            );
        });
    })();
    </script>
<?php endif; ?>
