<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Política de seguridad de contenido (CSP) sin 'unsafe-inline' para scripts.
 *
 * Los <script> en línea que necesitan datos de PHP llevan el nonce de la petición (Csp::nonce()); los
 * comportamientos sencillos (confirmar, imprimir, enviar al cambiar) se declaran con atributos data-* y los
 * atiende public/assets/js/app.js. Los atributos on*="…" y los enlaces javascript: quedan bloqueados.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** Nonce aleatorio de esta petición (128 bits, hexadecimal: válido como base64 en la CSP). */
    public static function nonce(): string
    {
        return self::$nonce ??= bin2hex(random_bytes(16));
    }

    public static function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self' https://checkout.bold.co https://checkout.wompi.co",
            "img-src 'self' data:",
            "font-src 'self'",
            // Los estilos en línea (atributos style="…") siguen permitidos: no ejecutan código.
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'nonce-" . self::nonce() . "'",
            "script-src-attr 'none'",
            "connect-src 'self'",
        ]);
    }

    /** URL del script compartido de comportamientos declarativos. */
    public static function appScriptUrl(): string
    {
        return Url::publicBasePath() . '/assets/js/app.js?v=2';
    }

    /**
     * Para documentos imprimibles que se entregan como página completa (estado de cuenta, recibo, paz y salvo):
     * añade app.js antes de </body> para que funcione el botón «Imprimir». No se usa en los correos.
     */
    public static function printable(string $html): string
    {
        $tag = '<script src="' . htmlspecialchars(self::appScriptUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" defer></script>';
        $position = strripos($html, '</body>');

        return $position === false ? $html . $tag : substr_replace($html, $tag, $position, 0);
    }
}
