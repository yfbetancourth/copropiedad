<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\Tenant;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Textos del sitio público de cada conjunto, con borrador y publicación (migraciones 035 y 045: una fila por texto
 * cambiado en public_site_texts; los metadatos de publicación en public_site_content).
 *
 * Solo se guardan los textos que el conjunto cambió; un campo vacío vuelve al texto por defecto. En los textos,
 * {nombre} se reemplaza por el nombre del conjunto. Los textos se muestran siempre escapados: no admiten HTML.
 */
final class PublicSiteService
{
    /** Marcador que se reemplaza por el nombre del conjunto. */
    public const NAME_TOKEN = '{nombre}';

    /**
     * Secciones en el orden de la portada. Cada campo: [etiqueta, texto por defecto, longitud máxima, varias líneas].
     *
     * @var array<string, array{label: string, help: string, fields: array<string, array{0: string, 1: string, 2: int, 3: bool}>}>
     */
    public const SECTIONS = [
        'general' => [
            'label' => 'Encabezado, pie y buscadores',
            'help' => 'Se ven en todas las páginas públicas (portada y clasificados).',
            'fields' => [
                'brand_tagline' => ['Frase bajo el nombre del conjunto', 'Vivir en comunidad', 40, false],
                'footer_text' => ['Frase del pie de página', 'Un buen lugar se construye entre todos.', 160, false],
                'meta_description' => ['Descripción para buscadores (Google)', '', 160, true],
            ],
        ],
        'hero' => [
            'label' => 'Portada principal',
            'help' => 'Lo primero que ve el visitante. Las fotos se toman de la Galería pública.',
            'fields' => [
                'hero_eyebrow' => ['Antetítulo', 'Un lugar para vivir mejor', 60, false],
                'hero_title' => ['Título', 'La vida se siente distinta', 90, false],
                'hero_title_em' => ['Título, parte destacada (cursiva)', 'cuando es en comunidad.', 90, false],
                'hero_lede' => ['Texto de presentación', '{nombre} es más que un lugar para vivir. Es el espacio que compartimos, cuidamos y hacemos nuestro cada día.', 400, true],
                'hero_button' => ['Botón principal', 'Entrar a mi portal', 40, false],
                'hero_footnote' => ['Nota inferior', 'Un espacio compartido. Muchas formas de sentirse en casa.', 120, false],
                'hero_photo_note' => ['Etiqueta sobre la foto', 'Nuestro lugar, todos los días', 60, false],
                'hero_inset_caption' => ['Leyenda de la foto pequeña', 'Hecho para compartir', 40, false],
                'hero_stamp_1' => ['Sello, palabra 1', 'HOGAR', 14, false],
                'hero_stamp_2' => ['Sello, palabra 2', 'COMUNIDAD', 14, false],
            ],
        ],
        'strip' => [
            'label' => 'Franja de frases',
            'help' => 'Tres frases cortas bajo la portada.',
            'fields' => [
                'strip_1' => ['Frase 1', 'Un buen vecino', 40, false],
                'strip_2' => ['Frase 2', 'Un espacio cuidado', 40, false],
                'strip_3' => ['Frase 3', 'Una comunidad que crece', 40, false],
            ],
        ],
        'spaces' => [
            'label' => 'Espacios (con fotos en la galería)',
            'help' => 'Se muestra cuando la Galería pública tiene fotos.',
            'fields' => [
                'spaces_eyebrow' => ['Antetítulo', 'Nuestro entorno', 60, false],
                'spaces_title' => ['Título', 'Un lugar que', 90, false],
                'spaces_title_em' => ['Título, parte destacada', 'se siente propio.', 90, false],
                'spaces_text' => ['Texto', 'Los pequeños momentos también hacen hogar: compartir, descansar, encontrarse y disfrutar los espacios que son de todos.', 300, true],
                'caption_1' => ['Leyenda foto 1', 'Momentos para compartir', 50, false],
                'caption_2' => ['Leyenda foto 2', 'Un respiro en casa', 50, false],
                'caption_3' => ['Leyenda foto 3', 'La vida en común', 50, false],
                'caption_4' => ['Leyenda foto 4', 'Cada rincón cuenta', 50, false],
            ],
        ],
        'values' => [
            'label' => 'Valores (sin fotos en la galería)',
            'help' => 'Reemplaza la sección Espacios mientras la Galería pública no tenga fotos.',
            'fields' => [
                'values_eyebrow' => ['Antetítulo', 'Nuestro entorno', 60, false],
                'values_title' => ['Título', 'La comunidad se construye', 90, false],
                'values_title_em' => ['Título, parte destacada', 'en cada detalle.', 90, false],
                'values_text' => ['Texto', 'Esta es una invitación a encontrarnos, cuidar lo que compartimos y hacer de este lugar un hogar para todos.', 300, true],
                'value_1_title' => ['Valor 1, título', 'Compartir', 40, false],
                'value_1_text' => ['Valor 1, texto', 'Los buenos momentos crecen cuando se viven en compañía.', 160, true],
                'value_2_title' => ['Valor 2, título', 'Cuidar', 40, false],
                'value_2_text' => ['Valor 2, texto', 'Un espacio común se siente mejor cuando todos lo hacemos nuestro.', 160, true],
                'value_3_title' => ['Valor 3, título', 'Conectar', 40, false],
                'value_3_text' => ['Valor 3, texto', 'Pequeños encuentros que hacen más cercana la vida de cada día.', 160, true],
            ],
        ],
        'band' => [
            'label' => 'Banda de comunidad',
            'help' => 'Franja oscura con invitación al portal de residentes.',
            'fields' => [
                'band_eyebrow' => ['Antetítulo', 'Una comunidad, muchas historias', 60, false],
                'band_title' => ['Título', 'Lo mejor de vivir aquí', 90, false],
                'band_title_em' => ['Título, parte destacada', 'lo hacemos entre todos.', 90, false],
                'band_button' => ['Botón', 'Conoce tu portal', 40, false],
            ],
        ],
        'location' => [
            'label' => 'Ubicación',
            'help' => 'La dirección se toma de Parametrización.',
            'fields' => [
                'location_eyebrow' => ['Antetítulo', 'Cerca de lo que importa', 60, false],
                'location_title' => ['Título', 'Aquí empieza', 90, false],
                'location_title_em' => ['Título, parte destacada', 'tu lugar.', 90, false],
                'location_text' => ['Texto', 'Encuéntranos fácilmente y descubre cómo llegar.', 200, true],
                'location_button' => ['Botón del mapa', 'Ver ubicación en Google Maps', 50, false],
            ],
        ],
        'classifieds' => [
            'label' => 'Clasificados',
            'help' => 'Solo se muestra si el módulo Clasificados está habilitado.',
            'fields' => [
                'classifieds_eyebrow' => ['Antetítulo', 'Oportunidades de la comunidad', 60, false],
                'classifieds_title' => ['Título', 'Algo bueno puede', 90, false],
                'classifieds_title_em' => ['Título, parte destacada', 'estar más cerca.', 90, false],
                'classifieds_empty_title' => ['Sin publicaciones, título', 'Las buenas oportunidades empiezan cerca.', 90, false],
                'classifieds_empty_text' => ['Sin publicaciones, texto', 'Pronto podrás descubrir publicaciones de venta y búsqueda compartidas por los residentes.', 200, true],
                'resident_cta_eyebrow' => ['Invitación a residentes, antetítulo', '¿Ya haces parte de {nombre}?', 90, false],
                'resident_cta_title' => ['Invitación a residentes, título', 'Tu comunidad también está en línea.', 90, false],
                'resident_cta_text' => ['Invitación a residentes, texto', 'Consulta tu inmueble, revisa tus mensajes y comparte un clasificado desde tu panel.', 200, true],
                'resident_cta_button' => ['Invitación a residentes, botón', 'Entrar al panel de usuario', 40, false],
            ],
        ],
    ];

