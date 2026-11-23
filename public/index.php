<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Url;
use App\Core\WebBootstrap;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
$router = WebBootstrap::router($projectRoot);
require $projectRoot . '/app/Scenarios/Public/routes.php';

try {
    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        Url::routePath($_SERVER['REQUEST_URI'] ?? '/')
    );
} catch (Throwable $exception) {
    WebBootstrap::handleException($exception);
}
