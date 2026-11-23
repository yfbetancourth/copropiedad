-- Zonas comunes (salón social, BBQ, piscina…) que los residentes reservan según la agenda desde su portal.
-- Cada solicitud queda pendiente hasta que la administración la aprueba; solo se aprueba si no se cruza con
-- otra reserva aprobada del mismo espacio. Módulo «zonas_comunes», que el Superadmin habilita por conjunto.
-- Requiere la migración 025 (avisos del portal).

CREATE TABLE IF NOT EXISTS common_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    rules VARCHAR(2000) NOT NULL DEFAULT '',
    capacity SMALLINT UNSIGNED NULL,
    opens_at TIME NOT NULL,
    closes_at TIME NOT NULL,
    max_hours TINYINT UNSIGNED NOT NULL DEFAULT 4,
    max_days_ahead SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_common_areas_tenant_name (tenant_id, name),
    CONSTRAINT chk_common_areas_hours CHECK (opens_at < closes_at),
    CONSTRAINT chk_common_areas_max_hours CHECK (max_hours BETWEEN 1 AND 24),
    CONSTRAINT fk_common_areas_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS common_area_bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    area_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    guests SMALLINT UNSIGNED NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    decision_notes VARCHAR(255) NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_common_area_bookings_agenda (tenant_id, area_id, status, starts_at),
    KEY idx_common_area_bookings_user (tenant_id, user_id, starts_at),
    CONSTRAINT chk_common_area_bookings_range CHECK (starts_at < ends_at),
    CONSTRAINT fk_common_area_bookings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_common_area_bookings_area FOREIGN KEY (area_id) REFERENCES common_areas (id) ON DELETE RESTRICT,
    CONSTRAINT fk_common_area_bookings_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_common_area_bookings_decider FOREIGN KEY (decided_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los avisos del portal dejan de ser una lista cerrada: cada módulo define sus tipos.
ALTER TABLE user_notices MODIFY kind VARCHAR(40) NOT NULL;

-- Permiso del panel para administrar zonas y decidir reservas: Total para el rol de sistema; los roles
-- personalizados empiezan sin acceso y se ajustan en Configuración / Roles y permisos.
INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'zonas.comunes', CASE WHEN roles.is_system = 1 THEN 'total' ELSE 'none' END
FROM admin_roles AS roles;
