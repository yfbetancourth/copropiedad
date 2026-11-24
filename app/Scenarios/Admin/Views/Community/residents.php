<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = ['active' => 'Activos', 'inactive' => 'Inactivos', 'all' => 'Todos'];
$activeCount = count(array_filter($residents, static fn (array $r): bool => (bool) $r['is_active']));
$counts = ['active' => $activeCount, 'inactive' => count($residents) - $activeCount, 'all' => count($residents)];
$typeNames = array_values(array_unique(array_filter(array_map(static fn (array $r): ?string => $r['resident_type'], $residents))));
foreach ($residentTypes as $type) {
    if (!in_array($type['name'], $typeNames, true)) {
        $typeNames[] = $type['name'];
    }
}
sort($typeNames, SORT_NATURAL | SORT_FLAG_CASE);
$hasSelection = $canWrite || $canDelete;
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-people me-2" aria-hidden="true"></i>Residentes</h1>
            <p class="text-secondary mb-0">Propietarios, inquilinos y residentes con acceso al portal. Aquí creas su cuenta y su ficha; sus inmuebles se vinculan en Asignación de inmuebles.</p>
        </div>
        <?php if ($canWrite): ?>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="<?= $escape($basePath) ?>/comunidad/residentes/importar"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Importar CSV</a>
                <a class="btn btn-primary" href="<?= $escape($basePath) ?>/comunidad/residentes/nuevo"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Nuevo residente</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
        <?php if ($residents === []): ?>
            <div class="card-body text-center text-secondary py-5">
                <i class="bi bi-people fs-1 d-block mb-2" aria-hidden="true"></i>
                Aún no hay residentes.
                <?php if ($canWrite): ?>
                    <div class="mt-2"><a href="<?= $escape($basePath) ?>/comunidad/residentes/nuevo">Crea el primero</a> o <a href="<?= $escape($basePath) ?>/comunidad/residentes/importar">impórtalos desde un CSV</a>.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm me-auto" role="group" aria-label="Estado">
                    <?php foreach ($filters as $key => $label): ?>
                        <input class="btn-check" type="radio" name="resident-status" id="status-<?= $escape($key) ?>" value="<?= $escape($key) ?>"<?= $status === $key ? ' checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="status-<?= $escape($key) ?>"><?= $escape($label) ?> <span class="badge text-bg-light border ms-1"><?= $counts[$key] ?></span></label>
                    <?php endforeach; ?>
                </div>
                <label class="visually-hidden" for="resident-search">Buscar residente</label>
                <input class="form-control form-control-sm" style="max-width: 16rem" id="resident-search" type="search" value="<?= $escape($search) ?>" placeholder="Nombre, documento, correo, inmueble…">
                <label class="visually-hidden" for="resident-type-filter">Tipo de residente</label>
                <select class="form-select form-select-sm w-auto" id="resident-type-filter">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($typeNames as $name): ?>
                        <option value="<?= $escape($name) ?>"><?= $escape($name) ?></option>
                    <?php endforeach; ?>
                    <option value="__none">Sin clasificar</option>
                </select>
                <label class="visually-hidden" for="resident-unit-filter">Inmuebles</label>
                <select class="form-select form-select-sm w-auto" id="resident-unit-filter">
                    <option value="">Con y sin inmueble</option>
                    <option value="with">Con inmueble</option>
                    <option value="without">Sin inmueble</option>
                </select>
                <div id="resident-grid-tools" class="d-flex flex-wrap gap-2"></div>
            </div>
            <div id="resident-grid-bulk" class="px-3 py-2 bg-primary-subtle border-bottom" hidden></div>
            <div class="table-responsive" style="max-height: 70vh">
                <table id="resident-table" class="table table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th scope="col" data-column="name" data-label="Residente" data-hideable="false" data-sortable>Residente</th>
                            <th scope="col" data-column="type" data-label="Tipo" data-sortable>Tipo</th>
                            <th scope="col" data-column="document" data-label="Documento" data-sortable>Documento</th>
                            <th scope="col" data-column="email" data-label="Correo" data-sortable>Correo</th>
                            <th scope="col" data-column="phone" data-label="Teléfono">Teléfono</th>
                            <th scope="col" data-column="units" data-label="Inmuebles" data-sortable>Inmuebles</th>
                            <th scope="col" data-column="status" data-label="Estado" data-sortable>Estado</th>
                            <th scope="col" class="text-end" data-export="false"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($residents as $resident): ?>
                        <?php
                        $id = (int) $resident['id'];
                        $active = (bool) $resident['is_active'];
                        $name = (string) ($resident['full_name'] ?: $resident['username']);
                        ?>
                        <tr class="resident-row" data-id="<?= $id ?>" data-active="<?= $active ? '1' : '0' ?>" data-type="<?= $escape((string) ($resident['resident_type'] ?? '')) ?>"
                            data-has-units="<?= $resident['units'] === [] ? '0' : '1' ?>" data-name="<?= $escape($name) ?>" data-units="<?= $escape(implode(', ', array_column($resident['units'], 'name'))) ?>">
                            <td>
                                <strong><?= $escape($name) ?></strong>
                                <span class="d-block small text-secondary">Usuario: <?= $escape((string) $resident['username']) ?></span>
                            </td>
                            <td class="small"><?= $resident['resident_type'] !== null ? '<span class="badge text-bg-light border">' . $escape((string) $resident['resident_type']) . '</span>' : '<span class="text-secondary">—</span>' ?></td>
                            <td class="small text-nowrap"><?= $resident['document_number'] !== null ? $escape($resident['document_type'] . ' ' . $resident['document_number']) : '<span class="text-secondary">—</span>' ?></td>
                            <td class="small"><?= $escape((string) $resident['email']) ?></td>
                            <td class="small text-nowrap"><?= $resident['phone'] !== null ? $escape((string) $resident['phone']) : '<span class="text-secondary">—</span>' ?></td>
                            <td class="small">
                                <?php if ($resident['units'] === []): ?>
                                    <span class="text-warning"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Sin inmueble</span>
                                <?php else: ?>
                                    <?php foreach ($resident['units'] as $unit): ?>
                                        <span class="d-block"><?= $escape($unit['name']) ?> <span class="text-secondary">· <?= $escape($unit['relation']) ?></span></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>"><?= $active ? 'Activo' : 'Inactivo' ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex flex-nowrap gap-1 justify-content-end">
                                    <?php if ($canWrite): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= $escape($basePath) ?>/comunidad/residentes/editar?id=<?= $id ?>" title="Editar"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Editar</span></a>
                                    <?php endif; ?>
                                    <?php if ($canAssign): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/inmuebles/asignaciones?usuario=<?= $id ?>" title="Vincular inmuebles"><i class="bi bi-house-door" aria-hidden="true"></i><span class="visually-hidden">Inmuebles</span></a>
                                    <?php endif; ?>
                                    <?php if ($canWrite && $active): ?>
                                        <form method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/invitar">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $id ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit" title="Enviar correo para crear contraseña"><i class="bi bi-envelope" aria-hidden="true"></i><span class="visually-hidden">Enviar acceso</span></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canWrite): ?>
                                        <form method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/estado"
                                              <?= $active ? 'onsubmit="return confirm(\'¿Desactivar a este residente? No podrá entrar al portal.\');"' : '' ?>>
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $id ?>">
                                            <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                                            <button class="btn btn-sm btn-outline-<?= $active ? 'warning' : 'success' ?>" type="submit" title="<?= $active ? 'Desactivar' : 'Activar' ?>"><i class="bi bi-<?= $active ? 'pause-circle' : 'play-circle' ?>" aria-hidden="true"></i><span class="visually-hidden"><?= $active ? 'Desactivar' : 'Activar' ?></span></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <form method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/eliminar"
                                              onsubmit="return confirm('¿Eliminar definitivamente a este residente? Se borran su cuenta, sus vínculos con inmuebles, reservas y clasificados. Si tiene cobros o pagos registrados no se podrá: desactívalo.');">
                                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $id ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Eliminar"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Eliminar</span></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="resident-grid-pager" class="px-3 py-2 border-top"></div>
        <?php endif; ?>
    </div>

    <?php if ($residents !== [] && $hasSelection): ?>
        <form id="resident-bulk-form" method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/lote" hidden>
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="">
            <input type="hidden" name="estado" value="<?= $escape($status) ?>">
        </form>
        <div class="modal fade" id="resident-bulk-modal" tabindex="-1" aria-labelledby="resident-bulk-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="resident-bulk-title">Acción en lote</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p id="resident-bulk-text" class="mb-2"></p>
                        <p id="resident-bulk-note" class="small text-secondary mb-0"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" id="resident-bulk-confirm">Confirmar</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php if ($residents !== []): ?>