    /** Textos publicados, con los valores por defecto para lo que no se cambió. */
    public function published(): array
    {
        return self::resolve($this->row()['published']);
    }

    /** Textos del borrador (o los publicados si no hay borrador), con los valores por defecto. */
    public function draft(): array
    {
        return self::resolve($this->draftOverrides());
    }

    /** Solo los textos cambiados del borrador (o de lo publicado si no hay borrador): para el formulario. */
    public function draftOverrides(): array
    {
        $row = $this->row();

        return $row['draft'] ?? $row['published'];
    }

    /**
     * @return array{available: bool, has_draft: bool, draft_updated_at: ?string, published_at: ?string, customized: int}
     */
    public function status(): array
    {
        $row = $this->row();

        return [
            'available' => $row['available'],
            'has_draft' => $row['draft'] !== null,
            'draft_updated_at' => $row['draft_updated_at'],
            'published_at' => $row['published_at'],
            'customized' => count($row['published']),
        ];
    }

    /**
     * Valida y guarda el borrador. Devuelve los errores por campo (vacío si se guardó).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function saveDraft(array $input, int $actorId): array
    {
        $overrides = [];
        $errors = [];
        foreach (self::fields() as $key => [$label, $default, $maxLength, $multiline]) {
            $value = $input[$key] ?? '';
            if (!is_string($value)) {
                $errors[$key] = 'Valor no válido.';
                continue;
            }
            $value = self::clean($value, $multiline);
            if (mb_strlen($value, 'UTF-8') > $maxLength) {
                $errors[$key] = sprintf('Máximo %d caracteres.', $maxLength);
                continue;
            }
            // Vacío o igual al texto por defecto: no se guarda, así sigue los cambios futuros del texto por defecto.
            if ($value !== '' && $value !== $default) {
                $overrides[$key] = $value;
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->requireTable();
        $tenantId = Tenant::id();
        $this->transaction(function (PDO $pdo) use ($tenantId, $overrides, $actorId): void {
            $pdo->prepare(
                'INSERT INTO public_site_content (tenant_id, draft_updated_at, draft_updated_by)
                 VALUES (?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE draft_updated_at = VALUES(draft_updated_at), draft_updated_by = VALUES(draft_updated_by)'
            )->execute([$tenantId, $actorId]);
            $pdo->prepare("DELETE FROM public_site_texts WHERE tenant_id = ? AND version = 'draft'")->execute([$tenantId]);
            $insert = $pdo->prepare("INSERT INTO public_site_texts (tenant_id, version, field_key, content) VALUES (?, 'draft', ?, ?)");
            foreach ($overrides as $key => $value) {
                $insert->execute([$tenantId, $key, $value]);
            }
        });

        return [];
    }

    /** Publica el borrador. Devuelve false si no hay borrador. */
    public function publish(int $actorId): bool
    {
        $this->requireTable();
        $tenantId = Tenant::id();

        return $this->transaction(function (PDO $pdo) use ($tenantId, $actorId): bool {
            $draft = $pdo->prepare(
                'SELECT 1 FROM public_site_content WHERE tenant_id = ? AND draft_updated_at IS NOT NULL FOR UPDATE'
            );
            $draft->execute([$tenantId]);
            if ($draft->fetchColumn() === false) {
                return false;
            }
            $pdo->prepare("DELETE FROM public_site_texts WHERE tenant_id = ? AND version = 'published'")->execute([$tenantId]);
            $pdo->prepare("UPDATE public_site_texts SET version = 'published' WHERE tenant_id = ? AND version = 'draft'")->execute([$tenantId]);
            $pdo->prepare(
                'UPDATE public_site_content
                    SET published_at = NOW(), published_by = ?, draft_updated_at = NULL, draft_updated_by = NULL
                  WHERE tenant_id = ?'
            )->execute([$actorId, $tenantId]);

            return true;
        });
    }

