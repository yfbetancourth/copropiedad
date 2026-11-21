<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\Tenant;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Clientes (conjuntos residenciales) de la plataforma. Solo lo usa el Superadmin desde el dominio principal,
 * por eso aquí las consultas reciben el tenant_id de forma explícita en lugar de leer Tenant::id().
 */
final class TenantService
{
    public const ADMIN_ROLE_NAME = 'Administrador';

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = Database::connection()->query(
            'SELECT tenants.id, tenants.slug, tenants.name, tenants.status, tenants.created_at,
                    (SELECT COUNT(*) FROM admin_users AS users WHERE users.tenant_id = tenants.id) AS user_count,
                    (SELECT COUNT(*) FROM admin_users AS users
                       JOIN admin_roles AS roles ON roles.id = users.role_id
                      WHERE users.tenant_id = tenants.id AND roles.is_system = 1) AS admin_count,
                    (SELECT COUNT(*) FROM billing_statements AS statements WHERE statements.tenant_id = tenants.id) AS statement_count
             FROM tenants
             ORDER BY tenants.name'
        )->fetchAll();

        $modules = $this->modulesByTenant();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['modules'] = $modules[$row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    public function find(int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, slug, name, status, created_at FROM tenants WHERE id = ? LIMIT 1'
        );
        $statement->execute([$tenantId]);
        $tenant = $statement->fetch();
        if (!is_array($tenant)) {
            return null;
        }
        $tenant['id'] = (int) $tenant['id'];
        $tenant['modules'] = $this->modulesByTenant($tenantId)[$tenantId] ?? [];
        $tenant['admins'] = $this->admins($tenantId);

        return $tenant;
    }

    /** @return list<array<string, mixed>> Cuentas con el rol de sistema del conjunto. */
    public function admins(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.username, users.email, users.is_active, users.created_at
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.tenant_id = ? AND roles.is_system = 1
             ORDER BY users.username'
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll();
    }

    /**
     * Crea el conjunto, sus módulos, el rol Administrador y la cuenta del administrador asignado, todo o nada.
     *
     * @param list<string> $modules
     */
    public function create(
        string $name,
        string $slug,
        array $modules,
        string $adminUsername,
        string $adminEmail,
        string $adminPassword
    ): int {
        $this->assertSlug($slug);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO tenants (slug, name) VALUES (?, ?)')->execute([$slug, $name]);
            $tenantId = (int) $pdo->lastInsertId();

            $this->writeModules($tenantId, $modules);
            $pdo->prepare(
                'INSERT INTO admin_roles (tenant_id, name, description, is_system) VALUES (?, ?, ?, 1)'
            )->execute([$tenantId, self::ADMIN_ROLE_NAME, 'Administrador principal del conjunto, asignado por el Superadmin']);
            $roleId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO copropiedad_settings (tenant_id, property_name) VALUES (?, ?)')
                ->execute([$tenantId, $name]);
            $this->insertAdmin($tenantId, $roleId, $adminUsername, $adminEmail, $adminPassword);

            $pdo->commit();

            return $tenantId;
        } catch (PDOException $exception) {
            $this->rollBack();
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe un conjunto con ese subdominio. Elige otro.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function update(int $tenantId, string $name, string $slug): void
    {
        $this->assertSlug($slug);
        try {
            $statement = Database::connection()->prepare('UPDATE tenants SET name = ?, slug = ? WHERE id = ?');
            $statement->execute([$name, $slug, $tenantId]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe un conjunto con ese subdominio. Elige otro.');
            }
            throw $exception;
        }
    }

    public function setStatus(int $tenantId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new RuntimeException('Selecciona un estado válido.');
        }
        Database::connection()->prepare('UPDATE tenants SET status = ? WHERE id = ?')->execute([$status, $tenantId]);
    }

