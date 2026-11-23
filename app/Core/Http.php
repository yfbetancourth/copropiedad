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
            http_response_code(419);
            echo 'La sesión del formulario venció. Recarga la página e intenta nuevamente.';
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
