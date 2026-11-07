<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\AuthService;

final class Authorization
{
    private const PERMISSION_LEVELS = ['none' => 0, 'read' => 1, 'write' => 2, 'total' => 3];

    public static function currentUser(): ?array
    {
        Session::start();
        $userId = $_SESSION['admin_user_id'] ?? null;
        $authVersion = $_SESSION['admin_auth_version'] ?? null;
        $lastActivity = $_SESSION['last_activity'] ?? null;

        if (!is_int($userId) || !is_int($authVersion) || !is_int($lastActivity)
            || time() - $lastActivity > 1800
        ) {
            if (isset($_SESSION['admin_user_id'])) {
                Session::destroy();
            }

            return null;
        }

        $user = (new AuthService())->currentUser($userId, $authVersion);
        if ($user === null) {
            Session::destroy();
            return null;
        }

        $_SESSION['last_activity'] = time();

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
        if ((bool) $user['is_system']) {
            return true;
        }

        $level = self::PERMISSION_LEVELS[$user['permissions'][$moduleKey] ?? 'none'] ?? 0;

        return $level >= $requiredLevel;
    }

    public static function canGrant(string $requested, string $actorLevel): bool
    {
        return (self::PERMISSION_LEVELS[$requested] ?? PHP_INT_MAX)
            <= (self::PERMISSION_LEVELS[$actorLevel] ?? 0);
    }
}
