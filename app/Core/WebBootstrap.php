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
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self'");
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
        $timezone = (string) ($_ENV['APP_TIMEZONE'] ?? 'America/Bogota');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'America/Bogota';
        }
        date_default_timezone_set($timezone);
    }
}
