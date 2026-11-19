<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
// php -S resuelve /admin/... y /usuario/... a su index.php; esos directorios son paneles, no un prefijo de la app.
if (in_array($scriptDirectory, ['/admin', '/usuario'], true)) {
    $scriptDirectory = '/';
}
$appPrefix = $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');
if ($appPrefix !== '' && str_starts_with($requestPath, $appPrefix . '/')) {
    $requestPath = substr($requestPath, strlen($appPrefix));
}

if ($requestPath === '/admin' || str_starts_with($requestPath, '/admin/')) {
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require __DIR__ . '/admin/index.php';
    return true;
}

if ($requestPath === '/usuario' || str_starts_with($requestPath, '/usuario/')) {
    $_SERVER['SCRIPT_NAME'] = '/usuario/index.php';
    require __DIR__ . '/usuario/index.php';
    return true;
}

$requestedFile = __DIR__ . $requestPath;
if ($requestPath !== '/' && is_file($requestedFile)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
