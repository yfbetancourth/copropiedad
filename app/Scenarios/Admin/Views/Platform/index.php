<?php

declare(strict_types=1);

use App\Core\ModuleCatalog;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeCount = count(array_filter($tenants, static fn (array $tenant): bool => $tenant['status'] === 'active'));

?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Conjuntos residenciales</h1>
            <p class="text-secondary mb-0">Clientes de la plataforma: crea conjuntos, habilita módulos y asigna su administrador.</p>
        </div>
        <a class="btn btn-primary" href="<?= $escape($basePath) ?>/clientes/nuevo"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo conjunto</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-sm-4">
            <div class="small-box text-bg-primary mb-0">
                <div class="inner"><h3><?= count($tenants) ?></h3><p>Conjuntos registrados</p></div>
                <i class="small-box-icon bi bi-buildings" aria-hidden="true"></i>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="small-box text-bg-success mb-0">
                <div class="inner"><h3><?= $activeCount ?></h3><p>Activos</p></div>
                <i class="small-box-icon bi bi-check2-circle" aria-hidden="true"></i>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="small-box text-bg-secondary mb-0">
                <div class="inner"><h3><?= count($tenants) - $activeCount ?></h3><p>Suspendidos</p></div>
                <i class="small-box-icon bi bi-pause-circle" aria-hidden="true"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if ($tenants === []): ?>
                <div class="text-center text-secondary p-5">
                    <i class="bi bi-buildings fs-1 d-block mb-2" aria-hidden="true"></i>
                    Aún no hay conjuntos. Crea el primero para asignarle su administrador.
                </div>
            <?php else: ?>
                <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-platform-index-1">
                    <table class="table table-hover align-middle mb-0">
                        <caption id="tbl-platform-index-1" class="visually-hidden">Conjuntos residenciales</caption>
                        <thead>
                        <tr>
                            <th scope="col">Conjunto</th>
                            <th scope="col">Dirección web</th>
                            <th scope="col">Módulos</th>
                            <th scope="col" class="text-end">Usuarios</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                            <tr>
                                <td>
                                    <strong><?= $escape((string) $tenant['name']) ?></strong>
                                    <?php if ((int) $tenant['admin_count'] === 0): ?>
                                        <div class="small text-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Sin administrador asignado</div>
                                    <?php endif; ?>
                                </td>
                                <td><a href="<?= $escape((string) $tenant['url']) ?>" target="_blank" rel="noopener"><?= $escape((string) $tenant['slug']) ?></a></td>
                                <td>
                                    <?php foreach (ModuleCatalog::MODULES as $moduleKey => $module): ?>
                                        <?php $on = (bool) ($tenant['modules'][$moduleKey] ?? false); ?>
                                        <i class="bi <?= $escape($module['icon']) ?> <?= $on ? 'text-success' : 'text-body-tertiary' ?> me-1"
                                           title="<?= $escape($module['label'] . ($on ? ' · habilitado' : ' · deshabilitado')) ?>"
                                           aria-label="<?= $escape($module['label'] . ($on ? ' habilitado' : ' deshabilitado')) ?>"></i>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-end"><?= (int) $tenant['user_count'] ?></td>
                                <td>
                                    <?php if ($tenant['status'] === 'active'): ?>
                                        <span class="badge text-bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Suspendido</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= $escape($basePath) ?>/clientes/editar?id=<?= (int) $tenant['id'] ?>">Administrar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
