<?php

declare(strict_types=1);

/**
 * Formulario «Mi cuenta» (usuario, correo y contraseña), compartido por el portal del residente y la página
 * Mi cuenta del panel. Espera $basePath, $csrfToken, $user y $accountFormAction (ruta del POST).
 */
?>
<div class="card" id="perfil">
    <div class="card-header"><h2 class="card-title">Personalizar mi cuenta</h2></div>
    <form method="post" action="<?= htmlspecialchars($basePath . $accountFormAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="card-body">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="mb-3">
                <label for="profile-username" class="form-label">Nombre de usuario</label>
                <input id="profile-username" name="username" class="form-control" minlength="3" maxlength="64" autocomplete="username" required value="<?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="mb-3">
                <label for="profile-email" class="form-label">Correo electrónico</label>
                <input id="profile-email" name="email" type="email" class="form-control" maxlength="254" autocomplete="email" required value="<?= htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <hr>
            <p class="small text-secondary">Confirma tu contraseña actual para guardar los cambios. La nueva contraseña es opcional.</p>
            <div class="mb-3">
                <label for="current-password" class="form-label">Contraseña actual</label>
                <input id="current-password" name="current_password" type="password" class="form-control" autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label for="new-password" class="form-label">Nueva contraseña</label>
                <input id="new-password" name="new_password" type="password" class="form-control" minlength="12" autocomplete="new-password">
                <small class="text-secondary">Déjala vacía si no deseas cambiarla. Mínimo 12 caracteres.</small>
            </div>
            <div class="mb-0">
                <label for="password-confirmation" class="form-label">Confirmar nueva contraseña</label>
                <input id="password-confirmation" name="password_confirmation" type="password" class="form-control" minlength="12" autocomplete="new-password">
            </div>
        </div>
        <div class="card-footer">
            <button class="btn btn-primary" type="submit">Guardar datos de mi cuenta</button>
        </div>
    </form>
</div>
