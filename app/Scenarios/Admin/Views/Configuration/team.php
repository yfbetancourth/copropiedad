<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$editing = is_array($editingUser);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.equipo', 2);
$canManage = \App\Core\Authorization::hasPermission($actor, 'configuracion.equipo', 3);

?>
<section class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Configuracion</h1>
            <p class="text-secondary mb-0">Equipo · administra las cuentas de la aplicacion.</p>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <?php if ($canWrite): ?>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title"><?= $editing ? 'Editar usuario' : 'Agregar usuario' ?></h2></div>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/equipo/guardar">
                        <div class="card-body">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="user_id" value="<?= $editing ? (int) $editingUser['id'] : '' ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">Nombre de usuario</label>
                                <input id="username" name="username" class="form-control" minlength="3" maxlength="64" required value="<?= $editing ? $escape($editingUser['username']) : '' ?>">
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Correo electronico</label>
                                <input id="email" name="email" type="email" class="form-control" maxlength="254" required value="<?= $editing ? $escape($editingUser['email']) : '' ?>">
                            </div>
                            <div class="mb-3">
                                <label for="role_id" class="form-label">Rol</label>
                                <select id="role_id" name="role_id" class="form-select" required>
                                    <option value="">Selecciona un rol</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>" <?= $editing && (int) $editingUser['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= $escape($role['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($roles === []): ?>
                                    <small class="text-danger">Crea primero un rol personalizado en Configuracion / Roles.</small>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label"><?= $editing ? 'Nueva contrasena (opcional)' : 'Contrasena inicial' ?></label>
                                <input id="password" name="password" type="password" class="form-control" minlength="12" <?= $editing ? '' : 'required' ?> autocomplete="new-password">
                                <small class="text-secondary">Minimo 12 caracteres<?= $editing ? '; dejala vacia para conservarla' : '' ?>.</small>
                            </div>
                            <?php if ($editing): ?>
                                <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/equipo">Cancelar</a>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit" <?= $roles === [] ? 'disabled' : '' ?>><?= $editing ? 'Guardar cambios' : 'Crear usuario' ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="<?= $canWrite ? 'col-lg-8' : 'col-12' ?>">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Usuarios</h2></div>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead>
                        <tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><?php if ($canWrite || $canManage): ?><th>Acciones</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $escape($user['username']) ?></td>
                                <td><?= $escape($user['email']) ?></td>
                                <td><?= $escape($user['role_name']) ?></td>
                                <td><span class="badge text-bg-<?= (bool) $user['is_active'] ? 'success' : 'secondary' ?>"><?= (bool) $user['is_active'] ? 'Activo' : 'Inactivo' ?></span></td>
                                <?php if ($canWrite || $canManage): ?>
                                    <td class="text-nowrap">
                                        <?php if ($canWrite && !(bool) $user['is_system']): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= $escape($basePath) ?>/configuracion/equipo?editar=<?= (int) $user['id'] ?>">Editar</a>
                                        <?php endif; ?>
                                        <?php if ($canManage && !(bool) $user['is_system']): ?>
                                            <form class="d-inline" method="post" action="<?= $escape($basePath) ?>/configuracion/equipo/estado">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                <input type="hidden" name="active" value="<?= (bool) $user['is_active'] ? '0' : '1' ?>">
                                                <button class="btn btn-outline-<?= (bool) $user['is_active'] ? 'danger' : 'success' ?> btn-sm" type="submit"><?= (bool) $user['is_active'] ? 'Desactivar' : 'Activar' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?>
                            <tr><td colspan="5" class="text-center text-secondary py-4">No hay usuarios registrados.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-secondary">Las cuentas Superadmin del sistema estan protegidas. Desactivar un usuario invalida sus sesiones.</div>
            </div>
        </div>
    </div>
</section>
