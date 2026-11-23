<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Core\Database;
use App\Core\Storage;
use App\Core\Tenant;
use RuntimeException;
use Throwable;

final class ClassifiedsService
{
    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;
    private const MAX_IMAGES = 5;
    private const MAX_IMAGE_PIXELS = 25_000_000;

    public function publicListings(int $limit = 50): array
    {
        return $this->listings('c.status = ?', ['published'], max(1, min(50, $limit)));
    }

    public function createListing(int $userId, array $data, array $files): void
    {
        $listing = $this->validateListing($data);
        $uploads = $this->validateUploads($files);
        if ($uploads === []) {
            throw new RuntimeException('Carga al menos una imagen para publicar el clasificado.');
        }

        $pdo = Database::connection();
        $storedPaths = [];
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'INSERT INTO property_classifieds
                    (tenant_id, user_id, operation, title, property_type, location, description, price, status,
                     contact_name, contact_email, contact_phone, contact_authorized, contact_authorized_at)
                 VALUES
                    (:tenant_id, :user_id, :operation, :title, :property_type, :location, :description, :price, \'published\',
                     :contact_name, :contact_email, :contact_phone, :contact_authorized,
                     IF(:contact_authorized_at = 1, UTC_TIMESTAMP(), NULL))'
            );
            $statement->execute([
                'tenant_id' => Tenant::id(),
                'user_id' => $userId,
                'operation' => $listing['operation'],
                'title' => $listing['title'],
                'property_type' => $listing['property_type'],
                'location' => $listing['location'],
                'description' => $listing['description'],
                'price' => $listing['price'],
                'contact_name' => $listing['contact_name'],
                'contact_email' => $listing['contact_email'],
                'contact_phone' => $listing['contact_phone'],
                'contact_authorized' => $listing['contact_authorized'] ? 1 : 0,
                'contact_authorized_at' => $listing['contact_authorized'] ? 1 : 0,
            ]);
            $listingId = (int) $pdo->lastInsertId();
            $this->storeImages($listingId, $uploads, $storedPaths);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->discardFiles($storedPaths);

