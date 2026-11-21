<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\Tenant;
use RuntimeException;

final class TeamService
{
    public function all(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.username, users.email, users.is_active, users.created_at,
                    roles.id AS role_id, roles.name AS role_name, roles.is_system
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.tenant_id = ?
             ORDER BY roles.is_system DESC, users.username'
        );
        $statement->execute([Tenant::id()]);

        return $statement->fetchAll();
    }

    public function find(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.username, users.email, users.is_active,
                    roles.id AS role_id, roles.name AS role_name, roles.is_system
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.tenant_id = ? LIMIT 1'
        );
        $statement->execute([$userId, Tenant::id()]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function create(string $username, string $email, string $password, int $roleId): void
    {
        $this->assertAssignableRole($roleId);
        $statement = Database::connection()->prepare(
            'INSERT INTO admin_users (tenant_id, username, email, password_hash, role_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([Tenant::id(), $username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
    }

    public function update(int $userId, string $username, string $email, int $roleId, ?string $password): void
    {
        $user = $this->find($userId);
        if ($user === null) {
            throw new RuntimeException('El usuario seleccionado no existe.');
        }

        if ((bool) $user['is_system']) {
            throw new RuntimeException('La cuenta del administrador principal solo la gestiona el Superadmin desde la consola de clientes.');
        }

        $this->assertAssignableRole($roleId);
        if ($password === null) {
            $statement = Database::connection()->prepare(
                'UPDATE admin_users
                 SET username = ?, email = ?, role_id = ?, auth_version = auth_version + 1
                 WHERE id = ? AND tenant_id = ?'
            );
            $statement->execute([$username, $email, $roleId, $userId, Tenant::id()]);
            return;
        }

        $statement = Database::connection()->prepare(
            'UPDATE admin_users
             SET username = ?, email = ?, role_id = ?, password_hash = ?,
                 failed_attempts = 0, locked_until = NULL, auth_version = auth_version + 1
             WHERE id = ? AND tenant_id = ?'
        );
        $statement->execute([
            $username,
            $email,
            $roleId,
            password_hash($password, PASSWORD_DEFAULT),
            $userId,
            Tenant::id(),
        ]);
    }

    public function setActive(int $userId, bool $isActive, int $actorId): void
    {
        if ($userId === $actorId) {
            throw new RuntimeException('No puedes desactivar tu propia cuenta.');
        }

        $user = $this->find($userId);
        if ($user === null) {
            throw new RuntimeException('El usuario seleccionado no existe.');
        }
        if ((bool) $user['is_system']) {
            throw new RuntimeException('La cuenta del administrador principal solo la gestiona el Superadmin desde la consola de clientes.');
        }

        $statement = Database::connection()->prepare(
            'UPDATE admin_users SET is_active = ?, auth_version = auth_version + 1 WHERE id = ? AND tenant_id = ?'
        );
        $statement->execute([$isActive ? 1 : 0, $userId, Tenant::id()]);
    }

    private function assertAssignableRole(int $roleId): void
    {
        $statement = Database::connection()->prepare(
            'SELECT id FROM admin_roles WHERE id = ? AND tenant_id = ? AND is_system = 0 LIMIT 1'
        );
        $statement->execute([$roleId, Tenant::id()]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Selecciona un rol personalizado valido.');
        }
    }
}
