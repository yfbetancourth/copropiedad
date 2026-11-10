<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use Throwable;

final class RoleService
{
    public const MODULES = [
        'panel' => 'Panel administrativo',
        'configuracion.equipo' => 'Configuracion / Equipo',
        'configuracion.roles' => 'Configuracion / Roles y permisos',
        'configuracion.parametrizacion' => 'Configuracion / Parametrizacion',
        'configuracion.financiera' => 'Configuracion / Financiera',
        'configuracion.correo' => 'Configuracion / Correo',
        'configuracion.caracterizacion' => 'Configuracion / Caracterizacion',
        'configuracion.conformacion' => 'Configuracion / Conformacion de la copropiedad',
        'inmuebles.asignaciones' => 'Inmuebles / Asignacion de usuarios',
        'cartera.cobros' => 'Cartera / Cobros',
        'mensajes' => 'Mensajes internos',
        'usuario.portal' => 'Usuario / Portal de inmuebles asignados',
    ];

    public const LEVELS = [
        'none' => 'Sin acceso',
        'read' => 'Lectura',
        'write' => 'Escritura',
        'total' => 'Total',
    ];

    public function all(): array
    {
        $roles = Database::connection()->query(
            'SELECT roles.id, roles.name, roles.description, roles.is_system,
                    COUNT(users.id) AS user_count
             FROM admin_roles AS roles
             LEFT JOIN admin_users AS users ON users.role_id = roles.id
             GROUP BY roles.id
             ORDER BY roles.is_system DESC, roles.name'
        )->fetchAll();

        foreach ($roles as &$role) {
            $role['permissions'] = $this->permissions((int) $role['id']);
        }
        unset($role);

        return $roles;
    }

    public function find(int $roleId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, description, is_system FROM admin_roles WHERE id = ? LIMIT 1'
        );
        $statement->execute([$roleId]);
        $role = $statement->fetch();
        if (!is_array($role)) {
            return null;
        }

        $role['permissions'] = $this->permissions($roleId);

        return $role;
    }

    public function permissions(int $roleId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT module_key, permission_level FROM admin_role_permissions WHERE role_id = ?'
        );
        $statement->execute([$roleId]);
        $permissions = [];
        foreach ($statement->fetchAll() as $permission) {
            $permissions[$permission['module_key']] = $permission['permission_level'];
        }

        return $permissions;
    }

    public function save(?int $roleId, string $name, string $description, array $permissions): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            if ($roleId === null) {
                $statement = $pdo->prepare('INSERT INTO admin_roles (name, description) VALUES (?, ?)');
                $statement->execute([$name, $description]);
                $roleId = (int) $pdo->lastInsertId();
            } else {
                $existingRole = $this->find($roleId);
                if ($existingRole === null) {
                    throw new \RuntimeException('El rol seleccionado no existe.');
                }
                if ((bool) $existingRole['is_system']) {
                    throw new \RuntimeException('El rol Superadmin del sistema no se puede modificar.');
                }

                $statement = $pdo->prepare(
                    'UPDATE admin_roles SET name = ?, description = ? WHERE id = ? AND is_system = 0'
                );
                $statement->execute([$name, $description, $roleId]);
            }

            $pdo->prepare('DELETE FROM admin_role_permissions WHERE role_id = ?')->execute([$roleId]);
            $insert = $pdo->prepare(
                'INSERT INTO admin_role_permissions (role_id, module_key, permission_level) VALUES (?, ?, ?)'
            );
            foreach (self::MODULES as $moduleKey => $_label) {
                $insert->execute([$roleId, $moduleKey, $permissions[$moduleKey] ?? 'none']);
            }
            $pdo->commit();

            return $roleId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function delete(int $roleId): bool
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM admin_roles WHERE id = ? AND is_system = 0'
        );
        $statement->execute([$roleId]);

        return $statement->rowCount() === 1;
    }

    public function assignableRoles(): array
    {
        return Database::connection()->query(
            'SELECT id, name FROM admin_roles WHERE is_system = 0 ORDER BY name'
        )->fetchAll();
    }
}
