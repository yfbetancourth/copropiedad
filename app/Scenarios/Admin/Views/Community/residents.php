<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = ['active' => 'Activos', 'inactive' => 'Inactivos', 'all' => 'Todos'];
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
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <ul class="nav nav-pills me-auto">
                <?php foreach ($filters as $key => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link py-1<?= $status === $key ? ' active' : '' ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>
                           href="<?= $escape($basePath) ?>/comunidad/residentes?estado=<?= $escape($key) ?><?= $search !== '' ? '&q=' . $escape(rawurlencode($search)) : '' ?>"><?= $escape($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="get" action="<?= $escape($basePath) ?>/comunidad/residentes" class="d-flex gap-2" role="search">
                <input type="hidden" name="estado" value="<?= $escape($status) ?>">
                <label class="visually-hidden" for="resident-search">Buscar residente</label>
                <input class="form-control form-control-sm" id="resident-search" name="q" type="search" value="<?= $escape($search) ?>" placeholder="Nombre, tipo, documento, correo, inmueble…">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Buscar</span></button>
            </form>
        </div>
        <?php if ($residents === []): ?>
            <div class="card-body text-center text-secondary py-5">
                <i class="bi bi-people fs-1 d-block mb-2" aria-hidden="true"></i>
                <?= $search !== '' ? 'Ningún residente coincide con «' . $escape($search) . '».' : 'No hay residentes en esta vista.' ?>
                <?php if ($canWrite && $search === '' && $status === 'active'): ?>
                    <div class="mt-2"><a href="<?= $escape($basePath) ?>/comunidad/residentes/nuevo">Crea el primero</a>.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Residente</th><th>Documento</th><th>Contacto</th><th>Inmuebles</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($residents as $resident): ?>
                        <?php $id = (int) $resident['id']; $active = (bool) $resident['is_active']; ?>
                        <tr>
                            <td>
                                <strong><?= $escape((string) ($resident['full_name'] ?: $resident['username'])) ?></strong>
                                <?php if ($resident['resident_type'] !== null): ?><span class="badge text-bg-light border ms-1"><?= $escape((string) $resident['resident_type']) ?></span><?php endif; ?>
                                <span class="d-block small text-secondary">Usuario: <?= $escape((string) $resident['username']) ?></span>
                            </td>
                            <td class="small"><?= $resident['document_number'] !== null ? $escape($resident['document_type'] . ' ' . $resident['document_number']) : '<span class="text-secondary">—</span>' ?></td>
                            <td class="small">
                                <?= $escape((string) $resident['email']) ?>
                                <?php if ($resident['phone'] !== null): ?><span class="d-block"><?= $escape((string) $resident['phone']) ?></span><?php endif; ?>
                            </td>
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
                                <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                    <?php if ($canWrite): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= $escape($basePath) ?>/comunidad/residentes/editar?id=<?= $id ?>">Editar</a>
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
                                            <button class="btn btn-sm btn-outline-<?= $active ? 'warning' : 'success' ?>" type="submit"><?= $active ? 'Desactivar' : 'Activar' ?></button>
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
            <div class="card-footer small text-secondary"><?= count($residents) ?> residente(s)</div>
        <?php endif; ?>
    </div>
</section>
