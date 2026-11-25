<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\Tenant;
use App\Modules\Configuration\SettingsService;
use PDOException;
use RuntimeException;

/**
 * Documentos de privacidad del conjunto (Ley 1581 de 2012, Decreto 1377 de 2013):
 *   - data_policy:   política de tratamiento de datos personales (contenido mínimo del art. 13 del Decreto 1377).
 *   - authorization: texto de la autorización que el titular acepta en el formulario (aviso de privacidad, art. 15).
 *   - cookie_policy: política de cookies del sitio público.
 * Cada publicación crea una versión nueva e inmutable: lo que aceptó cada titular queda como prueba.
 * Las plantillas iniciales se ofrecen ya completadas con los datos del conjunto para que el administrador las revise.
 */
final class PrivacyService
{
    public const DOCUMENTS = [
        'data_policy' => [
            'label' => 'Política de tratamiento de datos',
            'public_path' => '/politica-de-datos',
            'max_length' => 60000,
        ],
        'authorization' => [
            'label' => 'Autorización del formulario',
            'public_path' => '/autorizacion-de-datos',
            'max_length' => 2000,
        ],
        'cookie_policy' => [
            'label' => 'Política de cookies',
            'public_path' => '/politica-de-cookies',
            'max_length' => 30000,
        ],
    ];

    /** @var array<string, ?array> */
    private array $currentCache = [];

    public static function isDocument(string $document): bool
    {
        return isset(self::DOCUMENTS[$document]);
    }

    /** El Superadmin habilita el módulo por conjunto; deshabilitado, nada de privacidad se muestra ni se recibe. */
    public static function moduleEnabled(): bool
    {
        return ModuleCatalog::enabled('privacidad');
    }

    /**
     * Versión vigente (la última publicada) de un documento, o null si aún no se ha publicado.
     *
     * @return array{id: int, version: int, content: string, change_summary: string, published_at: string, published_by_name: ?string}|null
     */
    public function current(string $document): ?array
    {
        $this->assertDocument($document);
        if (array_key_exists($document, $this->currentCache)) {
            return $this->currentCache[$document];
        }
        // Con el módulo deshabilitado no hay documentos vigentes: la página pública oculta políticas, aviso y formulario.
        if (!self::moduleEnabled()) {
            return $this->currentCache[$document] = null;
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT versions.id, versions.version, versions.content, versions.change_summary, versions.published_at,
                        users.username AS published_by_name
                 FROM privacy_document_versions AS versions
                 LEFT JOIN admin_users AS users ON users.id = versions.published_by
                 WHERE versions.tenant_id = ? AND versions.document = ?
                 ORDER BY versions.version DESC LIMIT 1'
            );
            $statement->execute([Tenant::id(), $document]);
            $row = $statement->fetch();
        } catch (PDOException $exception) {
            // Sin la migración 037 el sitio público sigue funcionando, sin políticas.
            if ((string) $exception->getCode() === '42S02') {
                return $this->currentCache[$document] = null;
            }
            throw $exception;
        }

