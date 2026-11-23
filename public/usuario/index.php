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
    WebBootstrap::handleException($exception);
}
