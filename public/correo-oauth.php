<?php

declare(strict_types=1);

/**
 * Retorno de «Conectar con Google / Microsoft» (OAuth 2.0). Es la MAIL_OAUTH_REDIRECT_URI registrada en Google Cloud y
 * Microsoft Entra, la misma para todos los conjuntos. No usa sesión: el «state» (de un solo uso, con vencimiento,
 * guardado como hash) identifica el conjunto, el usuario y la cuenta. Al terminar vuelve al panel con un código fijo.
 */

use App\Core\WebBootstrap;
use App\Modules\Configuration\MailOAuthService;
use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
Dotenv::createImmutable($projectRoot)->safeLoad();
WebBootstrap::configureTimezone();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// El código de autorización viaja en la URL: no se envía a ningún otro sitio por el Referer.
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
header('Cache-Control: no-store, private');

$text = static fn (string $key): string => is_string($_GET[$key] ?? null) ? trim((string) $_GET[$key]) : '';
$page = static function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Conexión de correo</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f4ee;color:#1f2721;margin:0;padding:48px 16px">'
        . '<main style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">No se pudo conectar la cuenta de correo</h1>'
        . '<p style="line-height:1.5;margin:0">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '</main></body></html>';
};

try {
    $result = (new MailOAuthService())->complete($text('state'), $text('code'), $text('error'));
    $separator = str_contains($result['return_url'], '?') ? '&' : '?';
    header('Location: ' . $result['return_url'] . $separator . 'correo=' . rawurlencode($result['code']), true, 303);
} catch (PDOException $exception) {
    error_log('Retorno de conexión de correo: ' . $exception->getMessage());
    $page(500, 'Ocurrió un error interno. Vuelve a intentarlo desde la configuración de correo.');
} catch (RuntimeException $exception) {
    $page(400, $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Retorno de conexión de correo: ' . $exception);
    $page(500, 'Ocurrió un error interno. Vuelve a intentarlo desde la configuración de correo.');
}
