<?php

declare(strict_types=1);

use App\Modules\Messaging\ConversationService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = ['pending' => 'Por responder', 'answered' => 'Respondidas', 'closed' => 'Cerradas', 'all' => 'Todas'];
$statusBadges = ['open' => 'warning', 'answered' => 'success', 'closed' => 'secondary'];
$categoryBadges = ['queja' => 'danger', 'reclamo' => 'danger', 'peticion' => 'primary', 'consulta' => 'info', 'sugerencia' => 'secondary'];
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Atención a residentes</h1>
            <p class="text-secondary mb-0">Consultas, peticiones, quejas, reclamos y sugerencias que los residentes envían desde su portal. Cada respuesta les llega al portal y por correo.</p>
        </div>
        <?php if ($pendingCount > 0): ?>
            <span class="badge text-bg-warning fs-6"><?= (int) $pendingCount ?> por responder</span>
        <?php endif; ?>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <ul class="nav nav-pills me-auto">
                <?php foreach ($filters as $key => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link py-1<?= $filter === $key ? ' active' : '' ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>
                           href="<?= $escape($basePath) ?>/comunicacion/atencion?estado=<?= $escape($key) ?>"><?= $escape($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="get" action="<?= $escape($basePath) ?>/comunicacion/atencion" class="d-flex gap-2" role="search">
                <input type="hidden" name="estado" value="<?= $escape($filter) ?>">
                <label class="visually-hidden" for="inbox-search">Buscar</label>
                <input class="form-control form-control-sm" id="inbox-search" name="q" type="search" value="<?= $escape($search) ?>" placeholder="Asunto o residente">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Buscar</span></button>
            </form>
        </div>
        <?php if ($conversations === []): ?>
            <div class="card-body text-center text-secondary py-5">
                <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success" aria-hidden="true"></i>
                <?= $filter === 'pending' ? 'No hay conversaciones esperando respuesta.' : 'No hay conversaciones en esta vista.' ?>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($conversations as $conversation): ?>
                    <?php $unread = (int) $conversation['staff_unread'] === 1; ?>
                    <a class="list-group-item list-group-item-action" href="<?= $escape($basePath) ?>/comunicacion/atencion/ver?id=<?= (int) $conversation['id'] ?>">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <span class="badge text-bg-<?= $categoryBadges[$conversation['category']] ?? 'secondary' ?> me-1"><?= $escape(ConversationService::CATEGORIES[$conversation['category']] ?? (string) $conversation['category']) ?></span>
                                <span class="<?= $unread ? 'fw-bold' : 'fw-semibold' ?>"><?= $escape((string) $conversation['subject']) ?></span>
                                <?php if ($unread): ?><span class="badge rounded-pill text-bg-primary ms-1">Nuevo</span><?php endif; ?>
                                <div class="small text-secondary mt-1">
                                    <?= $escape((string) ($conversation['full_name'] ?: $conversation['username'])) ?>
                                    · <?= (int) $conversation['message_count'] ?> mensaje(s)
                                    · último el <?= $escape(date('d/m/Y H:i', strtotime((string) $conversation['last_message_at']) ?: time())) ?>
                                </div>
                            </div>
                            <span class="badge text-bg-<?= $statusBadges[$conversation['status']] ?? 'secondary' ?> align-self-start"><?= $escape(ConversationService::STATUS_LABELS[$conversation['status']] ?? (string) $conversation['status']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