    public function discardDraft(): bool
    {
        $this->requireTable();
        $tenantId = Tenant::id();

        return $this->transaction(function (PDO $pdo) use ($tenantId): bool {
            $pdo->prepare("DELETE FROM public_site_texts WHERE tenant_id = ? AND version = 'draft'")->execute([$tenantId]);
            $statement = $pdo->prepare(
                'UPDATE public_site_content SET draft_updated_at = NULL, draft_updated_by = NULL
                  WHERE tenant_id = ? AND draft_updated_at IS NOT NULL'
            );
            $statement->execute([$tenantId]);

            return $statement->rowCount() > 0;
        });
    }

    /**
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Reemplaza {nombre}. El resultado se debe escapar al mostrarlo. */
    public static function fill(string $text, string $propertyName): string
    {
        return str_replace(self::NAME_TOKEN, $propertyName, $text);
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: bool}> */
    public static function fields(): array
    {
        $fields = [];
        foreach (self::SECTIONS as $section) {
            $fields += $section['fields'];
        }

        return $fields;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return array_map(static fn (array $field): string => $field[1], self::fields());
    }

    /** @param array<string, string> $overrides */
    private static function resolve(array $overrides): array
    {
        return array_replace(self::defaults(), array_intersect_key($overrides, self::fields()));
    }

