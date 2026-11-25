<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$date = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
$emptyMessages = ['new' => 'No hay mensajes nuevos.', 'read' => 'No hay mensajes leídos.', 'todos' => 'Aún no han llegado mensajes desde la página pública.'];
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-envelope-paper me-2" aria-hidden="true"></i>Contacto del sitio web</h1>
        <p class="text-secondary mb-0">Mensajes del formulario de contacto de la página pública. Cada visitante autorizó el tratamiento de sus datos para que le respondan; responde desde tu correo. Elimina el mensaje cuando el titular pida suprimir sus datos o cuando ya no sea necesario conservarlo.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>
    <?php if (!$formAvailable): ?>
        <div class="alert alert-info">El formulario no se muestra en la página pública: falta publicar la política de datos y la autorización, o está desactivado. <?php if ($canSeeConsents): ?><a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/privacidad">Ir a Privacidad y cookies</a><?php endif; ?></div>
    <?php endif; ?>

    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
        <?php foreach ($filters as $key => $label): ?>
            <?php $count = $key === 'todos' ? array_sum($counts) : (int) ($counts[$key] ?? 0); ?>
            <li class="nav-item">
                <a class="nav-link<?= $filter === $key ? ' active' : '' ?>" href="?estado=<?= $escape($key) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $escape($label) ?> <span class="badge <?= $key === 'new' && $count > 0 ? 'text-bg-warning' : 'text-bg-light border' ?> ms-1"><?= $count ?></span></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($messages === []): ?>
        <div class="alert alert-light border"><?= $escape($emptyMessages[$filter]) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($messages as $message): ?>
            <?php $messageId = (int) $message['id']; ?>
            <div class="col-xl-6">
                <article class="card h-100<?= $message['status'] === 'new' ? ' border-warning' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <h2 class="h6 mb-1"><?= $escape($message['name']) ?></h2>
                            <span class="badge <?= $message['status'] === 'new' ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= $message['status'] === 'new' ? 'Nuevo' : 'Leído' ?></span>
                        </div>
                        <p class="small text-secondary mb-2">
                            <?= $escape($date($message['created_at'])) ?> ·
                            <a href="mailto:<?= $escape($message['email']) ?>"><?= $escape($message['email']) ?></a>
                            <?= $message['phone'] ? ' · ' . $escape($message['phone']) : '' ?>
                        </p>
                        <p class="mb-2" style="white-space: pre-line"><?= $escape($message['message']) ?></p>
                        <p class="small text-secondary mb-0">
                            <i class="bi bi-check2-square me-1" aria-hidden="true"></i><?= $message['revoked_at'] === null ? 'Autorizó el tratamiento de sus datos' : 'El titular revocó su autorización' ?>
                            <?php if ($canSeeConsents): ?> · <a href="<?= $escape($basePath) ?>/configuracion/privacidad/autorizaciones?<?= $escape(http_build_query(['q' => $message['email']])) ?>">ver registro</a><?php endif; ?>
                            <?php if ($message['read_at'] !== null): ?> · leído el <?= $escape($date($message['read_at'])) ?><?= $message['read_by_name'] ? ' por ' . $escape($message['read_by_name']) : '' ?><?php endif; ?>
                        </p>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="card-footer bg-transparent d-flex gap-2 flex-wrap">
                            <a class="btn btn-sm btn-primary" href="mailto:<?= $escape($message['email']) ?>?subject=<?= $escape(rawurlencode('Respuesta a tu mensaje')) ?>"><i class="bi bi-reply me-1" aria-hidden="true"></i>Responder por correo</a>
                            <?php if ($message['status'] === 'new'): ?>
                                <form method="post" action="<?= $escape($basePath) ?>/comunicacion/contacto/leido">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="message_id" value="<?= $messageId ?>">
                                    <input type="hidden" name="estado" value="<?= $escape($filter) ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Marcar como leído</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= $escape($basePath) ?>/comunicacion/contacto/eliminar" onsubmit="return confirm('¿Eliminar este mensaje? La autorización se conserva en el registro.');">
                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="message_id" value="<?= $messageId ?>">
                                <input type="hidden" name="estado" value="<?= $escape($filter) ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>
