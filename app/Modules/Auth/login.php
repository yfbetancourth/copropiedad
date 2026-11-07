<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card card-primary card-outline mb-4">
            <div class="card-header">
                <h1 class="card-title fs-4"><?= htmlspecialchars($propertyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
                <p class="text-secondary mb-0">Acceso administrativo</p>
            </div>
            <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/login" autocomplete="on">
                <div class="card-body">
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($notice !== null): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="identity" class="form-label">Usuario o correo</label>
                        <input id="identity" name="identity" class="form-control" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Contrasena</label>
                        <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Iniciar sesion</button>
                </div>
            </form>
            <div class="card-footer">
                <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/forgot-password">Olvide mi contrasena</a>
            </div>
        </div>
    </div>
</div>
