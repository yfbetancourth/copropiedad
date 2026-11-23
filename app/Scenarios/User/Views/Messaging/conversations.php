<?php

declare(strict_types=1);

use App\Modules\Messaging\ConversationService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusBadges = ['open' => 'warning', 'answered' => 'success', 'closed' => 'secondary'];
$residentStatus = ['open' => 'Enviada · esperando respuesta', 'answered' => 'Respondida', 'closed' => 'Cerrada'];
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Escribir a la administración</h1>
        <p class="text-secondary mb-0">Haz consultas, peticiones, quejas, reclamos o sugerencias. El equipo de la administración te responde aquí y te avisamos por correo.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Mis solicitudes</h2></div>
                <?php if ($conversations === []): ?>
                    <div class="card-body text-secondary">Aún no has escrito a la administración.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($conversations as $conversation): ?>
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-start gap-2"
                               href="<?= $escape($basePath) ?>/usuario/comunicacion/ver?id=<?= (int) $conversation['id'] ?>">
                                <span>
                                    <span class="fw-semibold<?= (int) $conversation['resident_unread'] === 1 ? ' text-primary' : '' ?>">
                                        <?php if ((int) $conversation['resident_unread'] === 1): ?><i class="bi bi-circle-fill small me-1" aria-hidden="true"></i><span class="visually-hidden">Respuesta sin leer: </span><?php endif; ?>
                                        <?= $escape((string) $conversation['subject']) ?>
                                    </span>
                                    <span class="d-block small text-secondary">
                                        <?= $escape(ConversationService::CATEGORIES[$conversation['category']] ?? (string) $conversation['category']) ?>
                                        · <?= (int) $conversation['message_count'] ?> mensaje(s)
                                        · <?= $escape(date('d/m/Y H:i', strtotime((string) $conversation['last_message_at']) ?: time())) ?>
                                    </span>
                                </span>
                                <span class="badge text-bg-<?= $statusBadges[$conversation['status']] ?? 'secondary' ?>"><?= $escape($residentStatus[$conversation['status']] ?? (string) $conversation['status']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-xl-5" id="nueva">
            <div class="card card-primary card-outline">
                <div class="card-header"><h2 class="card-title">Nueva solicitud</h2></div>
                <form method="post" action="<?= $escape($basePath) ?>/usuario/comunicacion/nueva">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="conversation-category">Tipo</label>
                            <select class="form-select" id="conversation-category" name="category" required>
                                <?php foreach (ConversationService::CATEGORIES as $value => $label): ?>
                                    <option value="<?= $escape($value) ?>"<?= ($draft['category'] ?? '') === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="conversation-subject">Asunto</label>
                            <input class="form-control" id="conversation-subject" name="subject" minlength="3" maxlength="160" required value="<?= $escape((string) ($draft['subject'] ?? '')) ?>">
                        </div>
                        <div>
                            <label class="form-label" for="conversation-body">Mensaje</label>
                            <textarea class="form-control" id="conversation-body" name="body" rows="5" minlength="2" maxlength="5000" required><?= $escape((string) ($draft['body'] ?? '')) ?></textarea>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar a la administración</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
