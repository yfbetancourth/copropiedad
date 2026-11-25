<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = is_array($old ?? null) ? $old : null;
$value = static fn (string $key): string => is_string($old[$key] ?? null) ? $old[$key] : '';
$checkedModules = $old !== null && is_array($old['modules'] ?? null) ? $old['modules'] : array_keys($modules);
$domainSuffix = $baseDomain !== '' ? '.' . $baseDomain : '';

?>
<section class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Nuevo conjunto residencial</h1>
            <p class="text-secondary mb-0">Crea el cliente, elige sus módulos y asigna el administrador que lo gestionará.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/clientes">Volver</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="alert"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($basePath) ?>/clientes/guardar" id="tenant-form">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header"><h2 class="card-title"><span class="badge text-bg-primary me-2">1</span>Datos del conjunto</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nombre del conjunto</label>
                            <input id="name" name="name" class="form-control" minlength="3" maxlength="160" required value="<?= $escape($value('name')) ?>" placeholder="Conjunto Residencial Torres del Parque">
                        </div>
                        <div>
                            <label for="slug" class="form-label">Subdominio</label>
                            <div class="input-group">
                                <input id="slug" name="slug" class="form-control" maxlength="63" pattern="[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?" value="<?= $escape($value('slug')) ?>" placeholder="torres-del-parque" aria-describedby="slug-help">
                                <?php if ($domainSuffix !== ''): ?><span class="input-group-text"><?= $escape($domainSuffix) ?></span><?php endif; ?>
                            </div>
                            <small id="slug-help" class="text-secondary">Dirección web del conjunto: su página pública, el portal de residentes y el panel del administrador. Letras minúsculas, números y guiones. Si lo dejas vacío se genera a partir del nombre.</small>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2 class="card-title"><span class="badge text-bg-primary me-2">2</span>Módulos habilitados</h2></div>
                    <div class="card-body">
                        <p class="text-secondary small">La configuración básica, el equipo, los roles, la caracterización, la conformación y la asignación de inmuebles siempre están disponibles. Podrás cambiar los módulos en cualquier momento.</p>
                        <div class="row g-2">
                            <?php foreach ($modules as $moduleKey => $module): ?>
                                <div class="col-md-6">
                                    <div class="form-check border rounded p-3 ps-5 h-100">
                                        <input class="form-check-input" type="checkbox" name="modules[]" value="<?= $escape($moduleKey) ?>" id="module-<?= $escape($moduleKey) ?>" <?= in_array($moduleKey, $checkedModules, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label d-block" for="module-<?= $escape($moduleKey) ?>">
                                            <i class="bi <?= $escape($module['icon']) ?> me-1" aria-hidden="true"></i><strong><?= $escape($module['label']) ?></strong>
                                            <span class="d-block small text-secondary"><?= $escape($module['description']) ?></span>
                                            <?php if ($module['requires'] !== []): ?>
                                                <span class="d-block small text-secondary fst-italic">Requiere: <?= $escape(implode(', ', array_map(static fn (string $required): string => $modules[$required]['label'], $module['requires']))) ?></span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h2 class="card-title"><span class="badge text-bg-primary me-2">3</span>Administrador asignado</h2></div>
                    <div class="card-body">
                        <p class="text-secondary small">Tendrá acceso total dentro de este conjunto: caracterización, conformación, comunidad, roles y los módulos habilitados.</p>
                        <div class="mb-3">
                            <label for="admin_username" class="form-label">Usuario</label>
                            <input id="admin_username" name="admin_username" class="form-control" minlength="3" maxlength="64" required autocomplete="off" value="<?= $escape($value('admin_username')) ?>">
                        </div>
                        <div class="mb-3">
                            <label for="admin_email" class="form-label">Correo electrónico</label>
                            <input id="admin_email" name="admin_email" type="email" class="form-control" maxlength="254" required autocomplete="off" value="<?= $escape($value('admin_email')) ?>">
                        </div>
                        <div class="mb-3">
                            <label for="admin_password" class="form-label">Contraseña inicial</label>
                            <input id="admin_password" name="admin_password" type="password" class="form-control" minlength="12" required autocomplete="new-password" aria-describedby="password-help">
                            <small id="password-help" class="text-secondary">Mínimo 12 caracteres. Compártela por un canal seguro; el administrador puede cambiarla desde su perfil o con «¿Olvidaste tu contraseña?».</small>
                        </div>
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Crear conjunto y administrador</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</section>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
    (() => {
        const name = document.getElementById('name');
        const slug = document.getElementById('slug');
        let edited = slug.value !== '';
        slug.addEventListener('input', () => { edited = slug.value !== ''; });
        name.addEventListener('input', () => {
            if (edited) return;
            slug.value = name.value.normalize('NFD').replace(/[̀-ͯ]/g, '')
                .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63);
        });
    })();
</script>
