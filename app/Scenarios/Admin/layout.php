<?php

declare(strict_types=1);

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authUser = Authorization::currentUser();
$currentPath = Url::routePath($_SERVER['REQUEST_URI'] ?? '/');
$adminHome = Url::adminHome();
$userPortalHome = Url::publicBasePath() . '/usuario';
$can = static fn (?string $module): bool => $authUser !== null && $module !== null && Authorization::hasPermission($authUser, $module, 1);

$accountName = is_string($authUser['username'] ?? null) ? $authUser['username'] : '';
$account = [
    'name' => $accountName,
    'email' => is_string($authUser['email'] ?? null) ? $authUser['email'] : '',
    'role' => is_string($authUser['role_name'] ?? null) ? $authUser['role_name'] : '',
    'initial' => mb_strtoupper(mb_substr($accountName, 0, 1, 'UTF-8'), 'UTF-8'),
];

$canSeeComposition = false;
if ($can('configuracion.conformacion')) {
    $compositionHousingType = (new \App\Modules\Configuration\SettingsService())->current()['housing_type'] ?? '';
    // El asistente de conformación solo tiene sentido cuando ya se eligió el tipo de vivienda.
    $canSeeComposition = in_array($compositionHousingType, ['casa', 'torres_apartamento', 'mixto'], true);
}

/*
 * Menú lateral: cada sección agrupa enlaces y solo se muestra si el rol puede abrir al menos uno.
 * Enlace: [permiso, ruta, ícono, etiqueta, rutas que lo marcan activo (prefijos terminados en *)]
 */
$menu = [
    [null, [
        ['panel', '/admin', 'bi-house-heart', 'Bienvenida', ['/admin']],
    ]],
    ['Financiero', [
        ['cartera.cobros', '/cartera/cobros', 'bi-receipt', 'Cartera / Cobros', ['/cartera/cobros*']],
        ['cartera.cobros', '/cartera/reportes', 'bi-graph-up', 'Reportes de cartera', ['/cartera/reportes*']],
        ['cartera.cobros', '/cartera/buscar', 'bi-search', 'Buscar en cartera', ['/cartera/buscar']],
    ]],
    ['Pagos', [
        ['configuracion.pagos', '/configuracion/metodos-pago', 'bi-credit-card', 'Métodos de pago', ['/configuracion/metodos-pago']],
    ]],
    ['Comunicación', [
        ['mensajes', '/mensajes', 'bi-chat-left-text', 'Mensajes', ['/mensajes']],
        ['configuracion.correo', '/configuracion/correo', 'bi-envelope-at', 'Correo', ['/configuracion/correo']],
    ]],
    ['Conformación', [
        ['configuracion.caracterizacion', '/configuracion/caracterizacion', 'bi-buildings', 'Caracterización', ['/configuracion/caracterizacion']],
        [$canSeeComposition ? 'configuracion.conformacion' : null, '/configuracion/conformacion', 'bi-diagram-3', 'Conformación de la copropiedad', ['/configuracion/conformacion']],
        ['inmuebles.asignaciones', '/inmuebles/asignaciones', 'bi-person-vcard', 'Asignación de inmuebles', ['/inmuebles/*']],
    ]],
    ['Administración', [
        ['configuracion.parametrizacion', '/configuracion/parametrizacion', 'bi-building-gear', 'Parametrización', ['/configuracion/parametrizacion']],
        ['configuracion.financiera', '/configuracion/financiera', 'bi-sliders', 'Configuración financiera', ['/configuracion/financiera']],
        ['configuracion.equipo', '/configuracion/equipo', 'bi-people', 'Equipo', ['/configuracion/equipo']],
        ['configuracion.roles', '/configuracion/roles', 'bi-shield-lock', 'Roles y permisos', ['/configuracion/roles']],
    ]],
    ['Personalización', [
        ['configuracion.parametrizacion', '/configuracion/galeria-publica', 'bi-images', 'Galería pública', ['/configuracion/galeria-publica']],
    ]],
];
$isActivePath = static function (array $patterns) use ($currentPath): bool {
    foreach ($patterns as $pattern) {
        if (str_ends_with($pattern, '*') ? str_starts_with($currentPath, rtrim($pattern, '*')) : $currentPath === $pattern) {
            return true;
        }
    }

    return false;
};
$sidebarSections = [];
foreach ($menu as [$menuTitle, $menuEntries]) {
    $menuItems = [];
    foreach ($menuEntries as [$menuModule, $menuPath, $menuIcon, $menuLabel, $menuPatterns]) {
        if ($can($menuModule)) {
            $menuItems[] = [
                'href' => $menuPath === '/admin' ? $adminHome : $basePath . $menuPath,
                'icon' => $menuIcon,
                'label' => $menuLabel,
                'active' => $isActivePath($menuPatterns),
            ];
        }
    }
    $sidebarSections[] = ['title' => $menuTitle, 'items' => $menuItems];
}
$sidebarSections[] = ['title' => null, 'items' => $can('usuario.portal')
    ? [['href' => $userPortalHome, 'icon' => 'bi-house-door', 'label' => 'Portal de residente']]
    : []];
$sidebarBrandHref = $can('panel') ? $adminHome : $userPortalHome;
$sidebarUser = ['name' => $account['name'], 'role' => $account['role'], 'initial' => $account['initial']];

$accountGroups = [
    ['title' => 'Ir a', 'items' => array_values(array_filter([
        $can('panel') ? ['href' => $adminHome, 'icon' => 'bi-house-heart', 'label' => 'Bienvenida', 'active' => $currentPath === '/admin'] : null,
        $can('usuario.portal') ? ['href' => $userPortalHome, 'icon' => 'bi-house-door', 'label' => 'Portal de residente'] : null,
        ['href' => Url::publicBasePath() . '/', 'icon' => 'bi-globe2', 'label' => 'Ver sitio público', 'newTab' => true],
    ]))],
    ['title' => 'Mi cuenta', 'items' => array_values(array_filter([
        $can('usuario.portal') ? ['href' => $userPortalHome . '#perfil', 'icon' => 'bi-person-gear', 'label' => 'Editar mis datos y contraseña'] : null,
        $authUser !== null && Authorization::hasPermission($authUser, 'cartera.cobros', 2)
            ? ['href' => $basePath . '/configuracion/mi-correo', 'icon' => 'bi-envelope-check', 'label' => 'Mi correo de envío', 'active' => $currentPath === '/configuracion/mi-correo']
            : null,
    ]))],
];
$logoutAction = $basePath . '/logout';
$csrfToken = Session::csrfToken();

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
<a class="visually-hidden-focusable position-absolute top-0 start-0 z-3 bg-white p-2" href="#main-content">Saltar al contenido principal</a>
<div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
            <?php if ($authUser !== null): ?>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <button class="nav-link" type="button" data-lte-toggle="sidebar" aria-label="Alternar menú lateral">
                            <i class="bi bi-list" aria-hidden="true"></i>
                        </button>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto align-items-center">
                    <?php require dirname(__DIR__) . '/Shared/account-menu.php'; ?>
                </ul>
            <?php else: ?>
                <a class="navbar-brand fw-semibold text-wrap text-break mw-100" href="<?= $escape($basePath) ?>/login"><?= $escape($propertyName) ?></a>
            <?php endif; ?>
        </div>
    </nav>
    <?php if ($authUser !== null): ?>
        <?php require dirname(__DIR__) . '/Shared/sidebar.php'; ?>
    <?php endif; ?>
    <main id="main-content" class="app-main">
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