        return $this->currentCache[$document] = is_array($row) ? $this->normalizeVersion($row) : null;
    }

    /** Una versión concreta del conjunto actual (para consultar lo que aceptó un titular). */
    public function version(int $versionId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT versions.id, versions.document, versions.version, versions.content, versions.change_summary,
                    versions.published_at, users.username AS published_by_name
             FROM privacy_document_versions AS versions
             LEFT JOIN admin_users AS users ON users.id = versions.published_by
             WHERE versions.tenant_id = ? AND versions.id = ?'
        );
        $statement->execute([Tenant::id(), $versionId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeVersion($row) + ['document' => (string) $row['document']] : null;
    }

    /** @return list<array{id: int, version: int, change_summary: string, published_at: string, published_by_name: ?string}> */
    public function history(string $document): array
    {
        $this->assertDocument($document);
        $statement = Database::connection()->prepare(
            'SELECT versions.id, versions.version, versions.content, versions.change_summary, versions.published_at,
                    users.username AS published_by_name
             FROM privacy_document_versions AS versions
             LEFT JOIN admin_users AS users ON users.id = versions.published_by
             WHERE versions.tenant_id = ? AND versions.document = ?
             ORDER BY versions.version DESC'
        );
        $statement->execute([Tenant::id(), $document]);

        return array_map(fn (array $row): array => $this->normalizeVersion($row), $statement->fetchAll());
    }

    /**
     * Publica una versión nueva del documento. Devuelve el número de versión publicado.
     */
    public function publish(string $document, string $content, string $changeSummary, int $actorId): int
    {
        $this->assertDocument($document);
        $content = self::normalizeText($content);
        $changeSummary = trim(preg_replace('/\s+/u', ' ', $changeSummary) ?? '');
        $maxLength = self::DOCUMENTS[$document]['max_length'];

        if (mb_strlen($content, 'UTF-8') < 40) {
            throw new RuntimeException('El texto es demasiado corto para ser válido.');
        }
        if (mb_strlen($content, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('El texto supera el máximo de %s caracteres.', number_format($maxLength, 0, ',', '.')));
        }
        if (mb_strlen($changeSummary, 'UTF-8') < 5 || mb_strlen($changeSummary, 'UTF-8') > 255) {
            throw new RuntimeException('Describe el cambio entre 5 y 255 caracteres; queda en el historial de versiones.');
        }
        $current = $this->current($document);
        if ($current !== null && $current['content'] === $content) {
            throw new RuntimeException('El texto no cambió frente a la versión vigente; no se publicó una versión nueva.');
        }
        if ($document !== 'cookie_policy' && $this->contactEmail() === '') {
            throw new RuntimeException('Antes de publicar, registra el correo para peticiones, consultas y reclamos en «Responsable».');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Bloquea las versiones del documento para numerar sin choques entre dos publicaciones simultáneas.
            $statement = $pdo->prepare(
                'SELECT COALESCE(MAX(version), 0) FROM privacy_document_versions WHERE tenant_id = ? AND document = ? FOR UPDATE'
            );
            $statement->execute([Tenant::id(), $document]);
            $version = (int) $statement->fetchColumn() + 1;
            $pdo->prepare(
                'INSERT INTO privacy_document_versions (tenant_id, document, version, content, change_summary, published_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $document, $version, $content, $changeSummary, $actorId]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        unset($this->currentCache[$document]);

        return $version;
    }

    /**
     * @return array{contact_email: string, contact_phone: string, responsible_area: string, contact_form_enabled: bool}
     */
    public function settings(): array
    {
        $defaults = ['contact_email' => '', 'contact_phone' => '', 'responsible_area' => 'Administración', 'contact_form_enabled' => true];
        try {
            $statement = Database::connection()->prepare(
                'SELECT contact_email, contact_phone, responsible_area, contact_form_enabled FROM privacy_settings WHERE tenant_id = ?'
            );
            $statement->execute([Tenant::id()]);
            $row = $statement->fetch();
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') {
                return $defaults;
            }
            throw $exception;
        }
        if (!is_array($row)) {
            return $defaults;
        }

        return [
            'contact_email' => (string) ($row['contact_email'] ?? ''),
            'contact_phone' => (string) ($row['contact_phone'] ?? ''),
            'responsible_area' => (string) ($row['responsible_area'] ?? '') ?: $defaults['responsible_area'],
            'contact_form_enabled' => (bool) $row['contact_form_enabled'],
        ];
    }

    public function saveSettings(string $email, string $phone, string $area, bool $contactFormEnabled, int $actorId): void
    {
        $email = trim($email);
        $phone = trim($phone);
        $area = trim(preg_replace('/\s+/u', ' ', $area) ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new RuntimeException('Ingresa un correo válido para peticiones, consultas y reclamos.');
        }
        if ($phone !== '' && preg_match('/^[0-9+() .-]{7,30}$/D', $phone) !== 1) {
            throw new RuntimeException('El teléfono solo puede tener números, espacios y los signos + ( ) - .');
        }
        if (mb_strlen($area, 'UTF-8') < 3 || mb_strlen($area, 'UTF-8') > 120) {
            throw new RuntimeException('Indica el área o persona responsable (entre 3 y 120 caracteres).');
        }

        Database::connection()->prepare(
            'INSERT INTO privacy_settings (tenant_id, contact_email, contact_phone, responsible_area, contact_form_enabled, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE contact_email = VALUES(contact_email), contact_phone = VALUES(contact_phone),
                 responsible_area = VALUES(responsible_area), contact_form_enabled = VALUES(contact_form_enabled),
                 updated_by = VALUES(updated_by)'
        )->execute([Tenant::id(), $email, $phone === '' ? null : $phone, $area, $contactFormEnabled ? 1 : 0, $actorId]);
    }

    /** El formulario de contacto solo se ofrece con la política y la autorización publicadas. */
    public function contactFormAvailable(): bool
    {
        return $this->settings()['contact_form_enabled']
            && $this->current('data_policy') !== null
            && $this->current('authorization') !== null;
    }

    /** Texto con el que se abre el editor: la versión vigente o, si no hay, la plantilla inicial completada. */
    public function editorText(string $document): string
    {
        return $this->current($document)['content'] ?? $this->template($document);
    }

    /**
     * Plantilla inicial con los datos del conjunto. Es un punto de partida: debe revisarla el conjunto
     * (idealmente con asesoría jurídica) antes de publicarla.
     */
    public function template(string $document): string
    {
        $this->assertDocument($document);
        $settings = (new SettingsService())->current();
        $privacy = $this->settings();
        $values = [
            '{nombre}' => trim((string) ($settings['property_name'] ?? '')) ?: Tenant::name(),
            '{nit}' => trim((string) ($settings['tax_id'] ?? '')) ?: '[NIT pendiente]',
            '{direccion}' => trim((string) ($settings['address'] ?? '')) ?: '[dirección pendiente]',
            '{correo}' => $privacy['contact_email'] !== '' ? $privacy['contact_email'] : '[correo pendiente]',
            '{telefono}' => $privacy['contact_phone'] !== '' ? $privacy['contact_phone'] : 'no disponible',
            '{area}' => $privacy['responsible_area'],
            '{sitio}' => rtrim(Tenant::absoluteUrl('/'), '/'),
            '{fecha}' => self::longDate(date('Y-m-d')),
        ];
        $template = (string) file_get_contents(__DIR__ . '/templates/' . $document . '.txt');

        return self::normalizeText(strtr($template, $values));
    }

    public function contactEmail(): string
    {
        return $this->settings()['contact_email'];
    }

    /**
     * Convierte el texto plano del documento en bloques para mostrarlo: «# » título, «- » viñeta, el resto párrafos.
     *
     * @return list<array{type: 'heading'|'list'|'paragraph', text?: string, items?: list<string>}>
     */
    public static function blocks(string $content): array
    {
        $blocks = [];
        foreach (preg_split('/\n{2,}/', self::normalizeText($content)) ?: [] as $chunk) {
            $lines = explode("\n", trim($chunk));
            if ($lines === [''] ) {
                continue;
            }
            if (count($lines) === 1 && str_starts_with($lines[0], '# ')) {
                $blocks[] = ['type' => 'heading', 'text' => trim(substr($lines[0], 2))];
                continue;
            }
            if (str_starts_with($lines[0], '# ')) {
                $blocks[] = ['type' => 'heading', 'text' => trim(substr(array_shift($lines), 2))];
            }
            $items = [];
            $paragraph = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, '- ')) {
                    if ($paragraph !== []) {
                        $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph)];
                        $paragraph = [];
                    }
                    $items[] = trim(substr($line, 2));
                } else {
                    if ($items !== []) {
                        $blocks[] = ['type' => 'list', 'items' => $items];
                        $items = [];
                    }
                    $paragraph[] = trim($line);
                }
            }
            if ($items !== []) {
                $blocks[] = ['type' => 'list', 'items' => $items];
            }
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph)];
            }
        }

        return $blocks;
    }

    public static function longDate(string $date): string
    {
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $timestamp = strtotime($date) ?: time();

        return (int) date('j', $timestamp) . ' de ' . $months[(int) date('n', $timestamp) - 1] . ' de ' . date('Y', $timestamp);
    }

    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private function normalizeVersion(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'version' => (int) $row['version'],
            'content' => (string) $row['content'],
            'change_summary' => (string) $row['change_summary'],
            'published_at' => (string) $row['published_at'],
            'published_by_name' => isset($row['published_by_name']) ? (string) $row['published_by_name'] : null,
        ];
    }

    private function assertDocument(string $document): void
    {
        if (!self::isDocument($document)) {
            throw new RuntimeException('El documento solicitado no existe.');
        }
    }
}
