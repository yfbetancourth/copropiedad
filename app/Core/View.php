<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): void
    {
        self::renderWithLayout($template, $data, 'layout.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function renderPortal(string $template, array $data = []): void
    {
        self::renderWithLayout($template, $data, 'portal-layout.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderWithLayout(string $template, array $data, string $layout): void
    {
        if (!preg_match('/^[A-Za-z0-9]+(?:[\/-][A-Za-z0-9]+)*$/D', $template)) {
            throw new RuntimeException('La plantilla solicitada no es valida.');
        }

        $templatePath = dirname(__DIR__) . '/Modules/' . $template . '.php';

        if (!is_file($templatePath)) {
            throw new RuntimeException('No se encontro la plantilla solicitada.');
        }

        extract($data, EXTR_SKIP);
        $basePath = Url::basePath();
        $propertyName = (new \App\Modules\Configuration\SettingsService())->propertyName();

        ob_start();
        require $templatePath;
        $content = ob_get_clean();
        $pageTitle = isset($title) && is_string($title) ? $title : $propertyName;

        require dirname(__DIR__) . '/Views/' . $layout;
    }
}
