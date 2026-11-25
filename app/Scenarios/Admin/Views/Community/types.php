<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-tags me-2" aria-hidden="true"></i>Tipos de residente</h1>
        <p class="text-secondary mb-0">Catálogo para clasificar a los residentes (por ejemplo, propietario, inquilino o familiar). Se elige en la ficha de cada residente y en la importación CSV.</p>
        <a class="small" href="<?= $escape($basePath) ?>/comunidad/residentes"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Residentes</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="<?= $canWrite ? 'col-lg-8' : 'col-12' ?>">
            <div class="card">
                <?php if ($types === []): ?>
                    <div class="card-body text-center text-secondary py-5">Aún no hay tipos de residente.<?= $canWrite ? ' Crea el primero con el formulario.' : '' ?></div>
                <?php else: ?>
                    <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-community-types-1">
                        <table class="table table-hover align-middle mb-0">
                            <caption id="tbl-community-types-1" class="visually-hidden">Tipos de residente</caption>
                            <thead><tr><th scope="col">Tipo</th><th scope="col" class="text-end">Residentes</th><th scope="col">Estado</th><?php if ($canWrite): ?><th scope="col" class="text-end">Acciones</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($types as $type): ?>
                                <?php $id = (int) $type['id']; ?>
                                <tr>
                                    <td>
                                        <strong><?= $escape($type['name']) ?></strong>
                                        <?php if ($type['requires_unit_owner']): ?><span class="badge text-bg-info ms-1" title="Solo se registra vinculado a un inmueble que ya tiene propietario">Exige propietario en el inmueble</span><?php endif; ?>
                                        <?php if ($type['description'] !== ''): ?><span class="d-block small text-secondary"><?= $escape($type['description']) ?></span><?php endif; ?>
                                        <?php if ($canWrite): ?>
                                            <form class="collapse mt-2" id="edit-type-<?= $id ?>" method="post" action="<?= $escape($basePath) ?>/comunidad/tipos-residente/guardar">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="type_id" value="<?= $id ?>">
                                                <div class="row g-2">
                                                    <div class="col-md-5"><input class="form-control form-control-sm" name="name" minlength="3" maxlength="80" required value="<?= $escape($type['name']) ?>" aria-label="Nombre del tipo"></div>
                                                    <div class="col-md-5"><input class="form-control form-control-sm" name="description" maxlength="255" value="<?= $escape($type['description']) ?>" aria-label="Descripción" placeholder="Descripción (opcional)"></div>
                                                    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit">Guardar</button></div>
                                                    <div class="col-12">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="requires_unit_owner" value="1" id="owner-<?= $id ?>"<?= $type['requires_unit_owner'] ? ' checked' : '' ?>>
                                                            <label class="form-check-label small" for="owner-<?= $id ?>">Exige propietario: solo se registra vinculado a un inmueble que ya tiene propietario</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($type['residents'] > 0): ?>
                                            <a href="<?= $escape($basePath) ?>/comunidad/residentes?estado=all&amp;q=<?= $escape(rawurlencode($type['name'])) ?>"><?= $type['residents'] ?></a>
                                        <?php else: ?>
                                            <span class="text-secondary">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge text-bg-<?= $type['is_active'] ? 'success' : 'secondary' ?>"><?= $type['is_active'] ? 'Activo' : 'Inactivo' ?></span></td>
                                    <?php if ($canWrite): ?>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                                <button class="btn btn-sm btn-outline-primary" type="button" data-collapse aria-controls="edit-type-<?= $id ?>" aria-expanded="false" aria-controls="edit-type-<?= $id ?>">Editar</button>
                                                <form method="post" action="<?= $escape($basePath) ?>/comunidad/tipos-residente/estado">
                                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                    <input type="hidden" name="type_id" value="<?= $id ?>">
                                                    <input type="hidden" name="active" value="<?= $type['is_active'] ? '0' : '1' ?>">
                                                    <button class="btn btn-sm btn-outline-<?= $type['is_active'] ? 'warning' : 'success' ?>" type="submit"><?= $type['is_active'] ? 'Desactivar' : 'Activar' ?></button>
                                                </form>
                                                <?php if ($canDelete): ?>
                                                    <form method="post" action="<?= $escape($basePath) ?>/comunidad/tipos-residente/eliminar"
                                                          data-confirm="¿Eliminar este tipo de residente?">
                                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                        <input type="hidden" name="type_id" value="<?= $id ?>">
                                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="<?= $type['residents'] > 0 ? 'Tiene residentes: desactívalo' : 'Eliminar' ?>"
                                                            <?= $type['residents'] > 0 ? 'disabled' : '' ?>><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Eliminar</span></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canWrite): ?>
            <div class="col-lg-4">
                <form class="card" method="post" action="<?= $escape($basePath) ?>/comunidad/tipos-residente/guardar">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="type_id" value="0">
                    <div class="card-header"><h2 class="card-title">Nuevo tipo</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="type-name">Nombre</label>
                            <input class="form-control" id="type-name" name="name" minlength="3" maxlength="80" required placeholder="Ej.: Arrendatario comercial">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="type-description">Descripción (opcional)</label>
                            <input class="form-control" id="type-description" name="description" maxlength="255">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="requires_unit_owner" value="1" id="type-requires-owner">
                            <label class="form-check-label" for="type-requires-owner">Exige propietario en el inmueble</label>
                            <div class="form-text">Para tipos como Inquilino: el residente solo se registra vinculado a un inmueble que ya tiene propietario.</div>
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear tipo</button></div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>
