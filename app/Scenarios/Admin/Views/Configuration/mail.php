<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.correo', 2);
$mailReady = $settings['host'] !== ''
    && filter_var($settings['port'], FILTER_VALIDATE_INT) !== false
    && (int) $settings['port'] >= 1
    && (int) $settings['port'] <= 65535
    && $settings['username'] !== ''
    && $settings['password_configured']
    && in_array($settings['encryption'], ['tls', 'ssl'], true)
    && filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL) !== false;

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-envelope-at me-2" aria-hidden="true"></i>Configuración de correo</h1>
        <p class="text-secondary mb-0">Configura y prueba el correo de la copropiedad. Esta cuenta también se usa para la recuperación de acceso.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h2 class="card-title mb-0">Proveedor SMTP</h2>
                    <span class="badge text-bg-<?= $mailReady ? 'success' : 'warning' ?>" role="status">
                        <?= $mailReady ? 'Configuración lista para probar' : 'Configuración incompleta' ?>
                    </span>
                </div>
                <form method="post" action="<?= $escape($basePath) ?>/configuracion/correo/guardar" autocomplete="off">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="mail-host" class="form-label">Servidor SMTP</label>
                                <input id="mail-host" name="host" class="form-control" maxlength="253" required value="<?= $escape($settings['host']) ?>" <?= $canWrite ? '' : 'readonly' ?> placeholder="smtp.ejemplo.com">
                            </div>
                            <div class="col-md-4">
                                <label for="mail-port" class="form-label">Puerto</label>
                                <input id="mail-port" name="port" type="number" class="form-control" min="1" max="65535" step="1" required value="<?= $escape($settings['port']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                            </div>
                            <div class="col-md-6">
                                <label for="mail-encryption" class="form-label">Cifrado</label>
                                <select id="mail-encryption" name="encryption" class="form-select" <?= $canWrite ? '' : 'disabled' ?>>
                                    <option value="tls" <?= $settings['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (recomendado, puerto 587)</option>
                                    <option value="ssl" <?= $settings['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (normalmente, puerto 465)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="mail-username" class="form-label">Usuario del proveedor</label>
                                <input id="mail-username" name="username" class="form-control" maxlength="254" required value="<?= $escape($settings['username']) ?>" <?= $canWrite ? '' : 'readonly' ?> autocomplete="username">
                            </div>
                            <div class="col-md-6">
                                <label for="mail-password" class="form-label">Contraseña o clave de aplicación</label>
                                <div class="input-group">
                                    <input id="mail-password" name="password" type="password" class="form-control" maxlength="512" <?= $canWrite ? '' : 'readonly' ?> autocomplete="new-password" placeholder="<?= $settings['password_configured'] ? 'Guardada; deja vacío para conservarla' : 'Ingresa la contraseña SMTP' ?>" <?= $settings['password_configured'] ? '' : 'required' ?>>
                                    <?php if ($canWrite): ?>
                                        <button class="btn btn-outline-secondary" type="button" id="toggle-mail-password" aria-controls="mail-password" aria-pressed="false">Mostrar</button>
                                    <?php endif; ?>
                                </div>
                                <small class="text-secondary">La clave se guarda cifrada y nunca vuelve a mostrarse. Déjala vacía para conservar la guardada.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="mail-from-address" class="form-label">Correo remitente</label>
                                <input id="mail-from-address" name="from_address" type="email" class="form-control" maxlength="254" required value="<?= $escape($settings['from_address']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                            </div>
                            <div class="col-md-6">
                                <label for="mail-from-name" class="form-label">Nombre del remitente</label>
                                <input id="mail-from-name" name="from_name" class="form-control" maxlength="160" value="<?= $escape($settings['from_name']) ?>" <?= $canWrite ? '' : 'readonly' ?> placeholder="Administración de la copropiedad">
                            </div>
                        </div>
                        <div class="alert alert-info mt-3 mb-0">
                            No uses la contraseña de inicio de sesión si tu proveedor ofrece claves de aplicación. Para Gmail, crea una contraseña de aplicación y usa smtp.gmail.com, puerto 587 y STARTTLS.
                        </div>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit" data-loading-label="Guardando...">Guardar configuración de correo</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card card-info card-outline">
                <div class="card-header"><h2 class="card-title">Probar envío</h2></div>
                <div class="card-body">
                    <p class="text-secondary">Envía un mensaje real a tu correo para comprobar las credenciales y la conexión SMTP.</p>
                    <p class="mb-0"><strong>Destinatario:</strong> <?= $escape($actor['email']) ?></p>
                    <p class="small text-secondary mt-2 mb-0">
                        <?= $mailReady
                            ? 'Guarda los cambios antes de probar. La prueba utiliza la configuración guardada.'
                            : 'Completa los campos obligatorios y guarda la configuración para habilitar la prueba.' ?>
                    </p>
                </div>
                <?php if ($canWrite): ?>
                    <div class="card-footer">
                        <form method="post" action="<?= $escape($basePath) ?>/configuracion/correo/probar">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <button class="btn btn-outline-primary" type="submit" data-loading-label="Enviando..." <?= $mailReady ? '' : 'disabled' ?>>Enviar correo de prueba</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php if ($canWrite): ?>
<script>
    document.getElementById('toggle-mail-password')?.addEventListener('click', function () {
        const password = document.getElementById('mail-password');
        const isVisible = password.type === 'text';
        password.type = isVisible ? 'password' : 'text';
        this.textContent = isVisible ? 'Mostrar' : 'Ocultar';
        this.setAttribute('aria-pressed', String(!isVisible));
    });

    document.querySelectorAll('form[action$="/configuracion/correo/guardar"], form[action$="/configuracion/correo/probar"]').forEach((form) => {
        form.addEventListener('submit', function () {
            const button = form.querySelector('button[type="submit"]');
            if (button && !button.disabled) {
                button.disabled = true;
                button.textContent = button.dataset.loadingLabel;
            }
        });
    });
</script>
<?php endif; ?>
