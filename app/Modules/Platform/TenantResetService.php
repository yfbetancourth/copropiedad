<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database;
use App\Core\Storage;
use App\Modules\Community\ResidentTypeService;
use App\Modules\Configuration\PropertyGalleryService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Reinicio de un conjunto residencial (solo Superadmin), completo o por categorías.
 *
 * Cada categoría declara las que necesita borrar antes (`requires`) para no dejar registros huérfanos:
 * por ejemplo, no se pueden borrar los inmuebles mientras haya notas de cobro que los referencian.
 * El registro del conjunto, su subdominio, su estado y sus módulos siempre se conservan.
 */
final class TenantResetService
{
    /**
     * Categorías en el orden en que se muestran.
     * counts: [tabla o clave especial => texto], para mostrar cuántos registros se borran.
     */
    public const CATEGORIES = [
        'billing' => [
            'label' => 'Cartera y pagos',
            'detail' => 'Notas de cobro, conceptos mensuales, pagos, pagos reportados con sus soportes, pagos en línea y enlaces de pago.',
            'requires' => [],
            'counts' => ['billing_statements' => 'notas', 'billing_payments' => 'pagos', 'billing_payment_reports' => 'reportados'],
        ],
        'notices' => [
            'label' => 'Avisos de la campana',
            'detail' => 'Avisos de cobro, pagos, reservas y respuestas que ven los usuarios.',
            'requires' => [],
            'counts' => ['user_notices' => 'avisos'],
        ],
        'messages' => [
            'label' => 'Comunicados',
            'detail' => 'Comunicados enviados a residentes y su estado de lectura.',
            'requires' => [],
            'counts' => ['admin_messages' => 'comunicados'],
        ],
        'conversations' => [
            'label' => 'Atención a residentes',
            'detail' => 'Conversaciones y mensajes entre residentes y la administración.',
            'requires' => [],
            'counts' => ['resident_conversations' => 'conversaciones'],
        ],
        'bookings' => [
            'label' => 'Reservas de zonas comunes',
            'detail' => 'Solicitudes y reservas aprobadas o rechazadas.',
            'requires' => [],
            'counts' => ['common_area_bookings' => 'reservas'],
        ],
        'common_areas' => [
            'label' => 'Espacios comunes',
            'detail' => 'Espacios configurados (salón social, BBQ…) y sus horarios.',
            'requires' => ['bookings'],
            'counts' => ['common_areas' => 'espacios'],
        ],
        'classifieds' => [
            'label' => 'Clasificados',
            'detail' => 'Publicaciones de venta y arriendo con sus fotos.',
            'requires' => [],
            'counts' => ['property_classifieds' => 'publicaciones'],
        ],
        'assignments' => [
            'label' => 'Asignaciones de inmuebles',
            'detail' => 'Vínculos de usuarios con inmuebles y su historial (trazabilidad).',
            'requires' => [],
            'counts' => ['property_unit_assignments' => 'vigentes', 'property_unit_relationship_history' => 'en historial'],
        ],
        'composition' => [
            'label' => 'Conformación',
            'detail' => 'Respuestas del asistente, inmuebles y coeficientes.',
            'requires' => ['assignments', 'billing'],
            'counts' => [':units' => 'inmuebles'],
        ],
        'settings' => [
            'label' => 'Parametrización y caracterización',
            'detail' => 'Datos del conjunto, cuenta bancaria, configuración financiera, tipo de vivienda y nomenclatura. El nombre del conjunto se conserva.',
            'requires' => ['composition'],
            'counts' => [],
        ],
        'mail' => [
            'label' => 'Configuración de correo',
            'detail' => 'Servidor SMTP del conjunto.',
            'requires' => [],
            'counts' => ['copropiedad_mail_settings' => 'configuraciones'],
        ],
        'payment_settings' => [
            'label' => 'Credenciales de pasarelas',
            'detail' => 'Llaves de Bold y Wompi guardadas por el conjunto.',
            'requires' => [],
            'counts' => ['bold_payment_settings' => 'Bold', 'wompi_payment_settings' => 'Wompi'],
        ],
        'gallery' => [
            'label' => 'Sitio público',
            'detail' => 'Imágenes de la galería y textos personalizados (publicados y borrador) de la página pública.',
            'requires' => [],
            'counts' => [':gallery' => 'imágenes', 'public_site_content' => 'textos'],
        ],
        'residents' => [
            'label' => 'Cuentas de residentes',
            'detail' => 'Cuentas con rol Residente y sus fichas; con ellas se van sus vínculos con inmuebles, conversaciones, reservas y avisos.',
            'requires' => ['billing', 'classifieds'],
            'counts' => [':residents' => 'cuentas'],
        ],
        'resident_types' => [
            'label' => 'Tipos de residente',
            'detail' => 'Catálogo de tipos de residente; vuelve a los tipos iniciales (Propietario, Inquilino, Familiar o acompañante).',
            'requires' => ['residents'],
            'counts' => ['resident_types' => 'tipos'],
        ],
        'accounts' => [
            'label' => 'Todas las cuentas y roles',
            'detail' => 'Administradores, equipo de trabajo y roles personalizados. Después asignas el nuevo administrador.',
            'requires' => ['residents', 'messages'],
            'counts' => [':staff' => 'cuentas del equipo', 'admin_roles' => 'roles'],
        ],
    ];

