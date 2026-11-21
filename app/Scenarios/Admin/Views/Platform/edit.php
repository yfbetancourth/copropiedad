<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$domainSuffix = $baseDomain !== '' ? '.' . $baseDomain : '';
$isActive = $tenant['status'] === 'active';
$tenantId = (int) $tenant['id'];

?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">
                <?= $escape((string) $tenant['name']) ?>
                <?php if ($isActive): ?>
                    <span class="badge text-bg-success align-middle fs-6">Activo</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary align-middle fs-6">Suspendido</span>
                <?php endif; ?>
            </h1>
            <p class="text-secondary mb-0">
                <a href="<?= $escape((string) $tenant['url']) ?>" target="_blank" rel="noopener"><?= $escape((string) $tenant['url']) ?></a>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/clientes">Volver</a>
            <a class="btn btn-primary" href="<?= $escape((string) $tenant['admin_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Entrar al conjunto</a>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h2 class="card-title">Datos del conjunto</h2></div>
                <form method="post" action="<?= $escape($basePath) ?>/clientes/actualizar">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nombre</label>
                            <input id="name" name="name" class="form-control" minlength="3" maxlength="160" required value="<?= $escape((string) $tenant['name']) ?>">
                        </div>
                        <div>
                            <label for="slug" class="form-label">Subdominio</label>
                            <div class="input-group">
                                <input id="slug" name="slug" class="form-control" maxlength="63" required pattern="[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?" value="<?= $escape((string) $tenant['slug']) ?>" aria-describedby="slug-help">
                                <?php if ($domainSuffix !== ''): ?><span class="input-group-text"><?= $escape($domainSuffix) ?></span><?php endif; ?>
                            </div>
                            <small id="slug-help" class="text-warning-emphasis">Cambiarlo cambia la dirección web del conjunto y cierra las sesiones abiertas allí. Actualiza también el webhook de Bold si lo usa.</small>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary" type="submit">Guardar datos</button>
                    </div>
                </form>
            </div>

            <div class="card mb-3" id="modulos">
                <div class="card-header"><h2 class="card-title">Módulos habilitados</h2></div>
                <form method="post" action="<?= $escape($basePath) ?>/clientes/modulos">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <?php foreach ($modules as $moduleKey => $module): ?>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="modules[]" value="<?= $escape($moduleKey) ?>" id="module-<?= $escape($moduleKey) ?>" <?= ($tenant['modules'][$moduleKey] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="module-<?= $escape($moduleKey) ?>">
                                    <i class="bi <?= $escape($module['icon']) ?> me-1" aria-hidden="true"></i><strong><?= $escape($module['label']) ?></strong>
                                    <span class="d-block small text-secondary"><?= $escape($module['description']) ?>
                                        <?php if ($module['requires'] !== []): ?>
                                            Requiere <?= $escape(implode(', ', array_map(static fn (string $required): string => $modules[$required]['label'], $module['requires']))) ?>.
                                        <?php endif; ?>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <p class="small text-secondary mb-0">Deshabilitar un módulo oculta sus opciones para todos en el conjunto, incluido el administrador. Los datos no se borran: vuelven a verse al habilitarlo de nuevo.</p>
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary" type="submit">Guardar módulos</button>
                    </div>
                </form>
            </div>

            <div class="card border-<?= $isActive ? 'danger' : 'success' ?>">
                <div class="card-header"><h2 class="card-title">Estado del servicio</h2></div>
                <div class="card-body">
                    <?php if ($isActive): ?>
                        <p class="mb-3">Suspender bloquea el sitio público y el ingreso de todas las cuentas del conjunto. Tú conservas el acceso. Los datos no se modifican.</p>
                    <?php else: ?>
                        <p class="mb-3">El conjunto está suspendido: su sitio y sus cuentas no tienen acceso.</p>
                    <?php endif; ?>
                    <form method="post" action="<?= $escape($basePath) ?>/clientes/estado"
                          onsubmit="return <?= $isActive ? "confirm('¿Suspender el servicio de este conjunto?')" : 'true' ?>;">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <input type="hidden" name="status" value="<?= $isActive ? 'suspended' : 'active' ?>">
                        <button class="btn btn-<?= $isActive ? 'outline-danger' : 'success' ?>" type="submit">
                            <?= $isActive ? 'Suspender conjunto' : 'Reactivar conjunto' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6" id="administradores">
            <div class="card mb-3">
                <div class="card-header"><h2 class="card-title">Administradores del conjunto</h2></div>
                <div class="card-body p-0">
                    <?php if ($tenant['admins'] === []): ?>
                        <p class="text-danger p-3 mb-0"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Este conjunto no tiene administrador. Asigna uno abajo.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($tenant['admins'] as $admin): ?>
                                <?php $adminActive = (bool) $admin['is_active']; $adminId = (int) $admin['id']; ?>
                                <li class="list-group-item">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <div>
                                            <strong><?= $escape((string) $admin['username']) ?></strong>
                                            <?php if (!$adminActive): ?><span class="badge text-bg-secondary ms-1">Inactivo</span><?php endif; ?>
                                            <div class="small text-secondary"><?= $escape((string) $admin['email']) ?></div>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#password-<?= $adminId ?>" aria-expanded="false" aria-controls="password-<?= $adminId ?>">Cambiar contraseña</button>
                                            <form method="post" action="<?= $escape($basePath) ?>/clientes/administrador/estado">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                                <input type="hidden" name="user_id" value="<?= $adminId ?>">
                                                <input type="hidden" name="active" value="<?= $adminActive ? '0' : '1' ?>">
                                                <button class="btn btn-sm btn-outline-<?= $adminActive ? 'danger' : 'success' ?>" type="submit"><?= $adminActive ? 'Desactivar' : 'Activar' ?></button>
                                            </form>
                                        </div>
                                    </div>
                                    <form class="collapse mt-2" id="password-<?= $adminId ?>" method="post" action="<?= $escape($basePath) ?>/clientes/administrador/clave">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                        <input type="hidden" name="user_id" value="<?= $adminId ?>">
                                        <label class="form-label small" for="admin-password-<?= $adminId ?>">Nueva contraseña (mínimo 12 caracteres)</label>
                                        <div class="input-group input-group-sm">
                                            <input class="form-control" id="admin-password-<?= $adminId ?>" name="admin_password" type="password" minlength="12" required autocomplete="new-password">
                                            <button class="btn btn-primary" type="submit">Guardar</button>
                                        </div>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Asignar otro administrador</h2></div>
                <form method="post" action="<?= $escape($basePath) ?>/clientes/administrador">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <div class="mb-3">
                            <label for="admin_username" class="form-label">Usuario</label>
                            <input id="admin_username" name="admin_username" class="form-control" minlength="3" maxlength="64" required autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label for="admin_email" class="form-label">Correo electrónico</label>
                            <input id="admin_email" name="admin_email" type="email" class="form-control" maxlength="254" required autocomplete="off">
                        </div>
                        <div>
                            <label for="admin_password" class="form-label">Contraseña inicial</label>
                            <input id="admin_password" name="admin_password" type="password" class="form-control" minlength="12" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary" type="submit">Asignar administrador</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
