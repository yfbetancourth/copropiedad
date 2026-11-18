<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

final class WebBootstrap
{
    public static function router(string $projectRoot): Router
    {
        Dotenv::createImmutable($projectRoot)->safeLoad();

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self'");
        header('Cache-Control: no-store, private');

        return new Router();
    }
}
