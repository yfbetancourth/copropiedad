<?php

declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function basePath(): string
    {
        $appUrl = $_ENV['APP_URL'] ?? '';
        $configuredPath = is_string($appUrl) && $appUrl !== ''
            ? parse_url($appUrl, PHP_URL_PATH)
            : null;

        if (is_string($configuredPath) && $configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

        return rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    }

    public static function to(string $path): string
    {
        $basePath = self::basePath();

        return $basePath . '/' . ltrim($path, '/');
    }

    public static function routePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }

        $basePath = self::basePath();
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }
}