    /** @param list<string> $modules Módulos habilitados; los demás quedan deshabilitados. */
    public function setModules(int $tenantId, array $modules): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->writeModules($tenantId, $modules);
            $pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function addAdmin(int $tenantId, string $username, string $email, string $password): void
    {
        try {
            $this->insertAdmin($tenantId, $this->adminRoleId($tenantId), $username, $email, $password);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Ese usuario o correo ya existe en este conjunto.');
            }
            throw $exception;
        }
    }

    public function setAdminActive(int $tenantId, int $userId, bool $active): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             SET users.is_active = ?, users.auth_version = users.auth_version + 1
             WHERE users.id = ? AND users.tenant_id = ? AND roles.is_system = 1'
        );
        $statement->execute([$active ? 1 : 0, $userId, $tenantId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('El administrador seleccionado no existe en este conjunto.');
        }
    }

    public function resetAdminPassword(int $tenantId, int $userId, string $password): void
    {
        self::assertPassword($password);
        $statement = Database::connection()->prepare(
            'UPDATE admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             SET users.password_hash = ?, users.failed_attempts = 0, users.locked_until = NULL,
                 users.auth_version = users.auth_version + 1
             WHERE users.id = ? AND users.tenant_id = ? AND roles.is_system = 1'
        );
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId, $tenantId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('El administrador seleccionado no existe en este conjunto.');
        }
    }

    public static function assertPassword(string $password): void
    {
        if (strlen($password) < 12) {
            throw new RuntimeException('La contraseña debe tener al menos 12 caracteres.');
        }
    }

    /** Convierte un nombre en un subdominio válido: «Torres del Parque» -> «torres-del-parque». */
    public static function suggestSlug(string $name): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-'));

        return substr($slug, 0, 63);
    }

    private function insertAdmin(int $tenantId, int $roleId, string $username, string $email, string $password): void
    {
        if (strlen($username) < 3 || strlen($username) > 64 || preg_match('/^[\p{L}\p{N}_.@-]+$/uD', $username) !== 1) {
            throw new RuntimeException('El usuario del administrador debe tener entre 3 y 64 caracteres, sin espacios.');
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Ingresa un correo válido para el administrador.');
        }
        self::assertPassword($password);

        Database::connection()->prepare(
            'INSERT INTO admin_users (tenant_id, username, email, password_hash, role_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$tenantId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
    }

    private function adminRoleId(int $tenantId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT id FROM admin_roles WHERE tenant_id = ? AND is_system = 1 ORDER BY id LIMIT 1'
        );
        $statement->execute([$tenantId]);
        $roleId = $statement->fetchColumn();
        if ($roleId !== false) {
            return (int) $roleId;
        }

        Database::connection()->prepare(
            'INSERT INTO admin_roles (tenant_id, name, description, is_system) VALUES (?, ?, ?, 1)'
        )->execute([$tenantId, self::ADMIN_ROLE_NAME, 'Administrador principal del conjunto, asignado por el Superadmin']);

        return (int) Database::connection()->lastInsertId();
    }

    /** @param list<string> $modules */
    private function writeModules(int $tenantId, array $modules): void
    {
        $enabled = array_fill_keys(array_filter($modules, [ModuleCatalog::class, 'exists']), true);
        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
        );
        foreach (array_keys(ModuleCatalog::MODULES) as $module) {
            $statement->execute([$tenantId, $module, isset($enabled[$module]) ? 1 : 0]);
        }
    }

    /** @return array<int, array<string, bool>> */
    private function modulesByTenant(?int $tenantId = null): array
    {
        $sql = 'SELECT tenant_id, module_key, enabled FROM tenant_modules';
        $statement = Database::connection()->prepare($tenantId === null ? $sql : $sql . ' WHERE tenant_id = ?');
        $statement->execute($tenantId === null ? [] : [$tenantId]);
        $modules = [];
        foreach ($statement->fetchAll() as $row) {
            $modules[(int) $row['tenant_id']][(string) $row['module_key']] = (bool) $row['enabled'];
        }

        return $modules;
    }

    private function assertSlug(string $slug): void
    {
        if (!Tenant::isValidSlug($slug)) {
            throw new RuntimeException(
                'El subdominio admite letras minúsculas, números y guiones (sin empezar ni terminar en guion), hasta 63 caracteres, y no puede ser una palabra reservada.'
            );
        }
    }

    private function rollBack(): void
    {
        $pdo = Database::connection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
