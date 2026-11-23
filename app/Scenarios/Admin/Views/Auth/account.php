<?php

declare(strict_types=1);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-person-gear me-2" aria-hidden="true"></i>Mi cuenta</h1>
        <p class="text-secondary mb-0">Tu usuario, correo y contraseña para entrar al panel administrativo.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= htmlspecialchars((string) $flash['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <?php $accountFormAction = '/mi-cuenta'; require dirname(__DIR__, 3) . '/Shared/account-form.php'; ?>
        </div>
    </div>
</section>
