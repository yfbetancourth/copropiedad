<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Utilidades de petición y respuesta compartidas por todos los controladores: CSRF, redirecciones,
 * mensajes flash y lectura segura de entradas.
 */
final class Http
{
    /** Corta la petición si el token del formulario no es válido. */
    public static function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(403); // 419 no es estándar: Apache lo convierte en 500.
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>La sesión del formulario venció</title></head>'
                . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f4ee;color:#1f2721;margin:0;padding:48px 16px">'
                . '<main style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px">'
                . '<h1 style="font-size:20px;margin:0 0 12px">La sesión del formulario venció</h1>'
                . '<p style="margin:0 0 12px;line-height:1.5">Por seguridad, el formulario dejó de ser válido: pasó mucho tiempo sin actividad o la sesión se cerró en otra pestaña. No se guardó ningún cambio.</p>'
                . '<p style="margin:0 0 20px;line-height:1.5">Vuelve, recarga la página y envíalo de nuevo.</p>'
                . '<a href="' . $escape(Url::to('/')) . '" data-back style="color:#2f4637;font-weight:600">← Volver al formulario</a></main>'
                . '<script src="' . $escape(Csp::appScriptUrl()) . '" defer></script></body></html>';
            exit;
        }
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }

    /** Guarda un mensaje para mostrarlo en la siguiente página; $key separa los mensajes de cada sección. */
    public static function flash(string $key, string $type, string $message): void
    {
        Session::start();
        $_SESSION[$key] = ['type' => $type, 'message' => $message];
    }

    /** Lee y elimina el mensaje flash de una sección. */
    public static function pullFlash(string $key): mixed
    {
        Session::start();
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return $value;
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Entero de un formulario: null si viene vacío, false si no es un entero válido. */
    public static function integer(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : false;
    }

    /** Identificador positivo de un formulario o de la URL; 0 si no es válido. */
    public static function id(mixed $value): int
    {
        $id = self::integer($value);

        return is_int($id) && $id > 0 ? $id : 0;
    }
}
