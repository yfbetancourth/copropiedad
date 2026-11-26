-- Paleta de colores del sitio público (Configuración / Sitio público), con el mismo flujo de borrador y publicación
-- que los textos:
--   published_palette = paleta que ve el público («bosque» = la identidad original, para no cambiar nada al migrar).
--   draft_palette     = paleta elegida en el borrador; NULL = el borrador no cambia la paleta.
-- Las claves válidas están en App\Modules\Configuration\PublicSitePalette (los valores de color viven en el código,
-- igual que los textos por defecto). Idempotente: se puede aplicar más de una vez.

ALTER TABLE public_site_content
    ADD COLUMN IF NOT EXISTS published_palette VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'bosque' AFTER tenant_id,
    ADD COLUMN IF NOT EXISTS draft_palette VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER published_by;

ALTER TABLE public_site_content
    DROP CONSTRAINT IF EXISTS chk_public_site_palettes;
ALTER TABLE public_site_content
    ADD CONSTRAINT chk_public_site_palettes CHECK (
        published_palette REGEXP '^[a-z0-9-]{1,32}$'
        AND (draft_palette IS NULL OR draft_palette REGEXP '^[a-z0-9-]{1,32}$')
    );
