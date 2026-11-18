<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$editing = is_array($editingRole) && !(bool) $editingRole['is_system'];
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.roles', 2);
$canManage = \App\Core\Authorization::hasPermission($actor, 'configuracion.roles', 3);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Configuracion</h1>
        <p class="text-secondary mb-0">Roles y permisos · define que puede consultar y modificar cada rol.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <?php if ($canWrite): ?>
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h2 class="card-title"><?= $editing ? 'Editar rol' : 'Crear rol' ?></h2></div>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/roles/guardar">
                        <div class="card-body">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="role_id" value="<?= $editing ? (int) $editingRole['id'] : '' ?>">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nombre del rol</label>
                                <input id="name" name="name" class="form-control" minlength="2" maxlength="64" required value="<?= $editing ? $escape($editingRole['name']) : '' ?>">
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Descripcion</label>
                                <input id="description" name="description" class="form-control" maxlength="255" value="<?= $editing ? $escape($editingRole['description']) : '' ?>">
                            </div>
                            <h3 class="h6">Permisos por modulo</h3>
                            <?php foreach ($modules as $moduleKey => $label): ?>
                                <div class="mb-3">
                                    <label for="permission-<?= $escape($moduleKey) ?>" class="form-label"><?= $escape($label) ?></label>
                                    <select id="permission-<?= $escape($moduleKey) ?>" name="permissions[<?= $escape($moduleKey) ?>]" class="form-select">
                                        <?php foreach ($levels as $level => $levelLabel): ?>
                                            <?php $selected = $editing ? ($editingRole['permissions'][$moduleKey] ?? 'none') : ($moduleKey === 'panel' ? 'read' : 'none'); ?>
                                            <option value="<?= $escape($level) ?>" <?= $selected === $level ? 'selected' : '' ?>><?= $escape($levelLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                            <p class="small text-secondary">Escritura permite crear y editar. Total permite todas las acciones, incluida la desactivacion o eliminacion. No puedes conceder un nivel superior al tuyo.</p>
                            <?php if ($editing): ?>
                                <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/roles">Cancelar</a>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit"><?= $editing ? 'Guardar rol' : 'Crear rol' ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="<?= $canManage ? 'col-lg-7' : 'col-12' ?>">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Roles registrados</h2></div>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead><tr><th>Rol</th><th>Usuarios</th><th>Permisos</th><?php if ($canWrite || $canManage): ?><th>Acciones</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($roles as $role): ?>
                            <tr>
                                <td><strong><?= $escape($role['name']) ?></strong><?= $role['description'] !== '' ? '<br><small class="text-secondary">' . $escape($role['description']) . '</small>' : '' ?><?= (bool) $role['is_system'] ? '<br><span class="badge text-bg-secondary">Protegido</span>' : '' ?></td>
                                <td><?= (int) $role['user_count'] ?></td>
                                <td>
                                    <?php foreach ($modules as $moduleKey => $label): ?>
                                        <?php $level = (bool) $role['is_system'] ? 'total' : ($role['permissions'][$moduleKey] ?? 'none'); ?>
                                        <?php if ($level !== 'none'): ?>
                                            <div><small><?= $escape($label) ?>: <?= $escape($levels[$level] ?? $level) ?></small></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <?php if ($canWrite || $canManage): ?>
                                    <td class="text-nowrap">
                                        <?php if (!(bool) $role['is_system'] && $canWrite): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= $escape($basePath) ?>/configuracion/roles?editar=<?= (int) $role['id'] ?>">Editar</a>
                                        <?php endif; ?>
                                        <?php if (!(bool) $role['is_system'] && $canManage && (int) $role['user_count'] === 0): ?>
                                            <form class="d-inline" method="post" action="<?= $escape($basePath) ?>/configuracion/roles/eliminar" onsubmit="return confirm('Eliminar este rol?')">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-secondary">El rol Superadmin es del sistema y no se puede editar ni eliminar.</div>
            </div>
        </div>
    </div>
</section>
