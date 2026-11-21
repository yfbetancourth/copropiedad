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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/inter@5.0.18/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/fraunces@5.0.21/wght-italic.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.10.2/dist/css/adminlte.min.css">
    <style>
        :root {
            --auth-green: #2f4637;
            --auth-green-soft: #46604c;
            --auth-paper: #f6f4ee;
            --auth-orange: #c2703f;
            --bs-primary: #2f4637;
            --bs-primary-rgb: 47, 70, 55;
            --bs-link-color: #2f4637;
            --bs-link-hover-color: #c2703f;
            --bs-font-sans-serif: "Inter Variable", Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        body { min-height: 100vh; margin: 0; background: var(--auth-paper); font-family: var(--bs-font-sans-serif); color: #1f2721; }
        .auth-shell { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); }
        .auth-brand {
            position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
            padding: clamp(32px, 5vw, 64px); color: #fff; background: var(--auth-green);
            background-image: radial-gradient(circle at 85% 10%, rgba(255,255,255,.09) 0 160px, transparent 161px),
                              radial-gradient(circle at 0% 100%, rgba(194,112,63,.35) 0 220px, transparent 221px);
        }
        .auth-brand.has-photo { background-size: cover; background-position: center; }
        .auth-brand.has-photo::before { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(31,45,36,.55) 0%, rgba(31,45,36,.82) 100%); }
        .auth-brand > * { position: relative; }
        .auth-logo { display: inline-flex; align-items: center; gap: 12px; color: inherit; text-decoration: none; font-weight: 700; font-size: 17px; line-height: 1.15; }
        .auth-logo small { display: block; margin-top: 3px; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; opacity: .75; }
        .auth-mark { width: 44px; height: 44px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 12px; background: rgba(255,255,255,.14); }
        .auth-mark svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .auth-headline { max-width: 460px; margin: 0 0 18px; font-size: clamp(34px, 3.6vw, 52px); font-weight: 650; letter-spacing: -.03em; line-height: 1.05; }
        .auth-headline em { font-family: "Fraunces Variable", Georgia, serif; font-weight: 400; color: #f0c7a8; }
        .auth-features { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; font-size: 16px; }
        .auth-features i { width: 32px; height: 32px; display: inline-grid; place-items: center; margin-right: 10px; border-radius: 8px; background: rgba(255,255,255,.14); }
        .auth-brand-footer { font-size: 14px; opacity: .8; }
        .auth-brand-footer a { color: inherit; }
        .auth-main { display: flex; flex-direction: column; padding: 32px clamp(20px, 5vw, 64px); }
        .auth-mobile-logo { display: none; color: var(--auth-green); }
        .auth-mobile-logo .auth-mark { background: var(--auth-green); color: #fff; }
        .auth-content { width: 100%; max-width: 420px; margin: auto; padding: 32px 0; }
        .auth-content h1 { font-size: 30px; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; }
        .auth-content .lead-text { color: #5f675f; margin-bottom: 28px; }
        .auth-content .form-label { font-weight: 600; }
        .auth-content a:not(.btn) { color: var(--auth-green); font-weight: 600; text-decoration: none; }
        .auth-content a:not(.btn):hover { color: var(--auth-orange); text-decoration: underline; }
        .auth-content .form-control, .auth-content .input-group-text, .auth-content .btn { min-height: 48px; }
        .auth-content .input-group-text { background: #fff; color: #5f675f; }
        .auth-content .form-control:focus { border-color: var(--auth-green-soft); box-shadow: 0 0 0 .2rem rgba(47,70,55,.18); }
        .auth-content .btn-primary { --bs-btn-bg: var(--auth-green); --bs-btn-border-color: var(--auth-green); --bs-btn-hover-bg: #233629; --bs-btn-hover-border-color: #233629; --bs-btn-active-bg: #1c2c21; font-weight: 600; }
        .auth-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; margin-bottom: 24px; border-radius: 12px; background: #e9e7de; }
        .auth-switch a { padding: 9px 12px; border-radius: 9px; color: #3c463e; font-weight: 600; text-align: center; text-decoration: none; font-size: 15px; }
        .auth-switch a[aria-current="page"] { background: #fff; color: var(--auth-green); box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .auth-footer { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; color: #5f675f; font-size: 14px; }
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
<script>
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
        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${button.dataset.loadingLabel || 'Enviando…'}`;
    });
});
</script>
</body>
</html>
