<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusLabels = ['new' => ['Nuevo', 'success'], 'exists' => ['Ya registrado', 'secondary'], 'error' => ['Con error', 'danger']];
$counts = ['new' => 0, 'exists' => 0, 'error' => 0];
foreach ($review['rows'] ?? [] as $row) {
    $counts[$row['status']]++;
}
$columns = [
    'nombre_apellidos' => ['Nombre y apellidos de la persona, o razón social.', true],
    'tipo_documento' => ['CC, CE, TI, PP, PPT, NIT u OTRO. Si se omite: NIT cuando el número trae dígito de verificación (900123456-7), si no CC.', false],
    'documento' => ['Número de cédula o NIT, sin puntos.', true],
    'correo' => ['Correo electrónico: con él recibe su acceso y sus cobros. No se puede repetir.', true],
    'tipo_residente' => ['Uno de los tipos del catálogo (ver abajo).', false],
    'telefono' => ['Teléfono de contacto.', false],
    'inmueble' => ['Nombre del inmueble tal como aparece en la conformación (ej.: Torre 1 - Apartamento 101). Obligatorio para tipos que exigen propietario, como Inquilino: la fila solo es válida si ese inmueble ya tiene propietario, y el residente queda vinculado a él como inquilino.', false],
];
?>
<section class="content">
    <a class="small text-decoration-none d-inline-block mb-2" href="<?= $escape($basePath) ?>/comunidad/residentes"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Residentes</a>
    <h1 class="h3 mb-1"><i class="bi bi-filetype-csv me-2" aria-hidden="true"></i>Importar residentes</h1>
    <p class="text-secondary">Crea muchos residentes a la vez desde un archivo CSV. Primero se revisa el archivo fila por fila; nada se guarda hasta que confirmes.</p>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="alert"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($review === null): ?>
        <div class="row g-3">
            <div class="col-lg-5">
                <form class="card" method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/importar/revisar" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="card-header"><h2 class="card-title">1. Sube el archivo</h2></div>
                    <div class="card-body">
                        <label class="form-label" for="csv-file">Archivo CSV (hasta 1 MB y <?= number_format($maxRows, 0, ',', '.') ?> filas)</label>
                        <input class="form-control" id="csv-file" name="csv" type="file" accept=".csv,text/csv" required>
                        <div class="form-text">En Excel: <em>Archivo → Guardar como → CSV UTF-8</em>. Se acepta separador punto y coma, coma o tabulador.</div>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Revisar archivo</button>
                        <a class="ms-auto" href="<?= $escape($basePath) ?>/comunidad/residentes/importar/plantilla"><i class="bi bi-download me-1" aria-hidden="true"></i>Descargar plantilla</a>
                    </div>
                </form>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Estructura del archivo</h2></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Columna</th><th>Contenido</th></tr></thead>
                            <tbody>
                            <?php foreach ($columns as $name => [$description, $required]): ?>
                                <tr>
                                    <td class="text-nowrap"><code><?= $escape($name) ?></code><?= $required ? ' <span class="badge text-bg-primary">Obligatoria</span>' : '' ?></td>
                                    <td class="small"><?= $escape($description) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body small">
                        <p class="mb-2">La primera fila debe ser el encabezado; el orden de las columnas no importa.</p>
                        <p class="mb-0">
                            Tipos de residente disponibles:
                            <?php if ($types === []): ?>
                                <span class="text-secondary">ninguno activo.</span>
                            <?php else: ?>
                                <?= implode(', ', array_map(static fn (array $t): string => '<strong>' . $escape($t['name']) . '</strong>', $types)) ?>.
                            <?php endif; ?>
                            <a href="<?= $escape($basePath) ?>/comunidad/tipos-residente">Administrar tipos</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-3">
            <?php foreach ($statusLabels as $key => [$label, $color]): ?>
                <div class="col-md-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="small text-secondary"><?= $escape($label) ?></div>
                        <div class="h3 mb-0 text-<?= $color === 'secondary' ? 'body' : $color ?>"><?= $counts[$key] ?></div>
                        <div class="small text-secondary"><?= ['new' => 'se crearán al confirmar', 'exists' => 'se omiten (correo o documento ya registrado)', 'error' => 'se omiten: corrígelas y vuelve a subir el archivo'][$key] ?></div>
                    </div></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h2 class="card-title mb-0 me-auto">2. Revisa · <?= $escape((string) $review['file']) ?></h2>
                <select id="import-status-filter" class="form-select form-select-sm w-auto" aria-label="Mostrar filas">
                    <option value="">Todas las filas</option>
                    <option value="new">Nuevos</option>
                    <option value="exists">Ya registrados</option>
                    <option value="error">Con error</option>
                </select>
                <div id="import-grid-tools" class="d-flex gap-2"></div>
            </div>
            <div class="table-responsive" style="max-height: 60vh">
                <table id="import-table" class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th data-column="line" data-label="Fila" data-sortable>Fila</th>
                            <th data-column="status" data-label="Estado" data-sortable>Estado</th>
                            <th data-column="name" data-label="Nombre y apellidos" data-hideable="false" data-sortable>Nombre y apellidos</th>
                            <th data-column="document" data-label="Documento" data-sortable>Documento</th>
                            <th data-column="email" data-label="Correo" data-sortable>Correo</th>
                            <th data-column="type" data-label="Tipo de residente" data-sortable>Tipo</th>
                            <th data-column="unit" data-label="Inmueble" data-sortable>Inmueble</th>
                            <th data-column="detail" data-label="Observación">Observación</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($review['rows'] as $row): ?>
                        <?php [$label, $color] = $statusLabels[$row['status']]; $data = $row['data']; ?>
                        <tr data-status="<?= $escape($row['status']) ?>">
                            <td class="text-secondary small"><?= (int) $row['line'] ?></td>
                            <td><span class="badge text-bg-<?= $color ?>"><?= $escape($label) ?></span></td>
                            <td><?= $escape((string) $data['full_name']) ?></td>
                            <td class="small text-nowrap"><?= $escape(trim($data['document_type'] . ' ' . $data['document_number'])) ?></td>
                            <td class="small"><?= $escape((string) $data['email']) ?></td>
                            <td class="small"><?= $escape((string) $data['resident_type']) ?></td>
                            <td class="small"><?= $escape((string) ($data['unit_id'] !== '' ? $data['unit'] : '')) ?></td>
                            <td class="small <?= $row['status'] === 'error' ? 'text-danger' : 'text-secondary' ?>"><?= $escape((string) $row['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="import-grid-pager" class="px-3 py-2 border-top"></div>
            <form class="card-footer d-flex flex-wrap gap-3 align-items-center" method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/importar/confirmar"
                  onsubmit="this.querySelector('[type=submit]').disabled = true;">
                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="send-invitations" name="send_invitations" value="1">
                    <label class="form-check-label" for="send-invitations">Enviar a cada residente nuevo el correo para crear su contraseña (vence en 72 horas)</label>
                </div>
                <a class="ms-auto" href="<?= $escape($basePath) ?>/comunidad/residentes/importar?descartar=1">Descartar y subir otro archivo</a>
                <button class="btn btn-primary" type="submit"<?= $counts['new'] === 0 ? ' disabled' : '' ?>>
                    <i class="bi bi-person-check me-1" aria-hidden="true"></i>Crear <?= $counts['new'] ?> residente(s)
                </button>
            </form>
        </div>
        <script src="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/js/data-grid.js?v=1"></script>
        <script>
        (() => {
            const status = document.getElementById('import-status-filter');
            const grid = new DataGrid(document.getElementById('import-table'), {
                storageKey: 'resident-import',
                filter: (row) => status.value === '' || row.dataset.status === status.value,
                toolbar: document.getElementById('import-grid-tools'),
                pager: document.getElementById('import-grid-pager'),
                exportName: 'revision-importacion-residentes',
            });
            status.addEventListener('change', () => grid.refresh(true));
        })();
        </script>
    <?php endif; ?>
</section>