    private static function clean(string $value, bool $multiline): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Sin caracteres de control; los saltos de línea solo en los campos de varias líneas.
        $value = preg_replace($multiline ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $multiline ? '' : ' ', $value) ?? '';
        if ($multiline) {
            $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
        }

        return trim($value);
    }

    /**
     * @return array{available: bool, published: array<string, string>, draft: ?array<string, string>,
     *               draft_updated_at: ?string, published_at: ?string}
     */
    private function row(): array
    {
        $empty = ['available' => true, 'published' => [], 'draft' => null, 'draft_updated_at' => null, 'published_at' => null];
        $pdo = Database::connection();
        try {
            $statement = $pdo->prepare('SELECT published_at, draft_updated_at FROM public_site_content WHERE tenant_id = ?');
            $statement->execute([Tenant::id()]);
            $row = $statement->fetch();
            $texts = $pdo->prepare('SELECT version, field_key, content FROM public_site_texts WHERE tenant_id = ?');
            $texts->execute([Tenant::id()]);
        } catch (PDOException $exception) {
            // Sin las migraciones 035/045 el sitio público sigue funcionando con los textos por defecto.
            if ($exception->getCode() === '42S02') {
                return ['available' => false] + $empty;
            }
            throw $exception;
        }
        if (!is_array($row)) {
            return $empty;
        }

        // Solo campos que siguen existiendo en SECTIONS: un campo retirado del código deja de mostrarse.
        $fields = self::fields();
        $byVersion = ['published' => [], 'draft' => []];
        foreach ($texts->fetchAll() as $text) {
            if (isset($fields[$text['field_key']])) {
                $byVersion[$text['version']][$text['field_key']] = (string) $text['content'];
            }
        }
        // En el orden de las secciones del formulario.
        foreach ($byVersion as &$overrides) {
            $overrides = array_intersect_key(array_replace(array_fill_keys(array_keys($fields), null), $overrides), $overrides);
        }
        unset($overrides);

        return [
            'available' => true,
            'published' => $byVersion['published'],
            'draft' => $row['draft_updated_at'] !== null ? $byVersion['draft'] : null,
            'draft_updated_at' => $row['draft_updated_at'],
            'published_at' => $row['published_at'],
        ];
    }

    private function requireTable(): void
    {
        if (!$this->row()['available']) {
            throw new RuntimeException('Falta aplicar las migraciones 035 y 045 (sitio público). Pídele al administrador técnico que las aplique.');
        }
    }
}
