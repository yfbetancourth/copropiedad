<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card card-primary card-outline mb-4">
            <div class="card-header">
                <h1 class="card-title fs-4">Crear nueva contrasena</h1>
            </div>
            <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/reset-password">
                <div class="card-body">
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label">Nueva contrasena (minimo 12 caracteres)</label>
                        <input id="password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="12" required>
                    </div>
                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirmar contrasena</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" minlength="12" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Cambiar contrasena</button>
                </div>
            </form>
        </div>
    </div>
</div>
