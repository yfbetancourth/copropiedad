<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<a class="small d-inline-block mb-4 text-decoration-none" href="<?= $escape($basePath) ?>/login"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a iniciar sesión</a>

<h1>Recuperar contraseña</h1>
<p class="lead-text">Escribe el correo de tu cuenta y te enviaremos un enlace para crear una contraseña nueva. El enlace vence en 1 hora.</p>

<?php if ($error !== null): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        <i class="bi bi-exclamation-circle-fill mt-1" aria-hidden="true"></i><div><?= $escape($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
        <i class="bi bi-envelope-check-fill mt-1" aria-hidden="true"></i>
        <div><?= $escape($notice) ?> <span class="d-block small mt-1">Revisa también la carpeta de correo no deseado.</span></div>
    </div>
<?php else: ?>
    <form method="post" action="<?= $escape($basePath) ?>/forgot-password" data-auth-form>
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <div class="mb-3">
            <label for="email" class="form-label">Correo electrónico</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                <input id="email" name="email" type="email" class="form-control" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus>
            </div>
        </div>
        <button class="btn btn-primary w-100" type="submit" data-loading-label="Enviando…">Enviar enlace de recuperación</button>
    </form>
<?php endif; ?>
