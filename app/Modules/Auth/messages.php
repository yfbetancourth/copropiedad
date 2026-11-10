<section class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Mis mensajes</h1>
            <p class="text-secondary mb-0">Mensajes internos recibidos de la administración.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al panel
        </a>
    </div>

    <?php if (is_array($messages) && $messages !== []): ?>
        <?php foreach ($messages as $message): ?>
            <article class="card <?= $message['read_at'] === null ? 'card-primary card-outline' : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <h2 class="card-title"><?= htmlspecialchars($message['subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                    <?php if ($message['read_at'] === null): ?>
                        <span class="badge text-bg-primary">Nuevo</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">Leído</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="small text-secondary">
                        De <?= htmlspecialchars($message['sender_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        · <?= htmlspecialchars($message['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </p>
                    <div class="text-break"><?= nl2br(htmlspecialchars($message['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                    <?php if ($message['read_at'] === null): ?>
                        <form class="mt-3" method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/mensajes/leer">
                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit">Marcar como leído</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-light border" role="status">No tienes mensajes recibidos.</div>
    <?php endif; ?>
</section>
