<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use RuntimeException;
use App\Core\Tenant;

final class PropertyGalleryService
{
    private const MAX_IMAGES = 8;
    private const MAX_BYTES = 3 * 1024 * 1024;
    private const MAX_PIXELS = 24_000_000;

    /** @return list<array{name: string, url: string}> */
    public function images(): array
    {
        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $images = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^\d{2}-[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$/D', $name) !== 1 || !is_file($file)) {
                continue;
            }
            $images[] = ['name' => $name, 'url' => '/uploads/property-gallery/' . Tenant::id() . '/' . rawurlencode($name)];
        }

        return $images;
    }

    public function upload(array $files): int
    {
        $names = $files['name'] ?? [];
        $temporaryNames = $files['tmp_name'] ?? [];
        $errors = $files['error'] ?? [];
        $sizes = $files['size'] ?? [];
        if (!is_array($names) || !is_array($temporaryNames) || !is_array($errors) || !is_array($sizes)) {
            throw new RuntimeException('Selecciona imágenes válidas para la galería.');
        }

        $uploads = [];
        foreach ($names as $index => $originalName) {
            if (!is_string($originalName) || $originalName === '') {
                continue;
            }
            $error = $errors[$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK || !is_string($temporaryNames[$index] ?? null)) {
                throw new RuntimeException('Una imagen no se pudo cargar. Verifica el tamaño máximo de 3 MB por foto.');
            }
            $temporaryName = $temporaryNames[$index];
            $size = $sizes[$index] ?? 0;
            if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES || !is_uploaded_file($temporaryName)) {
                throw new RuntimeException('Cada imagen debe pesar máximo 3 MB.');
            }

            $info = @getimagesize($temporaryName);
            $mime = is_array($info) ? ($info['mime'] ?? '') : '';
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => '',
            };
            $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
            $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
            if ($extension === '' || $width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
                throw new RuntimeException('Usa imágenes JPG, PNG o WebP válidas, de hasta 24 megapíxeles.');
            }

            $uploads[] = ['temporary_name' => $temporaryName, 'extension' => $extension];
        }

        if ($uploads === []) {
            throw new RuntimeException('Selecciona al menos una imagen.');
        }
        if (count($this->images()) + count($uploads) > self::MAX_IMAGES) {
            throw new RuntimeException('La galería admite hasta ocho imágenes. Elimina alguna antes de agregar más.');
        }

        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de la galería.');
        }

        $saved = [];
        try {
            $nextPosition = count($this->images()) + 1;
            foreach ($uploads as $offset => $upload) {
                $name = sprintf('%02d-', $nextPosition + $offset) . bin2hex(random_bytes(16)) . '.' . $upload['extension'];
                $target = $directory . DIRECTORY_SEPARATOR . $name;
                if (!move_uploaded_file($upload['temporary_name'], $target)) {
                    throw new RuntimeException('No se pudo guardar una imagen. Intenta nuevamente.');
                }
                $saved[] = $target;
            }
        } catch (RuntimeException $exception) {
            foreach ($saved as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            throw $exception;
        }

        return count($saved);
    }

    public function delete(string $name): bool
    {
        if (preg_match('/^\d{2}-[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$/D', $name) !== 1) {
            return false;
        }
        $path = $this->directory() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) && unlink($path);
    }

    /** Cada conjunto tiene su propia carpeta de galería. */
    private function directory(): string
    {
        return self::directoryFor(Tenant::id());
    }

    /** Carpeta pública de la galería de un conjunto (también la usa el reinicio del Superadmin). */
    public static function directoryFor(int $tenantId): string
    {
        return Tenant::homeDirectory($tenantId) . '/public/uploads/property-gallery/' . $tenantId;
    }
}
