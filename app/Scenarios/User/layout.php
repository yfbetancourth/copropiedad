<?php

declare(strict_types=1);

use App\Core\Session;
use App\Core\Url;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$user = is_array($user ?? null) ? $user : [];
$currentPath = Url::routePath($_SERVER['REQUEST_URI'] ?? '/usuario');
$portalHome = $basePath . '/usuario';

$accountName = is_string($user['username'] ?? null) ? $user['username'] : '';
$account = [
    'name' => $accountName,
    'email' => is_string($user['email'] ?? null) ? $user['email'] : '',
    'role' => is_string($user['role_name'] ?? null) ? $user['role_name'] : 'Residente',
    'initial' => mb_strtoupper(mb_substr($accountName, 0, 1, 'UTF-8'), 'UTF-8'),
];

$unreadMessages = (int) ($notifications['message_count'] ?? 0);
$unreadBilling = (int) ($notifications['billing_count'] ?? 0);
$unreadNotices = (int) ($notifications['notice_count'] ?? 0);
$notificationCount = $unreadMessages + $unreadBilling + $unreadNotices;
$noticeIcons = [
    'payment_received' => 'bi-check-circle text-success',
    'payment_report_approved' => 'bi-check-circle text-success',
    'payment_report_rejected' => 'bi-x-circle text-danger',
    'deposit_claim_rejected' => 'bi-x-circle text-danger',
    'booking_approved' => 'bi-calendar2-check text-success',
    'booking_rejected' => 'bi-calendar2-x text-danger',
    'conversation_reply' => 'bi-chat-dots text-primary',
    'classified_approved' => 'bi-megaphone text-success',
    'classified_rejected' => 'bi-megaphone text-danger',
];

// Mismo menú por secciones que el panel administrativo, con las opciones del residente.
// Cada opción aparece solo si el Superadmin habilitó su módulo en este conjunto.
$billingEnabled = \App\Core\ModuleCatalog::enabled('cartera');
$messagesEnabled = \App\Core\ModuleCatalog::enabled('mensajes');
$unreadConversations = $messagesEnabled && isset($user['id'])
    ? (new \App\Modules\Messaging\ConversationService())->unreadForResident((int) $user['id'])
    : 0;
$classifiedsEnabled = \App\Core\ModuleCatalog::enabled('clasificados');
$commonAreasEnabled = \App\Core\ModuleCatalog::enabled('zonas_comunes');
$sidebarSections = [
    ['title' => null, 'items' => [
        ['href' => $portalHome, 'icon' => 'bi-house-heart', 'label' => 'Bienvenida', 'active' => $currentPath === '/usuario'],
    ]],
    ['title' => 'Mi inmueble', 'items' => [
        ['href' => $portalHome . '#inmuebles', 'icon' => 'bi-house-door', 'label' => 'Mis inmuebles'],
    ]],
    ['title' => 'Pagos', 'items' => $billingEnabled ? [
        ['href' => $portalHome . '/pagos', 'icon' => 'bi-credit-card', 'label' => 'Pagar mis cuotas', 'active' => str_starts_with($currentPath, '/usuario/pagos'), 'badge' => $unreadBilling + $unreadNotices],
        ['href' => $portalHome . '/pagos#reportar', 'icon' => 'bi-upload', 'label' => 'Reportar un pago'],
    ] : []],
    ['title' => 'Comunicación', 'items' => $messagesEnabled ? [
        ['href' => $portalHome . '/comunicacion', 'icon' => 'bi-chat-dots', 'label' => 'Escribir a la administración', 'active' => str_starts_with($currentPath, '/usuario/comunicacion'), 'badge' => $unreadConversations],
        ['href' => $portalHome . '/mensajes', 'icon' => 'bi-megaphone', 'label' => 'Comunicados', 'active' => str_starts_with($currentPath, '/usuario/mensajes'), 'badge' => $unreadMessages],
    ] : []],
    ['title' => 'Comunidad', 'items' => [
        ...($commonAreasEnabled ? [['href' => $portalHome . '/zonas-comunes', 'icon' => 'bi-calendar2-week', 'label' => 'Zonas comunes', 'active' => str_starts_with($currentPath, '/usuario/zonas-comunes')]] : []),
        ...($classifiedsEnabled ? [
            ['href' => $portalHome . '#clasificados', 'icon' => 'bi-megaphone', 'label' => 'Mis clasificados'],
            ['href' => $basePath . '/clasificados', 'icon' => 'bi-shop', 'label' => 'Clasificados públicos', 'newTab' => true],
        ] : []),
    ]],
    ['title' => 'Mi cuenta', 'items' => [
        ['href' => $portalHome . '#perfil', 'icon' => 'bi-person-gear', 'label' => 'Datos y contraseña'],
    ]],
];
$sidebarBrandHref = $portalHome;
$sidebarUser = ['name' => $account['name'], 'role' => $account['role'], 'initial' => $account['initial']];

