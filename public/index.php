<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Url;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

$router = new Router();
require dirname(__DIR__) . '/app/Modules/Home/routes.php';
require dirname(__DIR__) . '/app/Modules/Auth/routes.php';
require dirname(__DIR__) . '/app/Modules/Configuration/routes.php';
require dirname(__DIR__) . '/app/Modules/PropertyAssignment/routes.php';

try {
    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        Url::routePath($_SERVER['REQUEST_URI'] ?? '/')
    );
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);

    $isDebug = ($_ENV['APP_ENV'] ?? 'production') !== 'production'
        && filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

    echo $isDebug
        ? nl2br(htmlspecialchars((string) $exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        : 'Ocurrio un error interno. Consulte el registro de la aplicacion.';
}
