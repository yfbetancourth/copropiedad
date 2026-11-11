<?php

declare(strict_types=1);

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authUser = Authorization::currentUser();
$adminUsername = $authUser !== null && is_string($authUser['username'] ?? null)
    ? $authUser['username']
    : '';
$adminAvatarInitial = function_exists('mb_strtoupper')
    ? mb_strtoupper(mb_substr($adminUsername, 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($adminUsername, 0, 1));
$currentPath = Url::routePath($_SERVER['REQUEST_URI'] ?? '/');
$canSeeTeam = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.equipo', 1);
$canSeeRoles = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.roles', 1);
$canSeeParametrization = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.parametrizacion', 1);
$canSeeFinancial = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.financiera', 1);
$canSeeMail = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.correo', 1);
$canSeePaymentMethods = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.pagos', 1);
$canSeeCharacterization = $authUser !== null && Authorization::hasPermission($authUser, 'configuracion.caracterizacion', 1);
$canSeeComposition = false;
if ($authUser !== null && Authorization::hasPermission($authUser, 'configuracion.conformacion', 1)) {
    $compositionHousingType = (new \App\Modules\Configuration\SettingsService())->current()['housing_type'] ?? '';
    $canSeeComposition = in_array($compositionHousingType, ['casa', 'torres_apartamento', 'mixto'], true);
}
$canSeeUnitAssignments = $authUser !== null && Authorization::hasPermission($authUser, 'inmuebles.asignaciones', 1);
$canSeeBilling = $authUser !== null && Authorization::hasPermission($authUser, 'cartera.cobros', 1);
$canSeeMessages = $authUser !== null && Authorization::hasPermission($authUser, 'mensajes', 1);
$canSeeUserPortal = $authUser !== null && Authorization::hasPermission($authUser, 'usuario.portal', 1);
$inPaymentMethods = $currentPath === '/configuracion/metodos-pago';
$inMail = $currentPath === '/configuracion/correo';
$inConformation = in_array($currentPath, ['/configuracion/caracterizacion', '/configuracion/conformacion'], true)
    || str_starts_with($currentPath, '/inmuebles/');
$inConfiguration = str_starts_with($currentPath, '/configuracion/')
    && !$inPaymentMethods
    && !$inMail
    && !$inConformation;
$inUnitAssignments = str_starts_with($currentPath, '/inmuebles/');
$inBilling = str_starts_with($currentPath, '/cartera/');
$inMessages = $currentPath === '/mensajes';

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | <?= $escape($propertyName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0/dist/css/adminlte.min.css">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
            <?php if ($authUser !== null): ?>
                <ul class="navbar-nav">
                    <?php if (Authorization::hasPermission($authUser, 'panel', 1)): ?>
                        <li class="nav-item">
                            <button class="nav-link" type="button" data-lte-toggle="sidebar" aria-label="Alternar menu lateral">
                                <i class="bi bi-list" aria-hidden="true"></i>
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item dropdown">
                        <button class="btn btn-light border d-flex align-items-center gap-2 dropdown-toggle"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="Opciones de usuario para <?= $escape($adminUsername) ?>">
                            <span class="rounded-circle bg-primary-subtle text-primary fw-semibold d-inline-flex align-items-center justify-content-center"
                                  style="width: 2.25rem; height: 2.25rem" aria-hidden="true">
                                <?= $escape($adminAvatarInitial) ?>
                            </span>
                            <span class="d-none d-sm-inline"><?= $escape($adminUsername) ?></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow">
                            <div class="px-3 py-2 border-bottom">
                                <strong><?= $escape($adminUsername) ?></strong>
                                <?php if (is_string($authUser['role_name'] ?? null)): ?>
                                    <div class="small text-secondary"><?= $escape($authUser['role_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (Authorization::hasPermission($authUser, 'panel', 1)): ?>
                                <a class="dropdown-item py-2" href="<?= $escape($basePath) ?>/admin">
                                    <i class="bi bi-speedometer2 me-2" aria-hidden="true"></i>Panel administrativo
                                </a>
                            <?php endif; ?>
                            <?php if ($canSeeUserPortal): ?>
                                <a class="dropdown-item py-2" href="<?= $escape($basePath) ?>/usuario">
                                    <i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Panel de usuario
                                </a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <form method="post" action="<?= $escape($basePath) ?>/logout">
                                <input type="hidden" name="_token" value="<?= $escape(Session::csrfToken()) ?>">
                                <button class="dropdown-item py-2 text-danger" type="submit">
                                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Cerrar sesión
                                </button>
                            </form>
                        </div>
                    </li>
                </ul>
            <?php else: ?>
                <a class="navbar-brand fw-semibold text-wrap text-break mw-100" href="<?= $escape($basePath) ?>/login"><?= $escape($propertyName) ?></a>
            <?php endif; ?>
        </div>
    </nav>
    <?php if ($authUser !== null): ?>
        <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
            <div class="sidebar-brand">
                <a class="brand-link" href="<?= $escape($basePath) ?><?= Authorization::hasPermission($authUser, 'panel', 1) ? '/admin' : '/usuario' ?>" title="<?= $escape($propertyName) ?>">
                    <span class="brand-text fw-light"><?= $escape($propertyName) ?></span>
                </a>
            </div>
            <div class="sidebar-wrapper">
                <nav class="mt-2" aria-label="Menu principal">
                    <ul class="nav sidebar-menu flex-column" role="menu" data-lte-toggle="treeview" data-accordion="false">
                        <?php if (Authorization::hasPermission($authUser, 'panel', 1)): ?>
                            <li class="nav-item">
                                <a href="<?= $escape($basePath) ?>/admin" class="nav-link<?= $currentPath === '/admin' ? ' active' : '' ?>">
                                    <i class="nav-icon bi bi-speedometer2" aria-hidden="true"></i>
                                    <p>Panel</p>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (Authorization::hasPermission($authUser, 'panel', 1)
                            && ($canSeeTeam || $canSeeRoles || $canSeeParametrization || $canSeeFinancial)
                        ): ?>
                            <li class="nav-header">ADMINISTRACIÓN</li>
                        <?php endif; ?>
                        <?php if ($canSeeTeam || $canSeeRoles || $canSeeParametrization || $canSeeFinancial): ?>
                            <li class="nav-item<?= $inConfiguration ? ' menu-open' : '' ?>">
                                <a href="#" class="nav-link<?= $inConfiguration ? ' active' : '' ?>" aria-expanded="<?= $inConfiguration ? 'true' : 'false' ?>">
                                    <i class="nav-icon bi bi-gear" aria-hidden="true"></i>
                                    <p>
                                        Configuracion
                                        <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <?php if ($canSeeParametrization): ?>
                                        <li class="nav-item">
                                            <a href="<?= $escape($basePath) ?>/configuracion/parametrizacion" class="nav-link<?= $currentPath === '/configuracion/parametrizacion' ? ' active' : '' ?>">
                                                <i class="nav-icon bi bi-building-gear" aria-hidden="true"></i>
                                                <p>Parametrizacion</p>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($canSeeFinancial): ?>
                                        <li class="nav-item">
                                            <a href="<?= $escape($basePath) ?>/configuracion/financiera" class="nav-link<?= $currentPath === '/configuracion/financiera' ? ' active' : '' ?>">
                                                <i class="nav-icon bi bi-cash-coin" aria-hidden="true"></i>
                                                <p>Financiera</p>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($canSeeTeam): ?>
                                        <li class="nav-item">
                                            <a href="<?= $escape($basePath) ?>/configuracion/equipo" class="nav-link<?= $currentPath === '/configuracion/equipo' ? ' active' : '' ?>">
                                                <i class="nav-icon bi bi-people" aria-hidden="true"></i>
                                                <p>Equipo</p>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($canSeeRoles): ?>
                                        <li class="nav-item">
                                            <a href="<?= $escape($basePath) ?>/configuracion/roles" class="nav-link<?= $currentPath === '/configuracion/roles' ? ' active' : '' ?>">
                                                <i class="nav-icon bi bi-shield-lock" aria-hidden="true"></i>
                                                <p>Roles y permisos</p>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>
                        <?php if ($canSeeCharacterization || $canSeeComposition || $canSeeUnitAssignments): ?>
                            <li class="nav-header">CONFORMACIÓN</li>
                            <?php if ($canSeeCharacterization): ?>
                                <li class="nav-item">
                                    <a href="<?= $escape($basePath) ?>/configuracion/caracterizacion" class="nav-link<?= $currentPath === '/configuracion/caracterizacion' ? ' active' : '' ?>">
                                        <i class="nav-icon bi bi-buildings" aria-hidden="true"></i>
                                        <p>Caracterizacion</p>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canSeeComposition): ?>
                                <li class="nav-item">
                                    <a href="<?= $escape($basePath) ?>/configuracion/conformacion" class="nav-link<?= $currentPath === '/configuracion/conformacion' ? ' active' : '' ?>">
                                        <i class="nav-icon bi bi-diagram-3" aria-hidden="true"></i>
                                        <p>Conformación de la copropiedad</p>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canSeeUnitAssignments): ?>
                                <li class="nav-item">
                                    <a href="<?= $escape($basePath) ?>/inmuebles/asignaciones" class="nav-link<?= $inUnitAssignments ? ' active' : '' ?>">
                                        <i class="nav-icon bi bi-person-vcard" aria-hidden="true"></i>
                                        <p>Asignación de inmuebles</p>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($canSeePaymentMethods): ?>
                            <li class="nav-header">PAGOS</li>
                            <li class="nav-item">
                                <a href="<?= $escape($basePath) ?>/configuracion/metodos-pago" class="nav-link<?= $inPaymentMethods ? ' active' : '' ?>">
                                    <i class="nav-icon bi bi-credit-card" aria-hidden="true"></i>
                                    <p>Metodos de pago</p>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($canSeeBilling): ?>
                            <li class="nav-header">FINANCIERO</li>
                            <li class="nav-item">
                                <a href="<?= $escape($basePath) ?>/cartera/cobros" class="nav-link<?= $inBilling ? ' active' : '' ?>">
                                    <i class="nav-icon bi bi-receipt" aria-hidden="true"></i>
                                    <p>Cartera / Cobros</p>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($canSeeMail || $canSeeMessages): ?>
                            <li class="nav-header">COMUNICACIÓN</li>
                            <?php if ($canSeeMail): ?>
                                <li class="nav-item">
                                    <a href="<?= $escape($basePath) ?>/configuracion/correo" class="nav-link<?= $inMail ? ' active' : '' ?>">
                                        <i class="nav-icon bi bi-envelope-at" aria-hidden="true"></i>
                                        <p>Correo</p>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canSeeMessages): ?>
                                <li class="nav-item">
                                    <a href="<?= $escape($basePath) ?>/mensajes" class="nav-link<?= $inMessages ? ' active' : '' ?>">
                                        <i class="nav-icon bi bi-envelope" aria-hidden="true"></i>
                                        <p>Mensajes</p>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </aside>
    <?php endif; ?>
    <main class="app-main">
        <div class="app-content">
            <div class="container-fluid py-4">
                <?= $content ?>
            </div>
        </div>
    </main>
    <footer class="app-footer">
        <div class="float-end">
            <?php if ($authUser === null): ?>
                <a href="<?= $escape($basePath) ?>/login?destino=usuario">Iniciar sesión de usuario</a>
            <?php endif; ?>
        </div>
        <strong><?= $escape($propertyName) ?></strong>
    </footer>
</div>
<script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0/dist/js/adminlte.min.js"></script>
</body>
</html>
