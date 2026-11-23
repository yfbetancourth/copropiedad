-- Comunidad / Residentes: el administrador crea, edita, activa/desactiva y elimina las cuentas de los residentes.
-- * admin_roles.kind separa los roles del personal (staff, gestionados en Equipo) del rol de residentes
--   (resident, gestionado en Comunidad). Cada conjunto tiene un rol «Residente» con acceso al portal.
-- * resident_profiles guarda los datos de la persona (nombre, documento, teléfono, notas internas).
-- * Permiso «comunidad.residentes»: Total para el rol de sistema; los demás roles empiezan sin acceso.

ALTER TABLE admin_roles
    ADD COLUMN kind ENUM('staff', 'resident') NOT NULL DEFAULT 'staff' AFTER is_system;

CREATE TABLE IF NOT EXISTS resident_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    document_type ENUM('CC', 'CE', 'TI', 'PP', 'PPT', 'NIT', 'OTRO') NULL,
    document_number VARCHAR(30) NULL,
    phone VARCHAR(30) NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_resident_profiles_document (tenant_id, document_type, document_number),
    KEY idx_resident_profiles_tenant_name (tenant_id, full_name),
    CONSTRAINT fk_resident_profiles_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_resident_profiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rol «Residente» en cada conjunto que aún no lo tenga, con acceso de lectura al portal.
INSERT INTO admin_roles (tenant_id, name, description, is_system, kind)
SELECT tenants.id, 'Residente', 'Propietarios, inquilinos y residentes: acceso al portal', 0, 'resident'
FROM tenants
WHERE NOT EXISTS (SELECT 1 FROM admin_roles AS roles WHERE roles.tenant_id = tenants.id AND roles.kind = 'resident')
  AND NOT EXISTS (SELECT 1 FROM admin_roles AS roles WHERE roles.tenant_id = tenants.id AND roles.name = 'Residente');

-- Si un conjunto ya tenía un rol llamado «Residente», ese pasa a ser su rol de residentes.
UPDATE admin_roles SET kind = 'resident'
WHERE name = 'Residente' AND is_system = 0 AND tenant_id IS NOT NULL;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT id, 'usuario.portal', 'read' FROM admin_roles WHERE kind = 'resident';

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'comunidad.residentes', CASE WHEN roles.is_system = 1 THEN 'total' ELSE 'none' END
FROM admin_roles AS roles;
