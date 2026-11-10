<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.correo', 2);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-envelope-at me-2" aria-hidden="true"></i>Configuración de correo</h1>
        <p class="text-secondary mb-0">Configura y prueba el correo de la copropiedad. Esta cuenta también se usa para la recuperación de acceso.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Proveedor SMTP</h2></div>
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
                                <input id="mail-username" name="username" class="form-control" maxlength="254" required value="<?= $escape($settings['username']) ?>" <?= $canWrite ? '' : 'readonly' ?> autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label for="mail-password" class="form-label">Contraseña o clave de aplicación</label>
                                <input id="mail-password" name="password" type="password" class="form-control" maxlength="512" <?= $canWrite ? '' : 'readonly' ?> autocomplete="new-password" placeholder="<?= $settings['password_configured'] ? 'Guardada; deja vacío para conservarla' : 'Ingresa la contraseña SMTP' ?>" <?= $settings['password_configured'] ? '' : 'required' ?>>
                                <small class="text-secondary">La clave se guarda cifrada y nunca vuelve a mostrarse.</small>
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
                            <button class="btn btn-primary" type="submit">Guardar configuración de correo</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card card-info card-outline">
                <div class="card-header"><h2 class="card-title">Probar conexión y envío</h2></div>
                <div class="card-body">
                    <p class="text-secondary">Envía un correo de prueba a la dirección de tu cuenta para confirmar que el proveedor acepta las credenciales.</p>
                    <p class="mb-0"><strong>Destinatario:</strong> <?= $escape($actor['email']) ?></p>
                </div>
                <?php if ($canWrite): ?>
                    <div class="card-footer">
                        <form method="post" action="<?= $escape($basePath) ?>/configuracion/correo/probar">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <button class="btn btn-outline-primary" type="submit">Enviar correo de prueba</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
