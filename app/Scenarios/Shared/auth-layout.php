<?php

declare(strict_types=1);

use App\Core\Url;

/**
 * Layout de las pantallas de acceso (inicio de sesión, recuperar y crear contraseña).
 * Panel de marca con la foto de portada de la copropiedad y formulario centrado.
 */
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$publicBase = Url::publicBasePath();
$coverUrl = is_string($coverImage ?? null) && $coverImage !== '' ? $publicBase . $coverImage : null;
$address = is_string($address ?? null) ? $address : '';
$brandIcon = '<svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><path d="M5 29V14.5L18 6l13 8.5V29H5Z"/><path d="M12 29V18h12v11M9.5 15h17"/></svg>';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $escape($pageTitle) ?> | <?= $escape($propertyName) ?></title>
    <?php $vendor = $escape(\App\Core\Url::publicBasePath()) . '/assets/vendor'; ?>
    <link rel="stylesheet" href="<?= $vendor ?>/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="<?= $vendor ?>/lexend-variable@5.2.8/index.css">
    <link rel="stylesheet" href="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/css/icons.css?v=1">
    <link rel="stylesheet" href="<?= $vendor ?>/admin-lte@4.10.2/css/adminlte.min.css">
    <link rel="stylesheet" href="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/css/app.css?v=1">
    <link rel="stylesheet" href="<?= $escape(\App\Core\Url::publicBasePath()) ?>/assets/css/theme.css?v=6">
    <style>
        /* Colores, tipografía, foco y controles vienen de theme.css; aquí solo la composición de la pantalla. */
        :root {
            --auth-green: var(--cp-brand-900);
            --auth-green-soft: var(--cp-brand-700);
            --auth-paper: var(--cp-color-page);
            --auth-orange: var(--cp-brand-800);
        }
        body { min-height: 100vh; margin: 0; background: var(--auth-paper); color: var(--cp-color-text); }
        .auth-shell { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); }
        .auth-brand {
            position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
            padding: clamp(32px, 5vw, 64px); color: #fff; background: var(--auth-green);
            background-image: radial-gradient(circle at 85% 10%, rgba(255,255,255,.09) 0 160px, transparent 161px),
                              radial-gradient(circle at 0% 100%, rgba(34,184,207,.22) 0 220px, transparent 221px);
        }
        .auth-brand.has-photo { background-size: cover; background-position: center; }
        .auth-brand.has-photo::before { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(22,78,99,.55) 0%, rgba(22,78,99,.88) 100%); }
        .auth-brand > * { position: relative; }
        .auth-logo { display: inline-flex; align-items: center; gap: 12px; color: inherit; text-decoration: none; font-weight: 700; font-size: 17px; line-height: 1.15; }
        .auth-logo small { display: block; margin-top: 3px; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; opacity: .75; }
        .auth-mark { width: 44px; height: 44px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 12px; background: rgba(255,255,255,.14); }
        .auth-mark svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .auth-headline { max-width: 460px; margin: 0 0 18px; font-family: var(--cp-font-heading); font-size: clamp(34px, 3.6vw, 52px); font-weight: 600; letter-spacing: -.03em; line-height: 1.05; }
        .auth-headline em { font-style: normal; font-weight: 300; color: #A5F3FC; }
        .auth-features { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; font-size: 16px; }
        .auth-features i { width: 32px; height: 32px; display: inline-grid; place-items: center; margin-right: 10px; border-radius: 8px; background: rgba(255,255,255,.14); }
        .auth-brand-footer { font-size: 14px; opacity: .8; }
        .auth-brand-footer a { color: inherit; }
        .auth-main { display: flex; flex-direction: column; padding: 32px clamp(20px, 5vw, 64px); }
        .auth-mobile-logo { display: none; color: var(--auth-green); }
        .auth-mobile-logo .auth-mark { background: var(--auth-green); color: #fff; }
        .auth-content { width: 100%; max-width: 420px; margin: auto; padding: 32px 0; }
        .auth-content h1 { font-size: 30px; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; }
        .auth-content .lead-text { color: var(--cp-color-text-muted); margin-bottom: 28px; }
        .auth-content .form-label { font-weight: 600; }
        .auth-content a:not(.btn) { color: var(--auth-green); font-weight: 600; text-decoration: none; }
        .auth-content a:not(.btn):hover { color: var(--auth-orange); text-decoration: underline; }
        .auth-content .form-control, .auth-content .input-group-text, .auth-content .btn { min-height: 48px; }
        .auth-content .input-group-text { background: #fff; color: var(--cp-color-text-muted); }
        .auth-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; margin-bottom: 24px; border-radius: 12px; background: var(--cp-brand-100); }
        .auth-switch a { padding: 9px 12px; min-block-size: var(--cp-target); display: grid; place-items: center; border-radius: 9px; color: var(--cp-brand-900); font-weight: 600; text-align: center; text-decoration: none; font-size: 15px; }
        .auth-switch a[aria-current="page"] { background: #fff; color: var(--auth-green); box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .auth-footer { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; color: var(--cp-color-text-muted); font-size: 15px; }
        .auth-footer a { color: inherit; text-decoration: none; }
        .auth-footer a:hover { color: var(--auth-orange); }
        @media (max-width: 991.98px) {
            .auth-shell { grid-template-columns: 1fr; }
            .auth-brand { display: none; }
            .auth-mobile-logo { display: inline-flex; }
        }
    </style>
</head>
<body>
<div class="auth-shell">
    <aside class="auth-brand<?= $coverUrl !== null ? ' has-photo' : '' ?>"<?= $coverUrl !== null ? ' style="background-image: url(\'' . $escape($coverUrl) . '\')"' : '' ?>>
        <a class="auth-logo" href="<?= $escape($publicBase) ?>/">
            <span class="auth-mark"><?= $brandIcon ?></span>
            <span><?= $escape($propertyName) ?><small>Vivir en comunidad</small></span>
        </a>
        <div>
            <p class="auth-headline">Tu comunidad, <em>en un solo lugar.</em></p>
            <ul class="auth-features">
                <li><i class="bi bi-receipt" aria-hidden="true"></i>Consulta y paga tus cuotas de administración</li>
                <li><i class="bi bi-chat-left-text" aria-hidden="true"></i>Recibe los comunicados de la administración</li>
                <li><i class="bi bi-megaphone" aria-hidden="true"></i>Publica y descubre clasificados de la comunidad</li>
            </ul>
        </div>
        <div class="auth-brand-footer">
            <?php if ($address !== ''): ?><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= $escape($address) ?><?php endif; ?>
        </div>
    </aside>
    <main class="auth-main" id="main-content">
        <a class="auth-logo auth-mobile-logo" href="<?= $escape($publicBase) ?>/">
            <span class="auth-mark"><?= $brandIcon ?></span>
            <span><?= $escape($propertyName) ?><small>Vivir en comunidad</small></span>
        </a>
        <div class="auth-content">
            <?= $content ?>
        </div>
        <footer class="auth-footer">
            <a href="<?= $escape($publicBase) ?>/"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al sitio</a>
            <span>© <?= date('Y') ?> <?= $escape($propertyName) ?></span>
        </footer>
    </main>
</div>
<script nonce="<?= \App\Core\Csp::nonce() ?>">
// Mostrar/ocultar contraseña, aviso de Bloq Mayús y evitar doble envío; compartido por las pantallas de acceso.
document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    const input = document.getElementById(button.dataset.togglePassword);
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        input.focus();
    });
});
document.querySelectorAll('input[type="password"]').forEach((input) => {
    const warning = document.querySelector(`[data-capslock-for="${input.id}"]`);
    if (!warning) return;
    const check = (event) => { warning.hidden = !(event.getModifierState && event.getModifierState('CapsLock')); };
    input.addEventListener('keydown', check);
    input.addEventListener('keyup', check);
    input.addEventListener('blur', () => { warning.hidden = true; });
});
document.querySelectorAll('form[data-auth-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.sending === '1' || !form.checkValidity()) return;
        form.dataset.sending = '1';
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-2';
        spinner.setAttribute('aria-hidden', 'true');
        button.replaceChildren(spinner, button.dataset.loadingLabel || 'Enviando…');
    });
});
</script>
<script src="<?= $escape(\App\Core\Csp::appScriptUrl()) ?>" defer></script>
</body>
</html>
