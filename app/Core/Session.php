<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Session
{
    /** Vida mínima de los datos de sesión en el servidor (debe superar los 30 min de inactividad permitidos). */
    private const LIFETIME_SECONDS = 7200;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('No se puede iniciar la sesion despues de enviar la respuesta.');
        }

        // Reject caller-supplied session IDs that PHP has not issued.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // PHP borra por defecto las sesiones a los 24 min (gc_maxlifetime=1440), antes del cierre por inactividad
        // de 30 min de Authorization: un formulario abierto ese tiempo perdía su token CSRF. Margen: 2 horas.
        if ((int) ini_get('session.gc_maxlifetime') < self::LIFETIME_SECONDS) {
            ini_set('session.gc_maxlifetime', (string) self::LIFETIME_SECONDS);
        }

        session_name(self::cookieName());
        session_set_cookie_params([
            'httponly' => true,
            'secure' => self::isHttps(),
            'samesite' => 'Lax',
            'path' => self::cookiePath(),
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

    /**
     * Nombre propio de la cookie para la plataforma y para cada conjunto. Con un nombre común (PHPSESSID), la
     * cookie de la raíz (ruta /) también se enviaba a las copias de los conjuntos: compartían una misma sesión y
     * al iniciar sesión en uno (o al salir del otro) se borraba la del otro, con su token CSRF.
     */
    private static function cookieName(): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]/', '', Env::get('APP_TENANT')) ?? '';

        return $slug === '' ? 'COPROPIEDAD_SID' : 'COPROPIEDAD_SID_' . strtoupper($slug);
    }

    /**
     * La copia de un conjunto limita la cookie a su ruta (APP_URL), para que varias copias servidas
     * desde el mismo host (p. ej. localhost) no compartan la sesión.
     */
    private static function cookiePath(): string
    {
        if (Env::get('APP_TENANT') === '') {
            return '/';
        }
        $path = parse_url(Env::get('APP_URL'), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? rtrim($path, '/') . '/' : '/';
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
