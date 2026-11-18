<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Url;
use App\Core\WebBootstrap;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';
$router = WebBootstrap::router($projectRoot);
require $projectRoot . '/app/Scenarios/User/routes.php';

try {
    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        Url::routePath($_SERVER['REQUEST_URI'] ?? '/usuario')
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
