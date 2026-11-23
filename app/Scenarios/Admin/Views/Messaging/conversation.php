<?php

declare(strict_types=1);

use App\Modules\Messaging\ConversationService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$threadViewer = 'staff';
$closed = $conversation['status'] === 'closed';
$conversationId = (int) $conversation['id'];
?>
<section class="content">
    <a class="small text-decoration-none d-inline-block mb-2" href="<?= $escape($basePath) ?>/comunicacion/atencion"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Atención a residentes</a>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= $escape((string) $conversation['subject']) ?></h1>
            <p class="text-secondary small mb-0">
                <?= $escape(ConversationService::CATEGORIES[$conversation['category']] ?? (string) $conversation['category']) ?>
                · <?= $escape((string) ($conversation['full_name'] ?: $conversation['username'])) ?>
                · <?= $escape((string) $conversation['email']) ?><?= $conversation['phone'] ? ' · ' . $escape((string) $conversation['phone']) : '' ?>
                · iniciada el <?= $escape(date('d/m/Y H:i', strtotime((string) $conversation['created_at']) ?: time())) ?>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-start">
            <span class="badge text-bg-<?= $closed ? 'secondary' : ($conversation['status'] === 'answered' ? 'success' : 'warning') ?> fs-6"><?= $escape(ConversationService::STATUS_LABELS[$conversation['status']] ?? '') ?></span>
            <?php if ($canWrite): ?>
                <form method="post" action="<?= $escape($basePath) ?>/comunicacion/atencion/estado">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="conversation_id" value="<?= $conversationId ?>">
                    <input type="hidden" name="close" value="<?= $closed ? '0' : '1' ?>">
                    <button class="btn btn-sm btn-outline-<?= $closed ? 'primary' : 'secondary' ?>" type="submit"><?= $closed ? 'Reabrir' : 'Cerrar sin responder' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php require dirname(__DIR__, 3) . '/Shared/conversation-thread.php'; ?>
        </div>
        <div class="card-footer">
            <?php if ($closed): ?>
                <p class="mb-0 text-secondary">Cerrada<?= $conversation['closed_by_name'] ? ' por ' . $escape((string) $conversation['closed_by_name']) : '' ?><?= $conversation['closed_at'] ? ' el ' . $escape(date('d/m/Y H:i', strtotime((string) $conversation['closed_at']) ?: time())) : '' ?>. Reábrela para responder.</p>
            <?php elseif ($canWrite): ?>
                <form method="post" action="<?= $escape($basePath) ?>/comunicacion/atencion/responder">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="conversation_id" value="<?= $conversationId ?>">
                    <label class="form-label" for="staff-reply">Respuesta al residente</label>
                    <textarea class="form-control mb-2" id="staff-reply" name="body" rows="4" minlength="2" maxlength="5000" required></textarea>
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar respuesta</button>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="reply-close" name="close" value="1">
                            <label class="form-check-label" for="reply-close">Responder y cerrar la solicitud</label>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <p class="mb-0 text-secondary">Tu rol puede consultar las conversaciones, pero no responderlas.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