            throw $exception;
        }
    }

    /**
     * Edita un clasificado propio: datos, contacto e imágenes (quitar algunas y agregar nuevas, entre 1 y 5 en total).
     *
     * @param list<int> $removeImageIds
     */
    public function updateListing(int $listingId, int $userId, array $data, array $files, array $removeImageIds): bool
    {
        $listing = $this->validateListing($data);
        $uploads = $this->validateUploads($files);

        $pdo = Database::connection();
        $storedPaths = [];
        $removedNames = [];
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare(
                'SELECT id, contact_authorized FROM property_classifieds
                 WHERE id = ? AND tenant_id = ? AND user_id = ? FOR UPDATE'
            );
            $lookup->execute([$listingId, Tenant::id(), $userId]);
            $current = $lookup->fetch();
            if (!is_array($current)) {
                $pdo->rollBack();

                return false;
            }

            $imagesQuery = $pdo->prepare('SELECT id, storage_name FROM property_classified_images WHERE listing_id = ?');
            $imagesQuery->execute([$listingId]);
            $images = $imagesQuery->fetchAll();
            $removeIds = array_values(array_intersect(
                array_map(static fn (array $image): int => (int) $image['id'], $images),
                array_map('intval', $removeImageIds)
            ));
            $remaining = count($images) - count($removeIds) + count($uploads);
            if ($remaining < 1) {
                throw new RuntimeException('El clasificado debe conservar al menos una imagen.');
            }
            if ($remaining > self::MAX_IMAGES) {
                throw new RuntimeException('Un clasificado admite máximo cinco imágenes. Quita alguna antes de agregar más.');
            }

            $update = $pdo->prepare(
                'UPDATE property_classifieds
                 SET operation = :operation, title = :title, property_type = :property_type, location = :location,
                     description = :description, price = :price,
                     contact_name = :contact_name, contact_email = :contact_email, contact_phone = :contact_phone,
                     contact_authorized = :contact_authorized,
                     contact_authorized_at = IF(:authorized_now = 0, NULL, COALESCE(contact_authorized_at, UTC_TIMESTAMP()))
                 WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id'
            );
            $update->execute([
                'operation' => $listing['operation'],
                'title' => $listing['title'],
                'property_type' => $listing['property_type'],
                'location' => $listing['location'],
                'description' => $listing['description'],
                'price' => $listing['price'],
                'contact_name' => $listing['contact_name'],
                'contact_email' => $listing['contact_email'],
                'contact_phone' => $listing['contact_phone'],
                'contact_authorized' => $listing['contact_authorized'] ? 1 : 0,
                'authorized_now' => $listing['contact_authorized'] ? 1 : 0,
                'id' => $listingId,
                'tenant_id' => Tenant::id(),
                'user_id' => $userId,
            ]);

            if ($removeIds !== []) {
                foreach ($images as $image) {
                    if (in_array((int) $image['id'], $removeIds, true)) {
                        $removedNames[] = (string) $image['storage_name'];
                    }
                }
                $placeholders = implode(', ', array_fill(0, count($removeIds), '?'));
                $pdo->prepare("DELETE FROM property_classified_images WHERE listing_id = ? AND id IN ({$placeholders})")
                    ->execute([$listingId, ...$removeIds]);
            }

            $this->storeImages($listingId, $uploads, $storedPaths);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->discardFiles($storedPaths);

            throw $exception;
        }

        $this->discardFiles(array_map($this->pathForStorageName(...), $removedNames));

        return true;
    }

    /** Clasificados de su autor, con los datos de contacto guardados aunque no estén autorizados (para editar). */
    public function listingsForOwner(int $userId): array
    {
        $listings = $this->listings('c.user_id = ?', [$userId]);
        if ($listings === []) {
            return [];
        }
        $query = Database::connection()->prepare(
            'SELECT id, contact_name, contact_email, contact_phone, contact_authorized
             FROM property_classifieds WHERE tenant_id = ? AND user_id = ?'
        );
        $query->execute([Tenant::id(), $userId]);
        $contacts = [];
        foreach ($query->fetchAll() as $row) {
            $contacts[(int) $row['id']] = $row;
        }
        foreach ($listings as &$listing) {
            $contact = $contacts[(int) $listing['id']] ?? [];
            $listing['contact_name'] = $contact['contact_name'] ?? null;
            $listing['contact_email'] = $contact['contact_email'] ?? null;
            $listing['contact_phone'] = $contact['contact_phone'] ?? null;
            $listing['contact_authorized'] = (bool) ($contact['contact_authorized'] ?? false);
        }
        unset($listing);

        return $listings;
    }

    public function setStatus(int $listingId, int $userId, string $status): bool
    {
        if (!in_array($status, ['published', 'closed'], true)) {
            throw new RuntimeException('El estado solicitado no es válido.');
        }

        $pdo = Database::connection();
        $lookup = $pdo->prepare(
            'SELECT id FROM property_classifieds WHERE id = ? AND tenant_id = ? AND user_id = ? LIMIT 1'
        );
        $lookup->execute([$listingId, Tenant::id(), $userId]);
        if ($lookup->fetchColumn() === false) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE property_classifieds SET status = ? WHERE id = ? AND tenant_id = ? AND user_id = ?'
        );
        $statement->execute([$status, $listingId, Tenant::id(), $userId]);

        return true;
    }

    public function deleteListing(int $listingId, int $userId): bool
    {
        $pdo = Database::connection();
        $lookup = $pdo->prepare(
            'SELECT images.storage_name
             FROM property_classifieds AS listings
             LEFT JOIN property_classified_images AS images ON images.listing_id = listings.id
             WHERE listings.id = ? AND listings.tenant_id = ? AND listings.user_id = ?'
        );
        $lookup->execute([$listingId, Tenant::id(), $userId]);
        $images = $lookup->fetchAll();
        if ($images === []) {
            return false;
        }

        $delete = $pdo->prepare('DELETE FROM property_classifieds WHERE id = ? AND tenant_id = ? AND user_id = ?');
        $delete->execute([$listingId, Tenant::id(), $userId]);

        if ($delete->rowCount() !== 1) {
            return false;
        }

        $storageNames = array_filter(array_column($images, 'storage_name'), 'is_string');
        $this->discardFiles(array_map($this->pathForStorageName(...), $storageNames));

        return true;
    }

    public function imageForDisplay(int $imageId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT images.storage_name, images.mime_type, images.byte_size,
                    listings.status, listings.user_id
             FROM property_classified_images AS images
             INNER JOIN property_classifieds AS listings ON listings.id = images.listing_id
             WHERE images.id = ? AND listings.tenant_id = ?
             LIMIT 1'
        );
        $statement->execute([$imageId, Tenant::id()]);
        $image = $statement->fetch();
        if (!is_array($image)) {
            return null;
        }

        $image['path'] = $this->pathForStorageName((string) $image['storage_name']);

        return $image;
    }

    private function listings(string $where, array $parameters, int $limit = 0): array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.id, c.user_id, c.operation, c.title, c.property_type, c.location,
                    c.description, c.price, c.status, c.created_at,
                    CASE WHEN c.contact_authorized = 1 THEN c.contact_name ELSE NULL END AS contact_name,
                    CASE WHEN c.contact_authorized = 1 THEN c.contact_email ELSE NULL END AS contact_email,
                    CASE WHEN c.contact_authorized = 1 THEN c.contact_phone ELSE NULL END AS contact_phone
             FROM property_classifieds AS c
             WHERE c.tenant_id = ? AND (' . $where . ')
             ORDER BY c.created_at DESC, c.id DESC'
            . ($limit > 0 ? ' LIMIT ' . $limit : '')
        );
        $statement->execute([Tenant::id(), ...$parameters]);
        $listings = $statement->fetchAll();
        if ($listings === []) {
            return [];
        }

        $listingIds = array_map(static fn (array $listing): int => (int) $listing['id'], $listings);
        $placeholders = implode(', ', array_fill(0, count($listingIds), '?'));
        $imageStatement = Database::connection()->prepare(
            'SELECT id, listing_id, mime_type, byte_size, width, height
             FROM property_classified_images
             WHERE listing_id IN (' . $placeholders . ')
             ORDER BY id'
        );
        $imageStatement->execute($listingIds);
        $imagesByListing = [];
        foreach ($imageStatement->fetchAll() as $image) {
            $imagesByListing[(int) $image['listing_id']][] = $image;
        }

        foreach ($listings as &$listing) {
            $listingId = (int) $listing['id'];
            $listing['images'] = $imagesByListing[$listingId] ?? [];
        }
        unset($listing);

        return $listings;
    }

    private function validateListing(array $data): array
    {
        $operation = is_string($data['operation'] ?? null) ? $data['operation'] : '';
        $title = trim(is_string($data['title'] ?? null) ? $data['title'] : '');
        $propertyType = is_string($data['property_type'] ?? null) ? $data['property_type'] : '';
        $location = trim(is_string($data['location'] ?? null) ? $data['location'] : '');
        $description = trim(is_string($data['description'] ?? null) ? $data['description'] : '');
        $priceInput = trim(is_string($data['price'] ?? null) ? $data['price'] : '');

        if (!in_array($operation, ['sale', 'rent', 'purchase'], true)) {
            throw new RuntimeException('Selecciona si ofreces el inmueble en venta, en arriendo o buscas comprar.');
        }
        if ($this->textLength($title) < 3 || $this->textLength($title) > 160) {
            throw new RuntimeException('El título debe tener entre 3 y 160 caracteres.');
        }
        if (!in_array($propertyType, ['house', 'apartment', 'commercial', 'other'], true)) {
            throw new RuntimeException('Selecciona un tipo de inmueble válido.');
        }
        if ($location === '' || $this->textLength($location) > 120) {
            throw new RuntimeException('Indica el barrio o sector (máximo 120 caracteres).');
        }
        if ($description === '' || $this->textLength($description) > 3000) {
            throw new RuntimeException('La descripción es obligatoria y no debe superar 3.000 caracteres.');
        }
        if ($priceInput !== ''
            && (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $priceInput) || (float) $priceInput <= 0)
        ) {
            throw new RuntimeException('El valor debe ser un monto positivo con hasta dos decimales.');
        }

        $contactAuthorized = ($data['contact_authorized'] ?? null) === '1';
        $contactName = trim(is_string($data['contact_name'] ?? null) ? $data['contact_name'] : '');
        $contactEmail = trim(is_string($data['contact_email'] ?? null) ? $data['contact_email'] : '');
        $contactPhone = trim(is_string($data['contact_phone'] ?? null) ? $data['contact_phone'] : '');

        if ($contactAuthorized) {
            if ($this->textLength($contactName) > 100
                || $this->textLength($contactEmail) > 254
                || $this->textLength($contactPhone) > 30
            ) {
                throw new RuntimeException('Revisa la longitud de los datos de contacto.');
            }
            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo de contacto no es válido.');
            }
            if ($contactPhone !== '' && !preg_match('/^[0-9+().\-\s]{7,30}$/D', $contactPhone)) {
                throw new RuntimeException('El teléfono de contacto no es válido.');
            }
            if ($contactEmail === '' && $contactPhone === '') {
                throw new RuntimeException('Ingresa un correo o teléfono que autorizas publicar.');
            }
        } else {
            $contactName = '';
            $contactEmail = '';
            $contactPhone = '';
        }

        return [
            'operation' => $operation,
            'title' => $title,
            'property_type' => $propertyType,
            'location' => $location,
            'description' => $description,
            'price' => $priceInput === '' ? null : $priceInput,
            'contact_authorized' => $contactAuthorized,
            'contact_name' => $contactName === '' ? null : $contactName,
            'contact_email' => $contactEmail === '' ? null : $contactEmail,
            'contact_phone' => $contactPhone === '' ? null : $contactPhone,
        ];
    }

    /**
     * Mueve las imágenes validadas al almacenamiento privado y las registra en el clasificado.
     * Cada archivo guardado se agrega a $storedPaths para limpiarlo si la transacción falla.
     */
    private function storeImages(int $listingId, array $uploads, array &$storedPaths): void
    {
        $insert = Database::connection()->prepare(
            'INSERT INTO property_classified_images (listing_id, storage_name, mime_type, byte_size, width, height)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($uploads as $upload) {
            $storageName = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
            $destination = Storage::directory('classifieds') . DIRECTORY_SEPARATOR . $storageName;
            if (!move_uploaded_file($upload['temporary_name'], $destination)) {
                throw new RuntimeException('No se pudo guardar una de las imágenes. Intenta nuevamente.');
            }
            $storedPaths[] = $destination;
            $insert->execute([$listingId, $storageName, $upload['mime_type'], $upload['byte_size'], $upload['width'], $upload['height']]);
        }
    }

    /** @param list<string> $paths */
    private function discardFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path) && !unlink($path)) {
                error_log('No se pudo eliminar una imagen de clasificados.');
            }
        }
    }

    private function validateUploads(array $files): array
    {
        if (!isset($files['error']) || !is_array($files['error'])) {
            return [];
        }

        $uploads = [];
        $count = count($files['error']);
        if ($count > self::MAX_IMAGES) {
            throw new RuntimeException('Puedes cargar máximo cinco imágenes por clasificado.');
        }

        $formats = [
            'image/jpeg' => ['extension' => 'jpg', 'type' => IMAGETYPE_JPEG],
            'image/png' => ['extension' => 'png', 'type' => IMAGETYPE_PNG],
            'image/webp' => ['extension' => 'webp', 'type' => IMAGETYPE_WEBP],
        ];
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);

        for ($index = 0; $index < $count; $index++) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Una imagen no se cargó correctamente. Revisa el tamaño máximo de 4 MB.');
            }

            $temporaryName = $files['tmp_name'][$index] ?? null;
            $size = $files['size'][$index] ?? null;
            if (!is_string($temporaryName) || !is_uploaded_file($temporaryName)
                || !is_int($size) || $size < 1 || $size > self::MAX_IMAGE_BYTES
            ) {
                throw new RuntimeException('Cada imagen debe pesar más de 0 y máximo 4 MB.');
            }

            $mimeType = $fileInfo->file($temporaryName);
            $imageInfo = @getimagesize($temporaryName);
            if (!is_string($mimeType) || !isset($formats[$mimeType]) || !is_array($imageInfo)
                || ($imageInfo[2] ?? null) !== $formats[$mimeType]['type']
            ) {
                throw new RuntimeException('Usa únicamente imágenes JPEG, PNG o WebP válidas.');
            }

            $width = (int) ($imageInfo[0] ?? 0);
            $height = (int) ($imageInfo[1] ?? 0);
            if ($width < 1 || $height < 1 || $width > 65535 || $height > 65535
                || $width * $height > self::MAX_IMAGE_PIXELS
            ) {
                throw new RuntimeException('La resolución de una imagen supera el máximo permitido.');
            }

            $uploads[] = [
                'temporary_name' => $temporaryName,
                'mime_type' => $mimeType,
                'extension' => $formats[$mimeType]['extension'],
                'byte_size' => $size,
                'width' => $width,
                'height' => $height,
            ];
        }

        return $uploads;
    }

    private function pathForStorageName(string $storageName): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $storageName)) {
            throw new RuntimeException('El nombre interno de una imagen no es válido.');
        }

        return Storage::directory('classifieds') . DIRECTORY_SEPARATOR . $storageName;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
