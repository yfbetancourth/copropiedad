<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Url;
use App\Core\WebBootstrap;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';
$router = WebBootstrap::router($projectRoot);
require $projectRoot . '/app/Scenarios/Admin/routes.php';

try {
    $path = Url::routePath($_SERVER['REQUEST_URI'] ?? '/admin');
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $exception) {
    WebBootstrap::handleException($exception);
}
