<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Tenant;

/** @var Router $router */
require dirname(__DIR__, 2) . '/Modules/Auth/routes.php';

// Dominio principal: portada de la plataforma y acceso del Superadmin; no hay datos de un conjunto.
if (Tenant::isPlatform()) {
    $router->get('/', static function (): void {
        header('Location: ' . \App\Core\Url::to('/admin'), true, 303);
    });
    $router->get('/robots.txt', static function (): void {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /admin\nDisallow: /login\n";
    });
    return;
}

require dirname(__DIR__, 2) . '/Modules/Home/routes.php';
require dirname(__DIR__, 2) . '/Modules/Classifieds/routes.php';
require dirname(__DIR__, 2) . '/Modules/Billing/public-routes.php';

$router->get('/robots.txt', static function (): void {
    $appUrl = rtrim(Tenant::absoluteUrl('/'), '/');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo "User-agent: *\nAllow: /\nDisallow: /admin\n";
    if ($appUrl !== '') {
        echo 'Sitemap: ' . $appUrl . '/sitemap.xml' . "\n";
    }
});

$router->get('/sitemap.xml', static function (): void {
    $appUrl = rtrim(Tenant::absoluteUrl('/'), '/');
    if ($appUrl === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $urls = [$appUrl . '/'];
    if (\App\Core\ModuleCatalog::enabled('clasificados')) {
        $urls[] = $appUrl . '/clasificados';
    }
    foreach ($urls as $url) {
        echo '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
    }
    echo '</urlset>';
});
