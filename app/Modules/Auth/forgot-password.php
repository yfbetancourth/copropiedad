<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card card-primary card-outline mb-4">
            <div class="card-header">
                <h1 class="card-title fs-4">Recuperar contrasena</h1>
            </div>
            <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/forgot-password">
                <div class="card-body">
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($notice !== null): ?>
                        <div class="alert alert-info" role="status"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <p>Ingresa el correo asociado a tu cuenta. Te enviaremos un enlace de recuperacion si existe.</p>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="email" class="form-label">Correo electronico</label>
                        <input id="email" name="email" type="email" class="form-control" autocomplete="email" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Enviar instrucciones</button>
                </div>
            </form>
            <div class="card-footer"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/login">Volver al inicio de sesion</a></div>
        </div>
    </div>
</div>