    /**
     * Pasos de borrado en orden seguro para las llaves foráneas: [categoría, tabla que debe existir, SQL].
     */
    private const STEPS = [
        ['billing', 'billing_payment_links', 'DELETE FROM billing_payment_links WHERE tenant_id = ?'],
        ['billing', 'billing_payment_reports', 'DELETE FROM billing_payment_reports WHERE tenant_id = ?'],
        ['billing', 'billing_payments', 'DELETE FROM billing_payments WHERE tenant_id = ?'],
        ['billing', 'billing_online_payments', 'DELETE FROM billing_online_payments WHERE tenant_id = ?'],
        ['billing', 'billing_bold_terminal_payments', 'DELETE FROM billing_bold_terminal_payments WHERE tenant_id = ?'],
        ['billing', 'billing_wompi_payments', 'DELETE FROM billing_wompi_payments WHERE tenant_id = ?'],
        ['billing', 'billing_statement_user_reads', 'DELETE FROM billing_statement_user_reads WHERE statement_id IN (SELECT id FROM billing_statements WHERE tenant_id = ?)'],
        ['billing', 'billing_statement_lines', 'DELETE FROM billing_statement_lines WHERE statement_id IN (SELECT id FROM billing_statements WHERE tenant_id = ?)'],
        ['billing', 'billing_statement_recipients', 'DELETE FROM billing_statement_recipients WHERE statement_id IN (SELECT id FROM billing_statements WHERE tenant_id = ?)'],
        // Cada nota apunta a la anterior: se suelta el encadenamiento antes de borrarlas.
        ['billing', 'billing_statements', 'UPDATE billing_statements SET previous_statement_id = NULL WHERE tenant_id = ?'],
        ['billing', 'billing_statements', 'DELETE FROM billing_statements WHERE tenant_id = ?'],
        ['billing', 'billing_monthly_items', 'DELETE FROM billing_monthly_items WHERE tenant_id = ?'],
        ['notices', 'user_notices', 'DELETE FROM user_notices WHERE tenant_id = ?'],
        ['messages', 'admin_messages', 'DELETE FROM admin_messages WHERE tenant_id = ?'],
        ['conversations', 'resident_conversations', 'DELETE FROM resident_conversations WHERE tenant_id = ?'],
        ['bookings', 'common_area_bookings', 'DELETE FROM common_area_bookings WHERE tenant_id = ?'],
        ['common_areas', 'common_areas', 'DELETE FROM common_areas WHERE tenant_id = ?'],
        ['classifieds', 'property_classifieds', 'DELETE FROM property_classifieds WHERE tenant_id = ?'],
        ['assignments', 'property_unit_relationship_history', 'DELETE FROM property_unit_relationship_history WHERE tenant_id = ?'],
        ['assignments', 'property_unit_assignments', 'DELETE FROM property_unit_assignments WHERE tenant_id = ?'],
        ['composition', 'copropiedad_composition', 'DELETE FROM copropiedad_composition WHERE tenant_id = ?'],
        ['mail', 'copropiedad_mail_settings', 'DELETE FROM copropiedad_mail_settings WHERE tenant_id = ?'],
        ['payment_settings', 'bold_payment_settings', 'DELETE FROM bold_payment_settings WHERE tenant_id = ?'],
        ['payment_settings', 'wompi_payment_settings', 'DELETE FROM wompi_payment_settings WHERE tenant_id = ?'],
        ['gallery', 'public_site_content', 'DELETE FROM public_site_content WHERE tenant_id = ?'],
        ['settings', 'copropiedad_settings', 'DELETE FROM copropiedad_settings WHERE tenant_id = ?'],
        // Las cuentas arrastran fichas, tokens, correos personales, lecturas y bandejas (ON DELETE CASCADE).
        ['residents', 'admin_users', "DELETE users FROM admin_users AS users JOIN admin_roles AS roles ON roles.id = users.role_id
                                       WHERE users.tenant_id = ? AND roles.kind = 'resident'"],
        ['resident_types', 'resident_types', 'DELETE FROM resident_types WHERE tenant_id = ?'],
        ['accounts', 'resident_profiles', 'DELETE FROM resident_profiles WHERE tenant_id = ?'],
        ['accounts', 'admin_users', 'DELETE FROM admin_users WHERE tenant_id = ?'],
        ['accounts', 'admin_roles', 'DELETE FROM admin_roles WHERE tenant_id = ?'],
    ];

    /**
     * Categorías con lo que se borraría hoy.
     *
     * @return array<string, array{label: string, detail: string, requires: list<string>, counts: list<string>, total: int}>
     */
    public function summary(int $tenantId): array
    {
        $tables = $this->existingTables();
        $summary = [];
        foreach (self::CATEGORIES as $key => $category) {
            $counts = [];
            $total = 0;
            foreach ($category['counts'] as $source => $noun) {
                $count = $this->countFor($source, $tenantId, $tables);
                if ($count === null) {
                    continue;
                }
                $total += $count;
                $counts[] = number_format($count, 0, ',', '.') . ' ' . $noun;
            }
            $summary[$key] = [
                'label' => $category['label'],
                'detail' => $category['detail'],
                'requires' => $category['requires'],
                'counts' => $counts,
                'total' => $total,
            ];
        }

        return $summary;
    }

    /**
     * Valida una selección: solo claves conocidas y con todas sus dependencias incluidas.
     *
     * @param list<string> $selected
     * @return list<string> la selección en el orden del catálogo
     */
    public static function validateSelection(array $selected): array
    {
        $selected = array_values(array_unique($selected));
        if ($selected === []) {
            throw new RuntimeException('Selecciona al menos un dato para reiniciar.');
        }
        foreach ($selected as $key) {
            if (!isset(self::CATEGORIES[$key])) {
                throw new RuntimeException('La selección contiene un dato desconocido.');
            }
        }
        foreach ($selected as $key) {
            $missing = array_diff(self::CATEGORIES[$key]['requires'], $selected);
            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'Para borrar «%s» también debes borrar: %s.',
                    self::CATEGORIES[$key]['label'],
                    implode(', ', array_map(static fn (string $m): string => self::CATEGORIES[$m]['label'], $missing))
                ));
            }
        }

        return array_values(array_intersect(array_keys(self::CATEGORIES), $selected));
    }

    /**
     * Borra las categorías elegidas (todas si se omite) en una transacción y luego sus archivos.
     *
     * @param list<string>|null $categories
     * @return int cantidad de archivos eliminados
     */
    public function reset(int $tenantId, string $tenantName, ?array $categories = null): int
    {
        $selected = array_fill_keys(self::validateSelection($categories ?? array_keys(self::CATEGORIES)), true);
        $pdo = Database::connection();
        $tables = $this->existingTables();
        $files = $this->filesOf($tenantId, $tables, $selected);

        $pdo->beginTransaction();
        try {
            foreach (self::STEPS as [$category, $table, $sql]) {
                if (isset($selected[$category], $tables[$table])) {
                    $pdo->prepare($sql)->execute([$tenantId]);
                }
            }
            // Queda como un conjunto recién creado en lo que se borró.
            if (isset($selected['accounts'])) {
                TenantService::seedRoles($tenantId);
            }
            if (isset($selected['resident_types'], $tables['resident_types'])) {
                ResidentTypeService::seedDefaults($tenantId);
            }
            if (isset($selected['settings'])) {
                $pdo->prepare('INSERT INTO copropiedad_settings (tenant_id, property_name) VALUES (?, ?)')
                    ->execute([$tenantId, $tenantName]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $deleted = 0;
        foreach ($files as $path) {
            if (is_file($path) && unlink($path)) {
                $deleted++;
            } elseif (is_file($path)) {
                error_log('Reinicio de conjunto: no se pudo eliminar ' . $path);
            }
        }

        return $deleted;
    }

    /**
     * Archivos de las categorías elegidas: fotos de clasificados, soportes de pago y galería pública.
     *
     * @param array<string, true> $tables
     * @param array<string, true> $selected
     * @return list<string>
     */
    private function filesOf(int $tenantId, array $tables, array $selected): array
    {
        $files = [];
        $pattern = '/^[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/D';
        $collect = function (string $sql, string $directory) use ($tenantId, $pattern, &$files): void {
            $query = Database::connection()->prepare($sql);
            $query->execute([$tenantId]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (is_string($name) && preg_match($pattern, $name) === 1) {
                    $files[] = $directory . DIRECTORY_SEPARATOR . $name;
                }
            }
        };

        if (isset($selected['classifieds'], $tables['property_classified_images'])) {
            $collect(
                'SELECT images.storage_name FROM property_classified_images AS images
                 JOIN property_classifieds AS listings ON listings.id = images.listing_id
                 WHERE listings.tenant_id = ?',
                Storage::directory('classifieds')
            );
        }
        if (isset($selected['billing'], $tables['billing_payment_reports'])) {
            $collect(
                'SELECT proof_storage_name FROM billing_payment_reports WHERE tenant_id = ?',
                Storage::directory('payment-proofs')
            );
        }
        if (isset($selected['gallery'])) {
            array_push($files, ...$this->galleryFiles($tenantId));
        }

        return $files;
    }

    /** @return list<string> */
    private function galleryFiles(int $tenantId): array
    {
        $directory = PropertyGalleryService::directoryFor($tenantId);

        return is_dir($directory) ? array_values(array_filter(glob($directory . '/*') ?: [], 'is_file')) : [];
    }

    /**
     * Registros que se borrarían de una fuente; null si su tabla aún no existe.
     *
     * @param array<string, true> $tables
     */
    private function countFor(string $source, int $tenantId, array $tables): ?int
    {
        $sql = match ($source) {
            ':units' => isset($tables['copropiedad_composition'])
                ? 'SELECT COALESCE(SUM(JSON_LENGTH(units)), 0) FROM copropiedad_composition WHERE tenant_id = ?'
                : null,
            ':residents' => "SELECT COUNT(*) FROM admin_users AS users JOIN admin_roles AS roles ON roles.id = users.role_id
                             WHERE users.tenant_id = ? AND roles.kind = 'resident'",
            ':staff' => "SELECT COUNT(*) FROM admin_users AS users JOIN admin_roles AS roles ON roles.id = users.role_id
                         WHERE users.tenant_id = ? AND roles.kind <> 'resident'",
            ':gallery' => '',
            default => isset($tables[$source]) ? "SELECT COUNT(*) FROM {$source} WHERE tenant_id = ?" : null,
        };
        if ($sql === null) {
            return null;
        }
        if ($sql === '') {
            return count($this->galleryFiles($tenantId));
        }
        $query = Database::connection()->prepare($sql);
        $query->execute([$tenantId]);

        return (int) $query->fetchColumn();
    }

    /** @return array<string, true> Tablas presentes (las migraciones nuevas pueden no estar aplicadas). */
    private function existingTables(): array
    {
        $names = Database::connection()
            ->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_fill_keys(array_map('strval', $names), true);
    }
}
