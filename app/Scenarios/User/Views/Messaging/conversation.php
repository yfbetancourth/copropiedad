<?php

declare(strict_types=1);

use App\Modules\Messaging\ConversationService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$threadViewer = 'resident';
$closed = $conversation['status'] === 'closed';
?>
<section class="content">
    <a class="small text-decoration-none d-inline-block mb-2" href="<?= $escape($basePath) ?>/usuario/comunicacion"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Mis solicitudes</a>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= $escape((string) $conversation['subject']) ?></h1>
            <p class="text-secondary small mb-0"><?= $escape(ConversationService::CATEGORIES[$conversation['category']] ?? (string) $conversation['category']) ?> · iniciada el <?= $escape(date('d/m/Y', strtotime((string) $conversation['created_at']) ?: time())) ?></p>
        </div>
        <span class="badge text-bg-<?= $closed ? 'secondary' : ($conversation['status'] === 'answered' ? 'success' : 'warning') ?> fs-6"><?= $escape($closed ? 'Cerrada' : ($conversation['status'] === 'answered' ? 'Respondida' : 'Esperando respuesta')) ?></span>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php require dirname(__DIR__, 3) . '/Shared/conversation-thread.php'; ?>
        </div>
        <div class="card-footer" id="responder">
            <?php if ($closed): ?>
                <p class="mb-0 text-secondary">La administración cerró esta solicitud. Si necesitas algo más, <a href="<?= $escape($basePath) ?>/usuario/comunicacion#nueva">abre una nueva</a>.</p>
            <?php else: ?>
                <form method="post" action="<?= $escape($basePath) ?>/usuario/comunicacion/responder">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="conversation_id" value="<?= (int) $conversation['id'] ?>">
                    <label class="form-label" for="reply-body">Responder</label>
                    <textarea class="form-control mb-2" id="reply-body" name="body" rows="3" minlength="2" maxlength="5000" required></textarea>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
