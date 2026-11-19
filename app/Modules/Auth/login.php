<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isResident = $destination === 'usuario';
?>
<nav class="auth-switch" aria-label="Tipo de acceso">
    <a href="<?= $escape($basePath) ?>/login?destino=usuario"<?= $isResident ? ' aria-current="page"' : '' ?>><i class="bi bi-house-door me-1" aria-hidden="true"></i>Residente</a>
    <a href="<?= $escape($basePath) ?>/login?destino=admin"<?= !$isResident ? ' aria-current="page"' : '' ?>><i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Administración</a>
</nav>

<h1><?= $isResident ? 'Portal de residentes' : 'Panel administrativo' ?></h1>
<p class="lead-text"><?= $isResident
    ? 'Ingresa para ver tus inmuebles, pagar tus cuotas y leer tus mensajes.'
    : 'Ingresa para gestionar la cartera, la conformación y la comunicación de ' . $escape($propertyName) . '.' ?></p>

<?php if ($error !== null): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        <i class="bi bi-exclamation-circle-fill mt-1" aria-hidden="true"></i><div><?= $escape($error) ?></div>
    </div>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
        <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i><div><?= $escape($notice) ?></div>
    </div>
<?php endif; ?>

<form method="post" action="<?= $escape($basePath) ?>/login" autocomplete="on" data-auth-form>
    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="destination" value="<?= $escape($destination) ?>">

    <div class="mb-3">
        <label for="identity" class="form-label">Usuario o correo electrónico</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
            <input id="identity" name="identity" class="form-control" autocomplete="username" autocapitalize="none" spellcheck="false"
                   required autofocus value="<?= $escape(is_string($_POST['identity'] ?? null) ? $_POST['identity'] : '') ?>">
        </div>
    </div>

    <div class="mb-2">
        <div class="d-flex justify-content-between align-items-baseline">
            <label for="password" class="form-label">Contraseña</label>
            <a class="small" href="<?= $escape($basePath) ?>/forgot-password">¿Olvidaste tu contraseña?</a>
        </div>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
            <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required aria-describedby="password-capslock">
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
        </div>
        <div id="password-capslock" class="form-text text-warning" data-capslock-for="password" hidden><i class="bi bi-capslock-fill me-1" aria-hidden="true"></i>Bloq Mayús está activado.</div>
    </div>

    <button class="btn btn-primary w-100 mt-3" type="submit" data-loading-label="Ingresando…">Iniciar sesión <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>
</form>

<p class="small text-secondary mt-4 mb-0">
    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Por seguridad, la cuenta se bloquea 15 minutos tras 5 intentos fallidos.
    <?php if ($isResident): ?>¿Aún no tienes cuenta? Solicítala a la administración.<?php endif; ?>
</p>
