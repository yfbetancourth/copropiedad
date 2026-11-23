<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

final class WebBootstrap
{
    public static function router(string $projectRoot): Router
    {
        Dotenv::createImmutable($projectRoot)->safeLoad();
        self::configureTimezone();

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self' https://checkout.bold.co https://checkout.wompi.co; img-src 'self' data:; font-src 'self' data: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self'");
        header('Cache-Control: no-store, private');

        // El subdominio decide el conjunto residencial (o la plataforma) antes de registrar las rutas.
        Tenant::resolve();

        return new Router();
    }

    /**
     * Zona horaria de la aplicación: períodos, vencimientos y fechas de pago se calculan en hora de Colombia
     * sin depender del date.timezone del servidor. Se puede cambiar con APP_TIMEZONE.
     */
    public static function configureTimezone(): void
    {
        $timezone = Env::get('APP_TIMEZONE') ?: 'America/Bogota';
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'America/Bogota';
        }
        date_default_timezone_set($timezone);
    }

    /**
     * Respuesta ante un error no controlado, común al sitio público, al panel y al portal. Si la causa es una
     * tabla o columna que aún no existe (migración pendiente), lo dice con claridad en lugar de un error genérico.
     */
    public static function handleException(\Throwable $exception): void
    {
        error_log((string) $exception);
        $missingSchema = $exception instanceof \PDOException
            && in_array((string) $exception->getCode(), ['42S02', '42S22'], true);
        http_response_code($missingSchema ? 503 : 500);
        if (Env::get('APP_ENV') !== 'production' && filter_var(Env::get('APP_DEBUG'), FILTER_VALIDATE_BOOL)) {
            echo nl2br(htmlspecialchars((string) $exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            return;
        }
        $title = $missingSchema ? 'Sección pendiente de actualización' : 'Ocurrió un error interno';
        $message = $missingSchema
            ? 'Esta sección necesita una actualización de la base de datos que aún no se ha aplicado (migraciones pendientes en database/migrations). Avisa al administrador de la plataforma; el resto del sistema sigue funcionando.'
            : 'No pudimos completar la solicitud. Intenta de nuevo; si el problema continúa, avisa a la administración.';
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title></head>'
            . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f4ee;color:#1f2721;margin:0;padding:48px 16px">'
            . '<main style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px">'
            . '<h1 style="font-size:20px;margin:0 0 12px">' . $title . '</h1><p style="margin:0 0 20px;line-height:1.5">' . $message . '</p>'
            . '<a href="javascript:history.back()" style="color:#2f4637;font-weight:600">← Volver</a></main></body></html>';
    }
}
