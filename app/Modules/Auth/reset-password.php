<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$linkValid = $token !== '';
?>
<h1>Crear nueva contraseña</h1>
<p class="lead-text">Elige una contraseña de al menos 12 caracteres. Una frase fácil de recordar funciona bien.</p>

<?php if ($error !== null): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        <i class="bi bi-exclamation-circle-fill mt-1" aria-hidden="true"></i><div><?= $escape($error) ?></div>
    </div>
<?php endif; ?>

<?php if (!$linkValid): ?>
    <a class="btn btn-primary w-100" href="<?= $escape($basePath) ?>/forgot-password">Solicitar un enlace nuevo</a>
<?php else: ?>
    <form method="post" action="<?= $escape($basePath) ?>/reset-password" data-auth-form id="reset-form">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="token" value="<?= $escape($token) ?>">
        <div class="mb-3">
            <label for="password" class="form-label">Nueva contraseña</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                <input id="password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="12" required autofocus aria-describedby="password-help">
                <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="progress mt-2" style="height: .3rem" aria-hidden="true"><div id="password-meter" class="progress-bar" style="width: 0%"></div></div>
            <div id="password-help" class="form-text">Mínimo 12 caracteres.</div>
            <div class="form-text text-warning" data-capslock-for="password" hidden><i class="bi bi-capslock-fill me-1" aria-hidden="true"></i>Bloq Mayús está activado.</div>
        </div>
        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill" aria-hidden="true"></i></span>
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" minlength="12" required aria-describedby="confirmation-help">
            </div>
            <div id="confirmation-help" class="form-text" aria-live="polite"></div>
        </div>
        <button class="btn btn-primary w-100" type="submit" data-loading-label="Guardando…">Guardar contraseña</button>
    </form>
    <script>
    (() => {
        const password = document.getElementById('password');
        const confirmation = document.getElementById('password_confirmation');
        const meter = document.getElementById('password-meter');
        const help = document.getElementById('password-help');
        const confirmationHelp = document.getElementById('confirmation-help');
        const update = () => {
            const value = password.value;
            const variety = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(value)).length;
            const score = value.length === 0 ? 0 : value.length < 12 ? 1 : Math.min(4, 1 + variety + (value.length >= 16 ? 1 : 0) - 1);
            meter.style.width = `${score * 25}%`;
            meter.className = `progress-bar ${['', 'bg-danger', 'bg-warning', 'bg-info', 'bg-success'][score]}`;
            help.textContent = value.length === 0 ? 'Mínimo 12 caracteres.'
                : value.length < 12 ? `Faltan ${12 - value.length} caracteres.`
                : ['', '', 'Aceptable. Combinar mayúsculas, números o símbolos la hace más segura.', 'Buena contraseña.', 'Contraseña muy segura.'][score];
            const matches = confirmation.value === '' || confirmation.value === value;
            confirmation.setCustomValidity(matches ? '' : 'Las contraseñas no coinciden.');
            confirmation.classList.toggle('is-invalid', !matches);
            confirmation.classList.toggle('is-valid', confirmation.value !== '' && matches && value.length >= 12);
            confirmationHelp.textContent = matches ? '' : 'Las contraseñas no coinciden.';
            confirmationHelp.className = `form-text ${matches ? '' : 'text-danger'}`;
        };
        password.addEventListener('input', update);
        confirmation.addEventListener('input', update);
    })();
    </script>
<?php endif; ?>
