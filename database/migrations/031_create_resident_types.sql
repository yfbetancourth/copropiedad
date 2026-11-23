-- Catálogo de tipos de residente por conjunto (Comunidad / Tipos de residente) y tipo en la ficha del residente.
-- * Un tipo en uso no se puede eliminar (RESTRICT): se desactiva y deja de ofrecerse en el formulario.
-- * Cada conjunto empieza con tres tipos editables.

CREATE TABLE IF NOT EXISTS resident_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resident_types_name (tenant_id, name),
    CONSTRAINT fk_resident_types_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE resident_profiles
    ADD COLUMN IF NOT EXISTS resident_type_id BIGINT UNSIGNED NULL AFTER full_name,
    ADD KEY IF NOT EXISTS idx_resident_profiles_type (resident_type_id);

ALTER TABLE resident_profiles
    ADD CONSTRAINT fk_resident_profiles_type FOREIGN KEY IF NOT EXISTS (resident_type_id) REFERENCES resident_types (id);

INSERT IGNORE INTO resident_types (tenant_id, name, description)
SELECT tenants.id, defaults.name, defaults.description
FROM tenants
CROSS JOIN (
    SELECT 'Propietario' AS name, 'Dueño de uno o más inmuebles' AS description
    UNION ALL SELECT 'Inquilino', 'Arrienda un inmueble del conjunto'
    UNION ALL SELECT 'Familiar o acompañante', 'Vive en el inmueble con el propietario o el inquilino'
) AS defaults;
