<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\AuthService;

final class Authorization
{
    private const PERMISSION_LEVELS = ['none' => 0, 'read' => 1, 'write' => 2, 'total' => 3];
    private const PORTAL_PERMISSION = 'usuario.portal';
    private static bool $userResolved = false;
    private static ?array $requestUser = null;
    private static ?int $requestUserId = null;
    private static ?int $requestAuthVersion = null;

    public static function currentUser(): ?array
    {
        Session::start();
        $userId = $_SESSION['admin_user_id'] ?? null;
        $authVersion = $_SESSION['admin_auth_version'] ?? null;
        $lastActivity = $_SESSION['last_activity'] ?? null;

        // La sesión queda ligada al conjunto (o a la plataforma) donde se inició: nunca se reutiliza en otro.
        $sessionScope = $_SESSION['tenant_scope'] ?? null;
        if (!is_int($userId) || !is_int($authVersion) || !is_int($lastActivity)
            || time() - $lastActivity > 1800
            || $sessionScope !== (Tenant::scopeId() ?? 0)
        ) {
            if (isset($_SESSION['admin_user_id'])) {
                Session::destroy();
            }

            return null;
        }

        if (self::$userResolved
            && self::$requestUserId === $userId
            && self::$requestAuthVersion === $authVersion
        ) {
            $_SESSION['last_activity'] = time();

            return self::$requestUser;
        }

        $user = (new AuthService())->currentUser($userId, $authVersion);
        if ($user === null) {
            Session::destroy();
            self::$userResolved = true;
            self::$requestUser = null;
            self::$requestUserId = $userId;
            self::$requestAuthVersion = $authVersion;

            return null;
        }

        $_SESSION['last_activity'] = time();
        self::$userResolved = true;
        self::$requestUser = $user;
        self::$requestUserId = $userId;
        self::$requestAuthVersion = $authVersion;

        return $user;
    }

    /** El portal es exclusivo de los residentes (rol de tipo «resident») y requiere el módulo Portal habilitado. */
    public static function isResident(?array $user): bool
    {
        return $user !== null
            && ($user['role_kind'] ?? 'staff') === 'resident'
            && ModuleCatalog::permissionAvailable(self::PORTAL_PERMISSION);
    }

    /** Residente autenticado, o corta la petición: sin sesión lleva al acceso del portal; el personal recibe 403. */
    public static function requireResident(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            header('Location: ' . Url::to('/login?destino=usuario'), true, 303);
            exit;
        }
        if (!self::isResident($user)) {
            http_response_code(403);
            echo 'El portal es exclusivo para residentes. El equipo de la administración trabaja desde el panel administrativo.';
            exit;
        }

        return $user;
    }

    public static function requirePermission(string $moduleKey, string $minimumLevel): ?array
    {
        $user = self::currentUser();
        if ($user === null) {
            header('Location: ' . Url::to('/login'), true, 303);
            exit;
        }

        $required = self::PERMISSION_LEVELS[$minimumLevel] ?? null;
        if ($required === null || !self::hasPermission($user, $moduleKey, $required)) {
            http_response_code(403);
            echo 'No tienes permisos para realizar esta accion.';
            return null;
        }

        return $user;
    }

    public static function hasPermission(array $user, string $moduleKey, int $requiredLevel): bool
    {
        // Un módulo deshabilitado por el Superadmin no concede acceso a nadie dentro del conjunto.
        if (!ModuleCatalog::permissionAvailable($moduleKey)) {
            return false;
        }

        if ((bool) $user['is_system']) {
            return true;
        }
        // Una cuenta de residente solo usa el portal: nunca obtiene permisos del panel, aunque se editen en su rol.
        if (($user['role_kind'] ?? 'staff') === 'resident') {
            return $moduleKey === self::PORTAL_PERMISSION;
        }

        $level = self::PERMISSION_LEVELS[$user['permissions'][$moduleKey] ?? 'none'] ?? 0;

        return $level >= $requiredLevel;
    }

    /**
     * El Superadmin es la cuenta de la plataforma (sin conjunto) con rol de sistema. Puede entrar a cualquier
     * conjunto. Se usa para acciones que ni el administrador del conjunto puede hacer, aunque tenga acceso total.
     */
    public static function isSuperadmin(?array $user): bool
    {
        return $user !== null
            && (bool) ($user['is_system'] ?? false)
            && array_key_exists('tenant_id', $user)
            && $user['tenant_id'] === null;
    }

    public static function canGrant(string $requested, string $actorLevel): bool
    {
        return (self::PERMISSION_LEVELS[$requested] ?? PHP_INT_MAX)
            <= (self::PERMISSION_LEVELS[$actorLevel] ?? 0);
    }
}
