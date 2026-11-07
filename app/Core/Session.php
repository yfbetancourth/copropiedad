<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('No se puede iniciar la sesion despues de enviar la respuesta.');
        }

        session_set_cookie_params([
            'httponly' => true,
            'secure' => self::isHttps(),
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::start();
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(mixed $token): bool
    {
        self::start();

        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function isHttps(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
