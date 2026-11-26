-- Normalización: los textos del sitio público pasan de los JSON public_site_content.published_content y
-- draft_content a una fila por texto modificado.
--   public_site_texts   = (conjunto, versión publicada o borrador, campo) → texto. Solo se guardan los textos que el
--                         conjunto cambió; los demás usan el texto por defecto definido en PublicSiteService.
--   public_site_content = queda con los metadatos: quién y cuándo publicó, y quién y cuándo editó el borrador.
--                         Hay borrador cuando draft_updated_at no es NULL (un borrador puede no tener filas: «todo
--                         vuelve a los textos por defecto»).
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS public_site_texts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    version ENUM('published', 'draft') NOT NULL,
    field_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content VARCHAR(400) NOT NULL,
    PRIMARY KEY (tenant_id, version, field_key),
    CONSTRAINT chk_public_site_texts_key CHECK (field_key REGEXP '^[a-z0-9_]+$'),
    CONSTRAINT chk_public_site_texts_content CHECK (content <> ''),
    CONSTRAINT fk_public_site_texts_content FOREIGN KEY (tenant_id)
        REFERENCES public_site_content (tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traspaso desde los JSON, solo mientras las columnas existan.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'public_site_content' AND COLUMN_NAME = 'draft_content') THEN
        EXECUTE IMMEDIATE
            'INSERT IGNORE INTO public_site_texts (tenant_id, version, field_key, content)
             SELECT c.tenant_id, v.version, k.field_key,
                    JSON_UNQUOTE(JSON_EXTRACT(IF(v.version = ''published'', c.published_content, c.draft_content), CONCAT(''$."'', k.field_key, ''"'')))
             FROM public_site_content AS c
             JOIN (SELECT ''published'' AS version UNION ALL SELECT ''draft'') AS v
             JOIN JSON_TABLE(JSON_KEYS(IF(v.version = ''published'', c.published_content, c.draft_content)),
                             ''$[*]'' COLUMNS (field_key VARCHAR(40) PATH ''$'')) AS k
             WHERE IF(v.version = ''published'', c.published_content, c.draft_content) IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(IF(v.version = ''published'', c.published_content, c.draft_content), CONCAT(''$."'', k.field_key, ''"''))) <> ''''';
        -- Un borrador guardado siempre tenía fecha; se asegura para que siga contando como borrador.
        EXECUTE IMMEDIATE
            'UPDATE public_site_content SET draft_updated_at = COALESCE(draft_updated_at, NOW())
             WHERE draft_content IS NOT NULL';
    END IF;
END //
DELIMITER ;

ALTER TABLE public_site_content
    DROP CONSTRAINT IF EXISTS chk_public_site_published_json,
    DROP CONSTRAINT IF EXISTS chk_public_site_draft_json;
ALTER TABLE public_site_content
    DROP COLUMN IF EXISTS published_content,
    DROP COLUMN IF EXISTS draft_content;
