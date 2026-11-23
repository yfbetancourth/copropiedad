<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Directorios privados de archivos subidos (fuera de public/), p. ej. storage/classifieds. */
final class Storage
{
    public static function directory(string $name): string
    {
        if (!preg_match('/^[a-z0-9-]+$/D', $name)) {
            throw new RuntimeException('El nombre del almacenamiento no es válido.');
        }
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de archivos.');
        }

        return $directory;
    }
}
