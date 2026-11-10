<?php

declare(strict_types=1);

use App\Core\Session;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$userPortalName = isset($user['username']) && is_string($user['username']) ? $user['username'] : '';
$avatarInitial = function_exists('mb_strtoupper')
    ? mb_strtoupper(mb_substr($userPortalName, 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($userPortalName, 0, 1));
$notificationCount = (int) ($notifications['message_count'] ?? 0)
    + (int) ($notifications['billing_count'] ?? 0);

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | <?= $escape($propertyName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0/dist/css/adminlte.min.css">
</head>
<body class="bg-body-tertiary">
<div class="d-flex min-vh-100 flex-column">
    <header class="navbar navbar-expand bg-body shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold text-wrap" href="<?= $escape($basePath) ?>/usuario">
                <i class="bi bi-buildings me-2" aria-hidden="true"></i><?= $escape($propertyName) ?>
            </a>
            <div class="d-flex align-items-center gap-3 ms-auto">
                <div class="dropdown">
                    <button class="btn btn-light border position-relative rounded-circle p-0 d-inline-flex align-items-center justify-content-center"
                            style="width: 2.5rem; height: 2.5rem"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="Notificaciones y perfil de <?= $escape($userPortalName) ?>">
                        <span class="fw-semibold"><?= $escape($avatarInitial) ?></span>
                        <?php if ($notificationCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger">
                                <?= min($notificationCount, 99) ?><?= $notificationCount > 99 ? '+' : '' ?>
                                <span class="visually-hidden">notificaciones sin leer</span>
                            </span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="min-width: min(22rem, 90vw)">
                        <div class="px-3 py-2 border-bottom">
                            <strong><?= $escape($userPortalName) ?></strong>
                            <div class="small text-secondary"><?= $notificationCount ?> notificación(es) pendiente(s)</div>
                        </div>
                        <?php if ($notificationCount === 0): ?>
                            <p class="dropdown-item-text text-secondary mb-0 py-3">No tienes notificaciones nuevas.</p>
                        <?php else: ?>
                            <?php foreach (($notifications['messages'] ?? []) as $message): ?>
                                <a class="dropdown-item text-wrap py-2" href="<?= $escape($basePath) ?>/usuario/mensajes">
                                    <i class="bi bi-envelope text-primary me-2" aria-hidden="true"></i>
                                    <strong><?= $escape($message['subject']) ?></strong>
                                    <span class="d-block small text-secondary">Mensaje de <?= $escape($message['sender_name']) ?></span>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach (($notifications['billing'] ?? []) as $notice): ?>
                                <form method="post" action="<?= $escape($basePath) ?>/usuario/notificaciones/cobro">
                                    <input type="hidden" name="_token" value="<?= $escape(Session::csrfToken()) ?>">
                                    <input type="hidden" name="statement_id" value="<?= (int) $notice['id'] ?>">
                                    <button class="dropdown-item text-wrap py-2" type="submit">
                                        <i class="bi bi-receipt text-warning me-2" aria-hidden="true"></i>
                                        <strong>Cobro <?= $escape($notice['period']) ?> · <?= $escape($notice['unit_name']) ?></strong>
                                        <span class="d-block small text-secondary">
                                            <?php if ((float) $notice['administration_fee'] > 0): ?>Cuota de administración<?php endif; ?>
                                            <?php if ((float) $notice['fine'] > 0): ?><?= (float) $notice['administration_fee'] > 0 ? ' · ' : '' ?>Multa generada<?php endif; ?>
                                            · Marcar como leído
                                        </span>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a class="dropdown-item border-top" href="<?= $escape($basePath) ?>/usuario/mensajes">
                            <i class="bi bi-envelope me-2" aria-hidden="true"></i>
                            Mis mensajes (<?= (int) ($notifications['message_count'] ?? 0) ?> sin leer)
                        </a>
                        <div class="dropdown-divider my-0"></div>
                        <a class="dropdown-item py-2" href="<?= $escape($basePath) ?>/usuario">
                            <i class="bi bi-person me-2" aria-hidden="true"></i>Mi perfil
                        </a>
                        <form method="post" action="<?= $escape($basePath) ?>/logout">
                            <input type="hidden" name="_token" value="<?= $escape(Session::csrfToken()) ?>">
                            <button class="dropdown-item py-2 text-danger" type="submit">
                                <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Cerrar sesión
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <main class="flex-grow-1">
        <div class="container-fluid py-4">
            <?= $content ?>
        </div>
    </main>
    <footer class="border-top bg-body py-3 mt-auto">
        <div class="container-fluid d-flex flex-wrap justify-content-between gap-2 text-secondary small">
            <span><?= $escape($propertyName) ?></span>
            <a href="<?= $escape($basePath) ?>/usuario">Panel de Usuario</a>
        </div>
    </footer>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0/dist/js/adminlte.min.js"></script>
</body>
</html>
