<?php

declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function basePath(): string
    {
        $appUrl = Env::get('APP_URL');
        $configuredPath = $appUrl !== ''
            ? parse_url($appUrl, PHP_URL_PATH)
            : null;

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $isAdminEntryPoint = preg_match('~/admin(?:/index\.php)?$~', $scriptName) === 1;
        $isUserEntryPoint = preg_match('~/usuario(?:/index\.php)?$~', $scriptName) === 1;
        $basePath = is_string($configuredPath) && $configuredPath !== ''
            ? rtrim($configuredPath, '/')
            : rtrim(dirname($scriptName), '/');

        // Requests served by public/admin/index.php keep all app links under /admin.
        if ($isAdminEntryPoint) {
            if ((!is_string($configuredPath) || $configuredPath === '') && str_ends_with($basePath, '/admin')) {
                $basePath = substr($basePath, 0, -strlen('/admin'));
            }
            return rtrim($basePath, '/') . '/admin';
        }

        if ($isUserEntryPoint && (!is_string($configuredPath) || $configuredPath === '')
            && str_ends_with($basePath, '/usuario')) {
            return substr($basePath, 0, -strlen('/usuario'));
        }

        return $basePath;
    }

    public static function to(string $path): string
    {
        if ($path === '/admin' || $path === '/admin/') {
            return self::adminHome();
        }

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $pathOnly = parse_url($path, PHP_URL_PATH);
        if (str_ends_with($scriptName, '/admin/index.php')
            && is_string($pathOnly)
            && ($pathOnly === '/usuario' || str_starts_with($pathOnly, '/usuario/'))) {
            return self::publicBasePath() . '/' . ltrim($path, '/');
        }

        $basePath = self::basePath();

        return $basePath . '/' . ltrim($path, '/');
    }

    public static function routePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }

        // Sin distinguir mayúsculas: en Windows, Apache sirve /copropiedad/ igual que /Copropiedad/.
        $basePath = self::basePath();
        $baseLength = strlen($basePath);
        if ($basePath !== '' && strncasecmp($path, $basePath, $baseLength) === 0
            && (strlen($path) === $baseLength || $path[$baseLength] === '/')) {
            $path = substr($path, $baseLength);
        }

        if (str_ends_with(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/admin/index.php')
            && ($path === '' || $path === '/')) {
            return '/admin';
        }

        if (str_ends_with(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/usuario/index.php')
            && $path === '/usuario/') {
            return '/usuario';
        }

        return $path === '' ? '/' : $path;
    }

    public static function adminHome(): string
    {
        return preg_match('~/admin(?:/index\.php)?$~', str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '')) === 1
            ? self::basePath()
            : self::basePath() . '/admin';
    }

    public static function publicBasePath(): string
    {
        $basePath = self::basePath();
        if (preg_match('~/admin(?:/index\.php)?$~', str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '')) === 1) {
            return preg_replace('~/admin$~', '', $basePath) ?? $basePath;
        }

        return $basePath;
    }
}
