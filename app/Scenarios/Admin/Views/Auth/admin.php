<section class="content">
    <div class="card">
        <div class="card-header">
            <h1 class="card-title"><?= htmlspecialchars($propertyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Panel administrativo</h1>
            <div class="card-tools">
                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/logout">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesion</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <p>Bienvenido, <strong><?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>.</p>
            <p>Tu acceso administrativo esta activo. Los modulos de gestion se incorporaran de forma independiente.</p>
        </div>
    </div>
</section>
