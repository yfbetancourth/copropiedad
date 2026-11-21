<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\Tenant;
use PDO;
use RuntimeException;
use Throwable;

final class AuthService
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    public function authenticate(string $identity, string $password): ?array
    {
        $pdo = Database::connection();
        [$scopeSql, $scopeParams] = self::loginScope('admin_users');
        // En un conjunto se prefiere la cuenta del conjunto; el Superadmin de la plataforma también puede entrar.
        $statement = $pdo->prepare(
            'SELECT id, tenant_id, username, email, password_hash, is_active, failed_attempts, locked_until, auth_version
             FROM admin_users
             WHERE (username = ? OR email = ?) AND ' . $scopeSql . '
             ORDER BY tenant_id IS NULL
             LIMIT 1'
        );
        $statement->execute([$identity, $identity, ...$scopeParams]);
        $user = $statement->fetch();
        $fallbackHash = password_hash('invalid-login-placeholder', PASSWORD_DEFAULT);
        $passwordMatches = password_verify($password, $user['password_hash'] ?? $fallbackHash);

        if (!is_array($user)) {
            return null;
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            return null;
        }

        if ($user['locked_until'] !== null) {
            $pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([$user['id']]);
            $user['failed_attempts'] = 0;
        }

        // Un conjunto suspendido solo admite al Superadmin.
        $blockedBySuspension = $user['tenant_id'] !== null && !Tenant::isActive();

        if (!$passwordMatches || !(bool) $user['is_active'] || $blockedBySuspension) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $lockedUntil = $attempts >= self::MAX_LOGIN_ATTEMPTS;
            $update = $pdo->prepare(
                'UPDATE admin_users
                 SET failed_attempts = ?, locked_until = IF(?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), NULL)
                 WHERE id = ?'
            );
            $update->execute([$attempts, $lockedUntil, $user['id']]);

            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        $pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$user['id']]);

        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'auth_version' => (int) $user['auth_version'],
        ];
    }

    /** Solo cuentas del conjunto actual (o de la plataforma en el dominio principal). */
    public function findActiveUserByEmail(string $email): ?array
    {
        [$scopeSql, $scopeParams] = self::ownScope('admin_users');
        $statement = Database::connection()->prepare(
            'SELECT id, email, username FROM admin_users WHERE email = ? AND is_active = 1 AND ' . $scopeSql . ' LIMIT 1'
        );
        $statement->execute([$email, ...$scopeParams]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function canRequestReset(int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT created_at
             FROM admin_password_reset_tokens
             WHERE user_id = ? AND used_at IS NULL
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $statement->execute([$userId]);
        $createdAt = $statement->fetchColumn();

        return !is_string($createdAt) || strtotime($createdAt . ' UTC') <= time() - 60;
    }

    public function createResetToken(int $userId, string $tokenHash): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'UPDATE admin_password_reset_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND used_at IS NULL'
            )->execute([$userId]);
            $pdo->prepare(
                'INSERT INTO admin_password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
            )->execute([$userId, $tokenHash]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function invalidateResetToken(string $tokenHash): void
    {
        Database::connection()->prepare(
            'UPDATE admin_password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE token_hash = ? AND used_at IS NULL'
        )->execute([$tokenHash]);
    }

    public function resetPassword(string $tokenHash, string $password): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            // El enlace solo sirve en el conjunto de la cuenta: un token de otro conjunto no es válido aquí.
            [$scopeSql, $scopeParams] = self::ownScope('users');
            $statement = $pdo->prepare(
                'SELECT tokens.id, tokens.user_id
                 FROM admin_password_reset_tokens AS tokens
                 JOIN admin_users AS users ON users.id = tokens.user_id
                 WHERE tokens.token_hash = ? AND tokens.used_at IS NULL AND tokens.expires_at > UTC_TIMESTAMP()
                   AND ' . $scopeSql . '
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute([$tokenHash, ...$scopeParams]);
            $token = $statement->fetch();

            if (!is_array($token)) {
                $pdo->commit();
                return false;
            }

            $pdo->prepare(
                'UPDATE admin_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL,
                    auth_version = auth_version + 1 WHERE id = ?'
            )->execute([password_hash($password, PASSWORD_DEFAULT), $token['user_id']]);
            $pdo->prepare(
                'UPDATE admin_password_reset_tokens SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND used_at IS NULL'
            )->execute([$token['user_id']]);
            $pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function isCurrentSession(int $userId, int $authVersion): bool
    {
        return $this->currentUser($userId, $authVersion) !== null;
    }

    public function updateOwnCredentials(
        int $userId,
        string $currentPassword,
        string $username,
        string $email,
        ?string $newPassword
    ): int {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'SELECT password_hash, auth_version
                 FROM admin_users
                 WHERE id = ? AND is_active = 1
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute([$userId]);
            $account = $statement->fetch();
            if (!is_array($account)) {
                throw new RuntimeException('No se pudo encontrar la cuenta activa.');
            }
            if (!password_verify($currentPassword, (string) $account['password_hash'])) {
                throw new RuntimeException('La contraseña actual no es correcta.');
            }

            if ($newPassword === null) {
                $pdo->prepare(
                    'UPDATE admin_users SET username = ?, email = ? WHERE id = ?'
                )->execute([$username, $email, $userId]);
            } else {
                $pdo->prepare(
                    'UPDATE admin_users
                     SET username = ?, email = ?, password_hash = ?, failed_attempts = 0,
                         locked_until = NULL, auth_version = auth_version + 1
                     WHERE id = ?'
                )->execute([$username, $email, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            }

            $authVersion = (int) $account['auth_version'] + ($newPassword === null ? 0 : 1);
            $pdo->commit();

            return $authVersion;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function currentUser(int $userId, int $authVersion): ?array
    {
        [$scopeSql, $scopeParams] = self::loginScope('users');
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.tenant_id, users.username, users.email, roles.id AS role_id, roles.name AS role_name,
                    roles.is_system, users.auth_version
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.auth_version = ? AND users.is_active = 1 AND ' . $scopeSql . '
             LIMIT 1'
        );
        $statement->execute([$userId, $authVersion, ...$scopeParams]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            return null;
        }
        if ($user['tenant_id'] !== null && !Tenant::isActive()) {
            return null; // Conjunto suspendido: solo el Superadmin conserva el acceso.
        }

        $permissions = Database::connection()->prepare(
            'SELECT module_key, permission_level FROM admin_role_permissions WHERE role_id = ?'
        );
        $permissions->execute([$user['role_id']]);
        $user['permissions'] = [];
        foreach ($permissions->fetchAll() as $permission) {
            $user['permissions'][$permission['module_key']] = $permission['permission_level'];
        }

        $user['id'] = (int) $user['id'];
        $user['tenant_id'] = $user['tenant_id'] === null ? null : (int) $user['tenant_id'];
        $user['role_id'] = (int) $user['role_id'];
        $user['is_system'] = (bool) $user['is_system'];

        return $user;
    }

    /**
     * Cuentas que pueden iniciar sesión aquí: en un conjunto, las suyas y el Superadmin; en la plataforma, solo esta.
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function loginScope(string $alias): array
    {
        $tenantId = Tenant::scopeId();

        return $tenantId === null
            ? [$alias . '.tenant_id IS NULL', []]
            : ['(' . $alias . '.tenant_id = ? OR ' . $alias . '.tenant_id IS NULL)', [$tenantId]];
    }

    /**
     * Cuentas que pertenecen exactamente al ámbito actual (recuperación de contraseña).
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function ownScope(string $alias): array
    {
        $tenantId = Tenant::scopeId();

        return $tenantId === null
            ? [$alias . '.tenant_id IS NULL', []]
            : [$alias . '.tenant_id = ?', [$tenantId]];
    }
}
