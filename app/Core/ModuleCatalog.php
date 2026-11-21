<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Módulos que el Superadmin habilita o deshabilita por conjunto residencial.
 * Un permiso de rol que pertenece a un módulo deshabilitado no concede acceso a nadie en ese conjunto,
 * ni siquiera al administrador. Los permisos que no aparecen aquí (configuración básica, equipo, roles,
 * caracterización, conformación, asignaciones) forman el núcleo y siempre están disponibles.
 */
final class ModuleCatalog
{
    public const MODULES = [
        'portal' => [
            'label' => 'Portal de residentes',
            'description' => 'Acceso de propietarios y residentes a sus inmuebles, documentos y perfil.',
            'icon' => 'bi-house-door',
            'permissions' => ['usuario.portal'],
            'requires' => [],
        ],
        'cartera' => [
            'label' => 'Cartera y cobros',
            'description' => 'Notas de cobro, pagos manuales, reportes de cartera y configuración financiera.',
            'icon' => 'bi-receipt',
            'permissions' => ['cartera.cobros', 'configuracion.financiera'],
            'requires' => [],
        ],
        'pagos_en_linea' => [
            'label' => 'Pagos en línea (Bold)',
            'description' => 'Botón de pago y datáfono Bold para que los residentes paguen sus cuotas.',
            'icon' => 'bi-credit-card',
            'permissions' => ['configuracion.pagos'],
            'requires' => ['cartera'],
        ],
        'mensajes' => [
            'label' => 'Mensajes',
            'description' => 'Mensajes internos de la administración a los residentes.',
            'icon' => 'bi-chat-left-text',
            'permissions' => ['mensajes'],
            'requires' => [],
        ],
        'clasificados' => [
            'label' => 'Clasificados',
            'description' => 'Avisos públicos de compra y venta de inmuebles publicados por los residentes.',
            'icon' => 'bi-megaphone',
            'permissions' => [],
            'requires' => [],
        ],
    ];

    public static function exists(string $module): bool
    {
        return isset(self::MODULES[$module]);
    }

    /** Módulo habilitado en el conjunto actual, junto con los módulos de los que depende. */
    public static function enabled(string $module): bool
    {
        if (!isset(self::MODULES[$module]) || !Tenant::moduleEnabled($module)) {
            return false;
        }
        foreach (self::MODULES[$module]['requires'] as $required) {
            if (!self::enabled($required)) {
                return false;
            }
        }

        return true;
    }

    public static function moduleForPermission(string $permissionKey): ?string
    {
        foreach (self::MODULES as $module => $definition) {
            if (in_array($permissionKey, $definition['permissions'], true)) {
                return $module;
            }
        }

        return null;
    }

    /** En la plataforma no hay módulos de conjunto: sus permisos solo aplican dentro de un cliente. */
    public static function permissionAvailable(string $permissionKey): bool
    {
        $module = self::moduleForPermission($permissionKey);

        return $module === null || self::enabled($module);
    }
}
