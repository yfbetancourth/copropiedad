<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$connectedAccount = $connectedAccount ?? null;
$oauthProviders = $oauthProviders ?? [];
$configured = is_array($personal);
$active = $configured && $personal['active'];
$verified = $configured && $personal['verified_at'] !== null;
$value = static fn (string $key, string $default = ''): string => $configured ? (string) $personal[$key] : $default;
$state = $connectedAccount !== null ? 'connected' : (!$configured ? 'none' : ($active ? 'active' : ($verified ? 'paused' : 'unverified')));
$stateInfo = [
    'connected' => ['success', 'bi-patch-check-fill', 'Cuenta conectada', 'Las notas que tú emitas o reenvíes salen desde tu cuenta conectada.'],
    'none' => ['secondary', 'bi-envelope', 'Sin configurar', 'Tus envíos salen por la cuenta general de la copropiedad.'],
    'unverified' => ['warning', 'bi-hourglass-split', 'Pendiente de prueba', 'Guardaste los datos, pero aún no se usan: envía el correo de prueba para activarla.'],
    'paused' => ['secondary', 'bi-pause-circle', 'En pausa', 'La cuenta está verificada pero desactivada: tus envíos salen por la cuenta general.'],
    'active' => ['success', 'bi-patch-check-fill', 'Activa', 'Las notas que tú emitas o reenvíes salen desde esta cuenta.'],
][$state];
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Mi correo de envío</h1>
        <p class="text-secondary mb-0">Usa tu propio correo (por ejemplo, un correo certificado) para enviar las notas de cobro a residentes y copropietarios.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> d-flex gap-2" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?> mt-1" aria-hidden="true"></i><div><?= $escape($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-<?= $stateInfo[0] ?>">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <span class="rounded-circle d-inline-flex align-items-center justify-content-center text-bg-<?= $stateInfo[0] ?> flex-shrink-0" style="width: 3rem; height: 3rem" aria-hidden="true">
                <i class="bi <?= $stateInfo[1] ?> fs-4"></i>
            </span>
            <div class="flex-grow-1" style="min-width: 14rem">
                <div class="fw-semibold">Estado: <?= $escape($stateInfo[2]) ?><?= $connectedAccount !== null ? ' · ' . $escape($connectedAccount['email']) : ($configured ? ' · ' . $escape($personal['from_address']) : '') ?></div>
                <div class="small text-secondary"><?= $escape($stateInfo[3]) ?>
                    <?php if ($verified): ?> Última verificación: <?= $escape($personal['verified_at']) ?>.<?php endif; ?></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($configured || $connectedAccount !== null): ?>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/mi-correo/probar">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button class="btn btn-<?= $active || $connectedAccount !== null ? 'outline-primary' : 'primary' ?>" type="submit" data-loading-label="Enviando prueba…">
                            <i class="bi bi-send-check me-1" aria-hidden="true"></i><?= $connectedAccount !== null ? 'Enviar correo de prueba' : ($active ? 'Volver a probar' : 'Enviar prueba y activar') ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($verified && $connectedAccount === null): ?>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/mi-correo/estado">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                        <button class="btn btn-outline-secondary" type="submit"><i class="bi <?= $active ? 'bi-pause' : 'bi-play' ?> me-1" aria-hidden="true"></i><?= $active ? 'Pausar' : 'Reactivar' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    $canWrite = true; // Esta página ya exige permiso de escritura.
    $connectPath = '/configuracion/mi-correo/conectar';
    $disconnectPath = '/configuracion/mi-correo/desconectar';
    $accountDescription = 'Las notas de cobro que tú emitas o reenvíes salen desde esta cuenta.';
    require __DIR__ . '/partials/mail-connect.php';
    ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <form class="card border-0 shadow-sm" method="post" action="<?= $escape($basePath) ?>/configuracion/mi-correo/guardar" autocomplete="off">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0"><?= $connectedAccount !== null ? 'Servidor SMTP de tu cuenta (no se usa mientras haya una cuenta conectada)' : 'Otra opción: datos SMTP de tu cuenta' ?></h2></div>
                <div class="card-body">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="host">Servidor SMTP</label>
                            <input id="host" name="host" class="form-control" required maxlength="253" value="<?= $escape($value('host')) ?>" placeholder="smtp.tuproveedor.com" spellcheck="false">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="port">Puerto</label>
                            <input id="port" name="port" type="number" class="form-control" required min="1" max="65535" value="<?= $escape($value('port', '587')) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="encryption">Cifrado</label>
                            <select id="encryption" name="encryption" class="form-select">
                                <option value="tls" <?= $value('encryption', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                                <option value="ssl" <?= $value('encryption') === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="username">Usuario SMTP</label>
                            <input id="username" name="username" class="form-control" required maxlength="254" value="<?= $escape($value('username')) ?>" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Contraseña SMTP</label>
                            <div class="input-group">
                                <input id="password" name="password" type="password" class="form-control" maxlength="512" autocomplete="new-password"
                                       <?= $configured && $personal['password_configured'] ? 'placeholder="•••••••• (guardada)"' : 'required' ?>>
                                <button class="btn btn-outline-secondary" type="button" id="toggle-password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            <div class="form-text">Se guarda cifrada y nunca vuelve a mostrarse. Déjala vacía para conservar la guardada.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="from_address">Correo remitente</label>
                            <input id="from_address" name="from_address" type="email" class="form-control" required maxlength="254" value="<?= $escape($value('from_address', $actor['email'] ?? '')) ?>">
                            <div class="form-text">Debe ser la dirección certificada de tu cuenta; algunos proveedores rechazan otros remitentes.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="from_name">Nombre del remitente</label>
                            <input id="from_name" name="from_name" class="form-control" maxlength="160" value="<?= $escape($value('from_name', $actor['username'] ?? '')) ?>" placeholder="Ej. Administración St Trope">
                        </div>
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Al cambiar el servidor, el usuario, la contraseña o el remitente, la cuenta se pausa hasta que envíes una nueva prueba. Así nunca se envían notas desde una cuenta sin verificar.
                    </div>
                </div>
                <div class="card-footer bg-transparent d-flex flex-wrap gap-2 align-items-center">
                    <button class="btn btn-primary" type="submit" data-loading-label="Guardando…">Guardar datos</button>
                    <span class="small text-secondary">Luego usa «Enviar prueba y activar».</span>
                </div>
            </form>
        </div>
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">Cómo funciona</h2></div>
                <div class="card-body small">
                    <ol class="ps-3 mb-3">
                        <li class="mb-2"><strong>Guarda</strong> los datos SMTP que te dio tu proveedor de correo certificado.</li>
                        <li class="mb-2"><strong>Envía la prueba</strong>: llegará a <?= $escape($actor['email'] ?? 'tu correo') ?>. Si llega, la cuenta se activa.</li>
                        <li>Desde ese momento, las notas que <strong>tú</strong> emitas o reenvíes en Cartera / Cobros salen desde tu correo, y queda registrado en cada envío.</li>
                    </ol>
                    <p class="mb-1 text-secondary">Lo que sigue saliendo por la cuenta general<?= $generalAddress !== '' ? ' (' . $escape($generalAddress) . ')' : '' ?>:</p>
                    <ul class="ps-3 mb-0 text-secondary">
                        <li>Envíos que hagan otros administradores sin correo propio.</li>
                        <li>Recuperación de contraseña de los residentes.</li>
                    </ul>
                </div>
            </div>
            <?php if ($configured): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body small">
                        <form method="post" action="<?= $escape($basePath) ?>/configuracion/mi-correo/eliminar" data-confirm="¿Eliminar tu correo personal de envío y su contraseña guardada? Tus envíos volverán a salir por la cuenta general.">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <button class="btn btn-link text-danger p-0" type="submit"><i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar mi correo de envío</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
document.getElementById('toggle-password')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const input = document.getElementById('password');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});
// data-confirm y data-loading-label los atiende app.js.

</script>
