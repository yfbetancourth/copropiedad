<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Database;
use App\Core\Tenant;
use PDOException;
use RuntimeException;

/** Catálogo de tipos de residente del conjunto (Propietario, Inquilino…), editable por la administración. */
final class ResidentTypeService
{
    /**
     * Tipos con los que empieza cada conjunto (también los usan las migraciones 031 y 032):
     * nombre => [descripción, exige propietario en el inmueble].
     */
    public const DEFAULTS = [
        'Propietario' => ['Dueño de uno o más inmuebles', false],
        'Inquilino' => ['Arrienda un inmueble del conjunto', true],
        'Familiar o acompañante' => ['Vive en el inmueble con el propietario o el inquilino', false],
    ];

    public static function seedDefaults(int $tenantId): void
    {
        $insert = Database::connection()->prepare(
            'INSERT IGNORE INTO resident_types (tenant_id, name, description, requires_unit_owner) VALUES (?, ?, ?, ?)'
        );
        foreach (self::DEFAULTS as $name => [$description, $requiresOwner]) {
            $insert->execute([$tenantId, $name, $description, $requiresOwner ? 1 : 0]);
        }
    }

    /**
     * Tipos del conjunto con cuántos residentes tiene cada uno.
     *
     * @return list<array{id: int, name: string, description: string, requires_unit_owner: bool, is_active: bool, residents: int}>
     */
    public function all(bool $onlyActive = false): array
    {
        $query = Database::connection()->prepare(
            'SELECT types.id, types.name, types.description, types.requires_unit_owner, types.is_active,
                    COUNT(profiles.user_id) AS residents
             FROM resident_types AS types
             LEFT JOIN resident_profiles AS profiles ON profiles.resident_type_id = types.id
             WHERE types.tenant_id = ?' . ($onlyActive ? ' AND types.is_active = 1' : '') . '
             GROUP BY types.id, types.name, types.description, types.requires_unit_owner, types.is_active
             ORDER BY types.name'
        );
        $query->execute([Tenant::id()]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'requires_unit_owner' => (bool) $row['requires_unit_owner'],
            'is_active' => (bool) $row['is_active'],
            'residents' => (int) $row['residents'],
        ], $query->fetchAll());
    }

    /** Busca un tipo del conjunto por nombre, sin distinguir mayúsculas ni tildes (lo usa la importación). */
    public function findByName(string $name): ?array
    {
        $query = Database::connection()->prepare('SELECT id, name, is_active, requires_unit_owner FROM resident_types WHERE tenant_id = ? AND name = ?');
        $query->execute([Tenant::id(), trim($name)]);
        $row = $query->fetch();

        return is_array($row) ? $row : null;
    }

    /** Verifica que el tipo exista en el conjunto y esté activo; devuelve su id. */
    public function requireActive(int $typeId): int
    {
        $query = Database::connection()->prepare('SELECT is_active FROM resident_types WHERE id = ? AND tenant_id = ?');
        $query->execute([$typeId, Tenant::id()]);
        $active = $query->fetchColumn();
        if ($active === false) {
            throw new RuntimeException('El tipo de residente no existe.');
        }
        if (!(bool) $active) {
            throw new RuntimeException('El tipo de residente está inactivo: actívalo en Tipos de residente o elige otro.');
        }

        return $typeId;
    }

    /** true si el tipo exige vincular al residente a un inmueble que ya tiene propietario (p. ej. Inquilino). */
    public function requiresUnitOwner(?int $typeId): bool
    {
        if ($typeId === null) {
            return false;
        }
        $query = Database::connection()->prepare('SELECT requires_unit_owner FROM resident_types WHERE id = ? AND tenant_id = ?');
        $query->execute([$typeId, Tenant::id()]);

        return (bool) $query->fetchColumn();
    }

    /** Crea ($typeId = 0) o actualiza un tipo. */
    public function save(int $typeId, string $name, string $description, bool $requiresUnitOwner = false): int
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $description = trim($description);
        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 80) {
            throw new RuntimeException('El nombre del tipo debe tener entre 3 y 80 caracteres.');
        }
        if (mb_strlen($description, 'UTF-8') > 255) {
            throw new RuntimeException('La descripción admite hasta 255 caracteres.');
        }
        $pdo = Database::connection();
        try {
            if ($typeId === 0) {
                $pdo->prepare('INSERT INTO resident_types (tenant_id, name, description, requires_unit_owner) VALUES (?, ?, ?, ?)')
                    ->execute([Tenant::id(), $name, $description, $requiresUnitOwner ? 1 : 0]);

                return (int) $pdo->lastInsertId();
            }
            $update = $pdo->prepare('UPDATE resident_types SET name = ?, description = ?, requires_unit_owner = ? WHERE id = ? AND tenant_id = ?');
            $update->execute([$name, $description, $requiresUnitOwner ? 1 : 0, $typeId, Tenant::id()]);
            if ($update->rowCount() === 0 && !$this->exists($typeId)) {
                throw new RuntimeException('El tipo de residente no existe.');
            }

            return $typeId;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe un tipo de residente con ese nombre.');
            }
            throw $exception;
        }
    }

    public function setActive(int $typeId, bool $active): void
    {
        $update = Database::connection()->prepare('UPDATE resident_types SET is_active = ? WHERE id = ? AND tenant_id = ?');
        $update->execute([$active ? 1 : 0, $typeId, Tenant::id()]);
        if ($update->rowCount() === 0 && !$this->exists($typeId)) {
            throw new RuntimeException('El tipo de residente no existe.');
        }
    }

    /** Solo se elimina un tipo sin residentes; si tiene, se desactiva para conservar la clasificación. */
    public function delete(int $typeId): void
    {
        if (!$this->exists($typeId)) {
            throw new RuntimeException('El tipo de residente no existe.');
        }
        try {
            Database::connection()->prepare('DELETE FROM resident_types WHERE id = ? AND tenant_id = ?')->execute([$typeId, Tenant::id()]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Hay residentes con este tipo: cámbiales el tipo o desactívalo en lugar de eliminarlo.');
            }
            throw $exception;
        }
    }

    private function exists(int $typeId): bool
    {
        $query = Database::connection()->prepare('SELECT 1 FROM resident_types WHERE id = ? AND tenant_id = ?');
        $query->execute([$typeId, Tenant::id()]);

        return $query->fetchColumn() !== false;
    }
}
