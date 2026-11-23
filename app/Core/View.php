<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /**
     * Pantallas de acceso (login y recuperación): plantillas en app/Modules, con layout propio a pantalla completa.
     *
     * @param array<string, mixed> $data
     */
    public static function renderAuth(string $template, array $data = []): void
    {
        self::renderWithLayout($template, $data, null, 'Shared/auth-layout.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function renderAdmin(string $template, array $data = []): void
    {
        self::renderWithLayout($template, $data, 'Admin', 'Admin/layout.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function renderPortal(string $template, array $data = []): void
    {
        self::renderWithLayout($template, $data, 'User', 'User/layout.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function renderPublic(string $template, array $data = []): void
    {
        self::renderWithLayout($template, $data, 'Public', 'Public/layout.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderWithLayout(string $template, array $data, ?string $scenario, string $layout): void
    {
        if (!preg_match('/^[A-Za-z0-9]+(?:[\/-][A-Za-z0-9]+)*$/D', $template)) {
            throw new RuntimeException('La plantilla solicitada no es valida.');
        }

        $templatePath = dirname(__DIR__)
            . ($scenario === null ? '/Modules/' : '/Scenarios/' . $scenario . '/Views/')
            . $template . '.php';

        if (!is_file($templatePath)) {
            throw new RuntimeException('No se encontro la plantilla solicitada.');
        }

        extract($data, EXTR_SKIP);
        $viewName = $template;
        $basePath = Url::basePath();
        if ($scenario === 'Public' || $scenario === 'User') {
            $basePath = preg_replace('~/admin$~', '', $basePath) ?? $basePath;
        }
        // En la plataforma (consola del Superadmin) no hay conjunto: se muestra el nombre de la plataforma.
        $propertyName = Tenant::isPlatform()
            ? self::platformName()
            : (new \App\Modules\Configuration\SettingsService())->propertyName();

        ob_start();
        require $templatePath;
        $content = ob_get_clean();
        $pageTitle = isset($title) && is_string($title) ? $title : $propertyName;

        require dirname(__DIR__) . '/Scenarios/' . $layout;
    }

    public static function platformName(): string
    {
        return Env::get('APP_NAME') ?: 'Copropiedad';
    }
}
