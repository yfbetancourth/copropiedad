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
$totalUnits = count($units);
$coverage = $totalUnits > 0 ? (int) round($coveredUnits * 100 / $totalUnits) : 0;
$currentStep = 3;
// Agrupa por torre (o por categoría) para que la lista larga se pueda recorrer.
$groupOf = static function (array $unit): string {
    $name = (string) ($unit['name'] ?? '');
    return preg_match('/^(Torre \S+)/u', $name, $match) === 1 ? $match[1] : (string) ($unit['category'] ?? 'Otros');
};
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Asignación de inmuebles</h1>
        <p class="text-secondary mb-0">Paso 3 de 3 · Relaciona cada usuario con los inmuebles que le pertenecen. Así recibe sus notas de cobro y los ve en su portal.</p>
    </div>

    <?php require dirname(__DIR__) . '/Configuration/conformation-steps.php'; ?>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$hasStructure): ?>
        <div class="alert alert-info" role="status">
            Primero guarda la estructura de inmuebles.
            <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/conformacion">Ir a Conformación de la copropiedad</a>
        </div>
    <?php elseif ($users === []): ?>
        <div class="alert alert-info" role="status">
            No hay usuarios para asignar. Crea primero las cuentas en
            <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/equipo">Configuración / Equipo</a>.
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between small"><span class="text-secondary">Inmuebles con usuario</span><strong><?= $coveredUnits ?> de <?= $totalUnits ?></strong></div>
                        <div class="progress my-2" style="height: .4rem" role="progressbar" aria-label="Cobertura de asignación" aria-valuenow="<?= $coverage ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $coverage === 100 ? 'bg-success' : '' ?>" style="width: <?= $coverage ?>%"></div>
                        </div>
                        <p class="small text-secondary mb-0">
                            <?= $totalUnits - $coveredUnits === 0
                                ? 'Todos los inmuebles tienen al menos un usuario.'
                                : ($totalUnits - $coveredUnits) . ' inmueble(s) sin usuario no recibirán notas de cobro por correo.' ?>
                        </p>
                    </div>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent">
                        <h2 class="card-title h6 mb-2">Usuarios</h2>
                        <input id="user-filter" class="form-control form-control-sm" type="search" placeholder="Buscar usuario o correo" aria-label="Buscar usuario">
                    </div>
                    <div class="list-group list-group-flush" id="user-list" style="max-height: 60vh; overflow-y: auto">
                        <?php foreach ($users as $user): ?>
                            <?php $isSelected = $selectedUserId === (int) $user['id']; ?>
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2<?= $isSelected ? ' active' : '' ?>"
                               href="<?= $escape($basePath) ?>/inmuebles/asignaciones?usuario=<?= (int) $user['id'] ?>"
                               data-search="<?= $escape(mb_strtolower($user['username'] . ' ' . ($user['email'] ?? ''), 'UTF-8')) ?>"<?= $isSelected ? ' aria-current="true"' : '' ?>>
                                <span style="min-width: 0">
                                    <span class="d-block fw-semibold text-truncate"><?= $escape($user['username']) ?></span>
                                    <span class="d-block small text-truncate <?= $isSelected ? '' : 'text-secondary' ?>"><?= $escape($user['email'] ?? '') ?></span>
                                </span>
                                <span class="d-flex align-items-center gap-1 flex-shrink-0">
                                    <?php if (!(bool) $user['is_active']): ?><span class="badge text-bg-secondary">Inactivo</span><?php endif; ?>
                                    <span class="badge rounded-pill <?= (int) $user['assigned_count'] === 0 ? 'text-bg-warning' : ($isSelected ? 'text-bg-light' : 'text-bg-primary') ?>"
                                          title="Inmuebles asignados"><?= (int) $user['assigned_count'] ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <?php if (is_array($selectedUser)): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent">
                            <div>
                                <h2 class="h6 mb-0">Inmuebles de <?= $escape($selectedUser['username']) ?></h2>
                                <div class="small text-secondary"><?= $escape($selectedUser['email'] ?? '') ?> · <?= $escape($selectedUser['role_name'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!(bool) $selectedUser['is_active']): ?>
                                <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Este usuario está inactivo: no podrá entrar al portal ni ver estos inmuebles hasta que lo actives en Equipo.</div>
                            <?php endif; ?>

                            <?php if ($canWrite): ?>
                                <form id="unit-assignment-form" method="post" action="<?= $escape($basePath) ?>/inmuebles/asignaciones/guardar">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                                    <input id="unit-ids-json" type="hidden" name="unit_ids_json" value="">

                                    <div class="mb-3">
                                        <div class="small text-secondary mb-1">Seleccionados (<span id="selected-unit-count"><?= count($assignedUnitIds) ?></span>):</div>
                                        <div id="selected-chips" class="d-flex flex-wrap gap-1" aria-live="polite"></div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <input id="unit-search" class="form-control form-control-sm" style="max-width: 18rem" type="search" autocomplete="off" placeholder="Buscar: Torre 2, Apartamento 03, local…" aria-label="Buscar inmueble">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Mostrar">
                                            <input class="btn-check" type="radio" name="unit-view" id="unit-view-all" value="all" checked><label class="btn btn-outline-secondary" for="unit-view-all">Todos</label>
                                            <input class="btn-check" type="radio" name="unit-view" id="unit-view-selected" value="selected"><label class="btn btn-outline-secondary" for="unit-view-selected">Seleccionados</label>
                                            <input class="btn-check" type="radio" name="unit-view" id="unit-view-free" value="free"><label class="btn btn-outline-secondary" for="unit-view-free">Sin usuario</label>
                                        </div>
                                    </div>

                                    <div id="unit-assignment-list" class="border rounded" style="max-height: 50vh; overflow-y: auto;">
                                        <?php $currentGroup = null; ?>
                                        <?php foreach ($units as $unit): ?>
                                            <?php
                                            $unitId = (string) ($unit['id'] ?? '');
                                            $group = $groupOf($unit);
                                            $others = array_values(array_filter($unitOwners[$unitId] ?? [], static fn (array $owner): bool => $owner['id'] !== (int) $selectedUser['id']));
                                            ?>
                                            <?php if ($group !== $currentGroup): $currentGroup = $group; ?>
                                                <div class="unit-group-header px-3 py-1 bg-body-tertiary small fw-semibold text-secondary border-bottom sticky-top" data-group="<?= $escape($group) ?>"><?= $escape($group) ?></div>
                                            <?php endif; ?>
                                            <label class="d-flex gap-2 align-items-center px-3 py-2 border-bottom unit-assignment-row"
                                                   data-group="<?= $escape($group) ?>" data-free="<?= ($unitOwners[$unitId] ?? []) === [] ? '1' : '0' ?>"
                                                   data-search="<?= $escape(mb_strtolower(($unit['category'] ?? '') . ' ' . ($unit['name'] ?? ''), 'UTF-8')) ?>">
                                                <input class="form-check-input mt-0 unit-assignment-check" type="checkbox" value="<?= $escape($unitId) ?>"
                                                       data-name="<?= $escape($unit['name'] ?? '') ?>" <?= isset($assignedUnitLookup[$unitId]) ? 'checked data-initial="1"' : 'data-initial="0"' ?>>
                                                <span class="flex-grow-1">
                                                    <?= $escape($unit['name'] ?? '') ?>
                                                    <span class="text-secondary small"> · <?= $escape($unit['category'] ?? '') ?></span>
                                                </span>
                                                <?php if ($others !== []): ?>
                                                    <span class="badge text-bg-light border fw-normal" title="Este inmueble también está asignado a otros usuarios">
                                                        <i class="bi bi-people me-1" aria-hidden="true"></i><?= $escape(implode(', ', array_column($others, 'username'))) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                        <p id="no-unit-results" class="text-secondary text-center small py-4 mb-0" hidden>Ningún inmueble coincide con la búsqueda.</p>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                                        <button id="select-visible-units" class="btn btn-outline-secondary btn-sm" type="button">Marcar visibles</button>
                                        <button id="clear-visible-units" class="btn btn-outline-secondary btn-sm" type="button">Desmarcar visibles</button>
                                        <span id="assignment-changes" class="small ms-auto" aria-live="polite"></span>
                                        <button id="assignment-reset" class="btn btn-link btn-sm" type="button" hidden>Deshacer cambios</button>
                                        <button id="assignment-submit" class="btn btn-primary" type="submit" disabled>Guardar asignaciones</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <ul class="list-group">
                                    <?php foreach ($units as $unit): ?>
                                        <?php if (isset($assignedUnitLookup[(string) ($unit['id'] ?? '')])): ?>
                                            <li class="list-group-item"><?= $escape($unit['name'] ?? '') ?> <span class="text-secondary small">· <?= $escape($unit['category'] ?? '') ?></span></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($assignedUnitIds === []): ?><li class="list-group-item text-secondary">No tiene inmuebles asignados.</li><?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center text-secondary py-5">
                            <i class="bi bi-person-check fs-1 d-block mb-2" aria-hidden="true"></i>
                            Elige un usuario de la lista para ver o editar sus inmuebles.
                            <?php $withoutUnits = count(array_filter($users, static fn (array $u): bool => (int) $u['assigned_count'] === 0)); ?>
                            <?php if ($withoutUnits > 0): ?>
                                <div class="small mt-2"><span class="badge text-bg-warning"><?= $withoutUnits ?></span> usuario(s) aún no tienen inmuebles.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php if ($hasStructure && $users !== []): ?>
<script>
(() => {
    const userFilter = document.getElementById('user-filter');
    userFilter?.addEventListener('input', () => {
        const query = userFilter.value.trim().toLocaleLowerCase();
        document.querySelectorAll('#user-list [data-search]').forEach((item) => {
            item.classList.toggle('d-none', query !== '' && !item.dataset.search.includes(query));
        });
    });

    const form = document.getElementById('unit-assignment-form');
    if (!form) return;
    const search = document.getElementById('unit-search');
    const rows = [...document.querySelectorAll('.unit-assignment-row')];
    const headers = [...document.querySelectorAll('.unit-group-header')];
    const checkboxes = rows.map((row) => row.querySelector('.unit-assignment-check'));
    const chips = document.getElementById('selected-chips');
    const changes = document.getElementById('assignment-changes');
    const submit = document.getElementById('assignment-submit');
    const reset = document.getElementById('assignment-reset');
    let saving = false;

    const diff = () => {
        const added = checkboxes.filter((c) => c.checked && c.dataset.initial === '0');
        const removed = checkboxes.filter((c) => !c.checked && c.dataset.initial === '1');
        return { added, removed };
    };

    const refresh = () => {
        const selected = checkboxes.filter((c) => c.checked);
        document.getElementById('selected-unit-count').textContent = selected.length;
        chips.replaceChildren(...(selected.length === 0
            ? [Object.assign(document.createElement('span'), { className: 'small text-warning', textContent: 'Ningún inmueble seleccionado.' })]
            : selected.map((checkbox) => {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'btn btn-sm btn-primary py-0';
                chip.innerHTML = `<span></span> <i class="bi bi-x" aria-hidden="true"></i>`;
                chip.querySelector('span').textContent = checkbox.dataset.name;
                chip.setAttribute('aria-label', `Quitar ${checkbox.dataset.name}`);
                chip.addEventListener('click', () => { checkbox.checked = false; refresh(); });
                return chip;
            })));
        const { added, removed } = diff();
        const parts = [];
        if (added.length) parts.push(`<span class="text-success">+${added.length} por agregar</span>`);
        if (removed.length) parts.push(`<span class="text-danger">−${removed.length} por quitar</span>`);
        changes.innerHTML = parts.length ? parts.join(' · ') : '<span class="text-secondary">Sin cambios</span>';
        submit.disabled = parts.length === 0;
        reset.hidden = parts.length === 0;
    };

    const applyFilter = () => {
        const query = search.value.trim().toLocaleLowerCase();
        const view = form.querySelector('[name="unit-view"]:checked').value;
        const visibleGroups = new Set();
        rows.forEach((row) => {
            const checkbox = row.querySelector('.unit-assignment-check');
            const show = (query === '' || row.dataset.search.includes(query))
                && (view !== 'selected' || checkbox.checked)
                && (view !== 'free' || row.dataset.free === '1');
            row.classList.toggle('d-none', !show);
            if (show) visibleGroups.add(row.dataset.group);
        });
        headers.forEach((header) => header.classList.toggle('d-none', !visibleGroups.has(header.dataset.group)));
        document.getElementById('no-unit-results').hidden = visibleGroups.size > 0;
    };
    const visibleChecks = () => rows.filter((row) => !row.classList.contains('d-none')).map((row) => row.querySelector('.unit-assignment-check'));

    search.addEventListener('input', applyFilter);
    form.querySelectorAll('[name="unit-view"]').forEach((radio) => radio.addEventListener('change', applyFilter));
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refresh));
    document.getElementById('select-visible-units').addEventListener('click', () => {
        const targets = visibleChecks();
        if (targets.length > 20 && !window.confirm(`¿Marcar ${targets.length} inmuebles para este usuario?`)) return;
        targets.forEach((c) => { c.checked = true; });
        refresh();
    });
    document.getElementById('clear-visible-units').addEventListener('click', () => {
        visibleChecks().forEach((c) => { c.checked = false; });
        refresh();
    });
    reset.addEventListener('click', () => {
        checkboxes.forEach((c) => { c.checked = c.dataset.initial === '1'; });
        refresh();
        applyFilter();
    });

    form.addEventListener('submit', (event) => {
        const { removed } = diff();
        const remaining = checkboxes.filter((c) => c.checked).length;
        if (removed.length > 0) {
            const names = removed.slice(0, 5).map((c) => `• ${c.dataset.name}`).join('\n');
            const message = `Vas a quitar ${removed.length} inmueble(s) a este usuario:\n${names}${removed.length > 5 ? '\n…' : ''}`
                + (remaining === 0 ? '\n\nEl usuario quedará sin inmuebles y dejará de recibir notas de cobro.' : '')
                + '\n\n¿Continuar?';
            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }
        }
        document.getElementById('unit-ids-json').value = JSON.stringify(checkboxes.filter((c) => c.checked).map((c) => c.value));
        saving = true;
        submit.disabled = true;
        submit.textContent = 'Guardando…';
    });
    window.addEventListener('beforeunload', (event) => {
        const { added, removed } = diff();
        if (!saving && (added.length || removed.length)) event.preventDefault();
    });

    refresh();
})();
</script>
<?php endif; ?>