<script src="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/js/data-grid.js?v=1"></script>
<script>
(() => {
    const table = document.getElementById('resident-table');
    const search = document.getElementById('resident-search');
    const typeFilter = document.getElementById('resident-type-filter');
    const unitFilter = document.getElementById('resident-unit-filter');
    const statusInputs = [...document.querySelectorAll('input[name="resident-status"]')];
    const bulkForm = document.getElementById('resident-bulk-form');
    const canWrite = <?= $canWrite ? 'true' : 'false' ?>;
    const canDelete = <?= $canDelete ? 'true' : 'false' ?>;

    // Búsqueda sin tildes ni mayúsculas, sobre todo el texto de la fila.
    const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    const haystacks = new Map();
    const haystack = (row) => {
        // Sin la celda de acciones: buscar «eliminar» no debe coincidir con el texto de los botones.
        if (!haystacks.has(row)) haystacks.set(row, normalize([...row.cells].slice(0, -1).map((cell) => cell.textContent).join(' ')));
        return haystacks.get(row);
    };
    const status = () => statusInputs.find((input) => input.checked)?.value || 'active';

    const filter = (row) => {
        const state = status();
        if (state === 'active' && row.dataset.active !== '1') return false;
        if (state === 'inactive' && row.dataset.active !== '0') return false;
        const type = typeFilter.value;
        if (type === '__none' ? row.dataset.type !== '' : type !== '' && row.dataset.type !== type) return false;
        if (unitFilter.value === 'with' && row.dataset.hasUnits !== '1') return false;
        if (unitFilter.value === 'without' && row.dataset.hasUnits !== '0') return false;
        const terms = normalize(search.value).split(' ').filter(Boolean);
        return terms.every((term) => haystack(row).includes(term));
    };

    // Confirmación de las acciones en lote: explica a cuántos aplica y a cuántos no.
    const modalElement = document.getElementById('resident-bulk-modal');
    let pending = null;
    const ask = (rows, { action, title, verb, applies, skippedNote, danger = false }) => {
        const targets = rows.filter(applies);
        const skipped = rows.length - targets.length;
        document.getElementById('resident-bulk-title').textContent = title;
        const confirm = document.getElementById('resident-bulk-confirm');
        confirm.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;
        confirm.disabled = targets.length === 0;
        confirm.textContent = targets.length === 0 ? 'Nada que hacer' : `${verb} ${targets.length}`;
        document.getElementById('resident-bulk-text').textContent = targets.length === 0
            ? `Ninguno de los ${rows.length} seleccionados aplica: ${skippedNote}.`
            : `Se va a ${verb.toLowerCase()} a ${targets.length} residente(s)`
                + (targets.length <= 5 ? `: ${targets.map((row) => row.dataset.name).join(', ')}.` : '.');
        const notes = [];
        if (skipped > 0 && targets.length > 0) notes.push(`${skipped} de la selección se omiten: ${skippedNote}.`);
        if (action === 'delete') notes.push('Se borran sus cuentas, sus vínculos con inmuebles, reservas y clasificados. Quien tenga cobros, pagos o mensajes no se elimina: desactívalo.');
        if (action === 'invite') notes.push('Cada uno recibe un correo para crear su contraseña (vence en 72 horas).');
        if (action === 'deactivate') notes.push('No podrán entrar al portal; su historial se conserva.');
        document.getElementById('resident-bulk-note').textContent = notes.join(' ');
        pending = { action, ids: targets.map((row) => row.dataset.id) };
        bootstrap.Modal.getOrCreateInstance(modalElement).show(); // Bootstrap se carga al final de la página
    };
    document.getElementById('resident-bulk-confirm')?.addEventListener('click', (event) => {
        if (!pending || pending.ids.length === 0) return;
        event.currentTarget.disabled = true;
        bulkForm.elements.action.value = pending.action;
        bulkForm.elements.estado.value = status();
        bulkForm.querySelectorAll('input[name="user_ids[]"]').forEach((input) => input.remove());
        pending.ids.forEach((id) => bulkForm.append(Object.assign(document.createElement('input'), { type: 'hidden', name: 'user_ids[]', value: id })));
        bulkForm.submit();
    });

    const isActive = (row) => row.dataset.active === '1';
    const bulkActions = [];
    if (canWrite) {
        bulkActions.push(
            { label: 'Enviar acceso', icon: 'bi-envelope', className: 'btn-outline-primary',
              run: (rows) => ask(rows, { action: 'invite', title: 'Enviar acceso al portal', verb: 'Invitar', applies: isActive, skippedNote: 'están inactivos' }) },
            { label: 'Activar', icon: 'bi-play-circle', className: 'btn-outline-success',
              run: (rows) => ask(rows, { action: 'activate', title: 'Activar residentes', verb: 'Activar', applies: (row) => !isActive(row), skippedNote: 'ya están activos' }) },
            { label: 'Desactivar', icon: 'bi-pause-circle', className: 'btn-outline-warning',
              run: (rows) => ask(rows, { action: 'deactivate', title: 'Desactivar residentes', verb: 'Desactivar', applies: isActive, skippedNote: 'ya están inactivos' }) },
        );
    }
    if (canDelete) {
        bulkActions.push({ label: 'Eliminar', icon: 'bi-trash', className: 'btn-outline-danger',
            run: (rows) => ask(rows, { action: 'delete', title: 'Eliminar residentes', verb: 'Eliminar', applies: () => true, skippedNote: '', danger: true }) });
    }

    const grid = new DataGrid(table, {
        storageKey: 'residents',
        rowSelector: '.resident-row',
        filter,
        sortValue: (row, key) => (key === 'name' ? row.dataset.name : key === 'units' ? row.dataset.units : undefined),
        selectable: bulkActions.length > 0,
        bulkActions,
        toolbar: document.getElementById('resident-grid-tools'),
        bulkBar: document.getElementById('resident-grid-bulk'),
        pager: document.getElementById('resident-grid-pager'),
        exportName: 'residentes',
    });

    // El estado y la búsqueda quedan en la dirección: al recargar o volver se conserva la vista.
    const syncUrl = () => {
        const url = new URL(window.location.href);
        url.searchParams.set('estado', status());
        if (search.value.trim() !== '') url.searchParams.set('q', search.value.trim()); else url.searchParams.delete('q');
        window.history.replaceState(null, '', url);
    };
    let timer = null;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => { grid.refresh(true); syncUrl(); }, 150);
    });
    [typeFilter, unitFilter, ...statusInputs].forEach((input) => input.addEventListener('change', () => { grid.refresh(true); syncUrl(); }));
})();
</script>
<?php endif; ?>
