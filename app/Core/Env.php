<?php

declare(strict_types=1);

namespace App\Core;

/** Lectura de variables de entorno (.env, servidor o proceso). */
final class Env
{
    public static function get(string $name, bool $trim = true): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value)) {
            return '';
        }

        return $trim ? trim($value) : $value;
    }
}
