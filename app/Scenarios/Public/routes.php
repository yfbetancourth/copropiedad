<?php

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */
require dirname(__DIR__, 2) . '/Modules/Home/routes.php';
require dirname(__DIR__, 2) . '/Modules/Auth/routes.php';
require dirname(__DIR__, 2) . '/Modules/Classifieds/routes.php';
require dirname(__DIR__, 2) . '/Modules/Billing/public-routes.php';

$router->get('/robots.txt', static function (): void {
    $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo "User-agent: *\nAllow: /\nDisallow: /admin\n";
    if ($appUrl !== '') {
        echo 'Sitemap: ' . $appUrl . '/sitemap.xml' . "\n";
    }
});

$router->get('/sitemap.xml', static function (): void {
    $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
    if ($appUrl === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ([$appUrl . '/', $appUrl . '/clasificados'] as $url) {
        echo '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
    }
    echo '</urlset>';
});
