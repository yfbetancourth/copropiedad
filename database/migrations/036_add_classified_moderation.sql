-- Moderación de clasificados: la administración decide qué se publica.
--   pending   = enviado por el residente (nuevo o editado), espera revisión; no se ve en el sitio público.
--   published = aprobado por la administración; se ve en el sitio público.
--   rejected  = no publicado por la administración, con motivo (review_note) que el residente ve en su portal.
--   closed    = cerrado por el residente.
-- approved_at: fecha de la aprobación vigente; se borra al editar o rechazar. Si el residente cierra y reabre un
-- clasificado sin editarlo, vuelve a publicarse; si no, pasa a revisión.
-- Los clasificados que ya estaban publicados o cerrados se consideran aprobados.
-- Idempotente: se puede aplicar más de una vez.

ALTER TABLE property_classifieds
    MODIFY status ENUM('pending', 'published', 'rejected', 'closed') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS review_note VARCHAR(255) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER review_note,
    ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL AFTER reviewed_at,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER reviewed_by;

UPDATE property_classifieds
SET approved_at = created_at
WHERE approved_at IS NULL AND reviewed_at IS NULL AND status IN ('published', 'closed');

ALTER TABLE property_classifieds DROP FOREIGN KEY IF EXISTS fk_property_classifieds_reviewed_by;
ALTER TABLE property_classifieds
    ADD CONSTRAINT fk_property_classifieds_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE SET NULL;

-- Permiso del panel para moderar clasificados: Total para el rol de sistema; los roles personalizados empiezan
-- sin acceso y se ajustan en Configuración / Roles y permisos.
INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'clasificados', CASE WHEN roles.is_system = 1 THEN 'total' ELSE 'none' END
FROM admin_roles AS roles;