$accountGroups = [
    ['title' => 'Ir a', 'items' => array_values(array_filter([
        ['href' => $portalHome, 'icon' => 'bi-house-heart', 'label' => 'Bienvenida', 'active' => $currentPath === '/usuario'],
        ['href' => $basePath . '/', 'icon' => 'bi-globe2', 'label' => 'Ver sitio público', 'newTab' => true],
    ]))],
    ['title' => 'Mi cuenta', 'items' => array_values(array_filter([
        ['href' => $portalHome . '#perfil', 'icon' => 'bi-person-gear', 'label' => 'Editar mis datos y contraseña'],
        $messagesEnabled ? ['href' => $portalHome . '/comunicacion', 'icon' => 'bi-chat-dots', 'label' => 'Escribir a la administración'] : null,
    ]))],
];
$logoutAction = $basePath . '/logout';
$csrfToken = Session::csrfToken();

?>
<?php require dirname(__DIR__) . '/Shared/panel-head.php'; ?>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<a class="visually-hidden-focusable position-absolute top-0 start-0 z-3 bg-white p-2" href="#main-content">Saltar al contenido principal</a>
<div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <button class="nav-link" type="button" data-sidebar-toggle aria-expanded="true" aria-label="Alternar menú lateral">
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <li class="nav-item dropdown">
                    <button popovertarget="notifications-menu" class="nav-link position-relative px-2" type="button"
                            aria-label="Notificaciones<?= $notificationCount > 0 ? ': ' . $notificationCount . ' sin leer' : '' ?>">
                        <i class="bi bi-bell fs-5" aria-hidden="true"></i>
                        <?php if ($notificationCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle-x badge rounded-pill text-bg-danger" aria-hidden="true">
                                <?= min($notificationCount, 99) ?><?= $notificationCount > 99 ? '+' : '' ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <div id="notifications-menu" popover class="dropdown-menu dropdown-menu-end shadow border-0 p-0 overflow-hidden" style="min-width: min(22rem, 92vw)">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-body-tertiary border-bottom">
                            <strong>Notificaciones</strong>
                            <span class="small text-secondary"><?= $notificationCount ?> sin leer</span>
                        </div>
                        <?php if ($notificationCount === 0): ?>
                            <div class="text-center text-secondary py-4 px-3">
                                <i class="bi bi-bell-slash fs-3 d-block mb-1" aria-hidden="true"></i>
                                No tienes notificaciones nuevas.
                            </div>
                        <?php else: ?>
                            <div style="max-height: 22rem; overflow-y: auto">
                                <?php foreach (($notifications['notices'] ?? []) as $notice): ?>
                                    <form method="post" action="<?= $escape($portalHome) ?>/avisos/abrir">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>">
                                        <button class="dropdown-item d-flex gap-2 text-wrap py-2 border-bottom" type="submit">
                                            <i class="bi <?= $escape($noticeIcons[$notice['kind']] ?? 'bi-info-circle text-primary') ?> mt-1" aria-hidden="true"></i>
                                            <span>
                                                <strong class="d-block"><?= $escape((string) $notice['title']) ?></strong>
                                                <span class="small text-secondary"><?= $escape((string) $notice['body']) ?></span>
                                            </span>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                                <?php foreach (($notifications['messages'] ?? []) as $message): ?>
                                    <a class="dropdown-item d-flex gap-2 text-wrap py-2 border-bottom" href="<?= $escape($portalHome) ?>/mensajes">
                                        <i class="bi bi-envelope text-primary mt-1" aria-hidden="true"></i>
                                        <span>
                                            <strong class="d-block"><?= $escape($message['subject']) ?></strong>
                                            <span class="small text-secondary">Mensaje de <?= $escape($message['sender_name']) ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach (($notifications['billing'] ?? []) as $notice): ?>
                                    <form method="post" action="<?= $escape($portalHome) ?>/notificaciones/cobro">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="statement_id" value="<?= (int) $notice['id'] ?>">
                                        <button class="dropdown-item d-flex gap-2 text-wrap py-2 border-bottom" type="submit">
                                            <i class="bi bi-receipt text-warning mt-1" aria-hidden="true"></i>
                                            <span>
                                                <strong class="d-block">Cobro <?= $escape($notice['period']) ?> · <?= $escape($notice['unit_name']) ?></strong>
                                                <span class="small text-secondary">
                                                    <?php if ((float) $notice['administration_fee'] > 0): ?>Cuota de administración<?php endif; ?>
                                                    <?php if ((float) $notice['fine'] > 0): ?><?= (float) $notice['administration_fee'] > 0 ? ' · ' : '' ?>Multa generada<?php endif; ?>
                                                    · Marcar como leído
                                                </span>
                                            </span>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <a class="dropdown-item text-center small fw-semibold py-2" href="<?= $escape($portalHome) ?>/mensajes">Ver todos los comunicados</a>
                    </div>
                </li>
                <?php require dirname(__DIR__) . '/Shared/account-menu.php'; ?>
            </ul>
        </div>
    </nav>
    <?php require dirname(__DIR__) . '/Shared/sidebar.php'; ?>
    <main id="main-content" class="app-main">
        <div class="app-content">
            <div class="container-fluid py-4">
                <?= $content ?>
            </div>
        </div>
    </main>
    <footer class="app-footer">
        <div class="float-end">
            <a href="<?= $escape($basePath) ?>/clasificados" target="_blank" rel="noopener">Clasificados públicos</a>
        </div>
        <strong><?= $escape($propertyName) ?></strong>
    </footer>
</div>
<?php require dirname(__DIR__) . '/Shared/panel-scripts.php'; ?>
</body>
</html>
