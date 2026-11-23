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
        'pagos_wompi' => [
            'label' => 'Pagos en línea (Wompi)',
            'description' => 'Checkout de Wompi (tarjeta, PSE, Nequi, Botón Bancolombia) para que los residentes paguen sus cuotas.',
            'icon' => 'bi-wallet2',
            'permissions' => ['configuracion.pagos'],
            'requires' => ['cartera'],
        ],
        'mensajes' => [
            'label' => 'Mensajes',
            'description' => 'Comunicados de la administración y atención a residentes: consultas, peticiones, quejas, reclamos y sugerencias desde el portal.',
            'icon' => 'bi-chat-left-text',
            'permissions' => ['mensajes'],
            'requires' => [],
        ],
        'zonas_comunes' => [
            'label' => 'Zonas comunes',
            'description' => 'Reserva de salón social, BBQ, piscina y demás espacios según la agenda; la administración aprueba cada solicitud.',
            'icon' => 'bi-calendar2-week',
            'permissions' => ['zonas.comunes'],
            'requires' => ['portal'],
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

    /** Responde 404 y corta la petición si el Superadmin deshabilitó el módulo en este conjunto. */
    public static function requireEnabled(string $module): void
    {
        if (!self::enabled($module)) {
            http_response_code(404);
            echo 'Esta opción no está habilitada en tu conjunto.';
            exit;
        }
    }

    /** @return list<string> Módulos que incluyen el permiso (uno puede compartirse, p. ej. métodos de pago). */
    public static function modulesForPermission(string $permissionKey): array
    {
        $modules = [];
        foreach (self::MODULES as $module => $definition) {
            if (in_array($permissionKey, $definition['permissions'], true)) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * Un permiso del núcleo siempre está disponible; uno de módulo, si al menos uno de sus módulos está habilitado.
     * En la plataforma no hay módulos de conjunto: sus permisos solo aplican dentro de un cliente.
     */
    public static function permissionAvailable(string $permissionKey): bool
    {
        $modules = self::modulesForPermission($permissionKey);
        if ($modules === []) {
            return true;
        }
        foreach ($modules as $module) {
            if (self::enabled($module)) {
                return true;
            }
        }

        return false;
    }
}
