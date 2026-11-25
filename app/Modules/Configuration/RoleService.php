<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\Tenant;
use Throwable;

/**
 * Roles del equipo de la administración (acceso al panel). El rol «Residente» es fijo: da acceso solo al portal
 * y no se edita aquí.
 */
final class RoleService
{
    public const MODULES = [
        'panel' => 'Bienvenida (acceso al panel administrativo)',
        'configuracion.equipo' => 'Configuracion / Equipo',
        'configuracion.roles' => 'Configuracion / Roles y permisos',
        'configuracion.parametrizacion' => 'Configuracion / Parametrizacion',
        'configuracion.financiera' => 'Configuracion / Financiera',
        'configuracion.correo' => 'Configuracion / Correo',
        'configuracion.pagos' => 'Configuracion / Metodos de pago',
        'configuracion.caracterizacion' => 'Configuracion / Caracterizacion',
        'configuracion.conformacion' => 'Configuracion / Conformacion de la copropiedad',
        'inmuebles.asignaciones' => 'Inmuebles / Asignacion de usuarios',
        'cartera.cobros' => 'Cartera / Cobros',
        'mensajes' => 'Mensajes internos',
        'comunidad.residentes' => 'Comunidad / Residentes',
        'zonas.comunes' => 'Zonas comunes / Espacios y reservas',
        'clasificados' => 'Clasificados / Revisión y publicación',
        'contacto' => 'Comunicación / Contacto del sitio web',
        'privacidad' => 'Privacidad y datos personales / Políticas y autorizaciones',
    ];

    public const LEVELS = [
        'none' => 'Sin acceso',
        'read' => 'Lectura',
        'write' => 'Escritura',
        'total' => 'Total',
    ];

    public function all(): array
    {
        $statement = Database::connection()->prepare(
            "SELECT roles.id, roles.name, roles.description, roles.is_system,
                    COUNT(users.id) AS user_count
             FROM admin_roles AS roles
             LEFT JOIN admin_users AS users ON users.role_id = roles.id
             WHERE roles.tenant_id = ? AND roles.kind = 'staff'
             GROUP BY roles.id
             ORDER BY roles.is_system DESC, roles.name"
        );
        $statement->execute([Tenant::id()]);
        $roles = $statement->fetchAll();

        foreach ($roles as &$role) {
            $role['permissions'] = $this->permissions((int) $role['id']);
        }
        unset($role);

        return $roles;
    }

    public function find(int $roleId): ?array
    {
        $statement = Database::connection()->prepare(
            "SELECT id, name, description, is_system FROM admin_roles WHERE id = ? AND tenant_id = ? AND kind = 'staff' LIMIT 1"
        );
        $statement->execute([$roleId, Tenant::id()]);
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
                $statement = $pdo->prepare('INSERT INTO admin_roles (tenant_id, name, description) VALUES (?, ?, ?)');
                $statement->execute([Tenant::id(), $name, $description]);
                $roleId = (int) $pdo->lastInsertId();
            } else {
                $existingRole = $this->find($roleId);
                if ($existingRole === null) {
                    throw new \RuntimeException('El rol seleccionado no existe.');
                }
                if ((bool) $existingRole['is_system']) {
                    throw new \RuntimeException('Los roles del sistema no se pueden modificar.');
                }

                $statement = $pdo->prepare(
                    'UPDATE admin_roles SET name = ?, description = ? WHERE id = ? AND tenant_id = ? AND is_system = 0'
                );
                $statement->execute([$name, $description, $roleId, Tenant::id()]);
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
            "DELETE FROM admin_roles WHERE id = ? AND tenant_id = ? AND is_system = 0 AND kind = 'staff'"
        );
        $statement->execute([$roleId, Tenant::id()]);

        return $statement->rowCount() === 1;
    }

    public function assignableRoles(): array
    {
        $statement = Database::connection()->prepare(
            "SELECT id, name FROM admin_roles WHERE tenant_id = ? AND is_system = 0 AND kind = 'staff' ORDER BY name"
        );
        $statement->execute([Tenant::id()]);

        return $statement->fetchAll();
    }
}
