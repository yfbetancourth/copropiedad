-- Personalización del sitio público: textos de cada sección de la portada, el encabezado y el pie.
-- Se guardan solo los textos que el conjunto cambió (JSON clave => texto); los demás usan el texto por defecto.
--   draft_content     = borrador en edición, visible solo en la vista previa del panel.
--   published_content = lo que ve el público.
-- Se gestiona con el permiso «Parametrización». Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS public_site_content (
    tenant_id BIGINT UNSIGNED NOT NULL,
    published_content LONGTEXT NULL,
    published_at DATETIME NULL,
    published_by BIGINT UNSIGNED NULL,
    draft_content LONGTEXT NULL,
    draft_updated_at DATETIME NULL,
    draft_updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (tenant_id),
    CONSTRAINT chk_public_site_published_json CHECK (published_content IS NULL OR JSON_VALID(published_content)),
    CONSTRAINT chk_public_site_draft_json CHECK (draft_content IS NULL OR JSON_VALID(draft_content)),
    CONSTRAINT fk_public_site_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_public_site_published_by FOREIGN KEY (published_by) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_public_site_draft_by FOREIGN KEY (draft_updated_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
