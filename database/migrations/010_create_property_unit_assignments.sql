CREATE TABLE IF NOT EXISTS property_unit_assignments (
    unit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (unit_id, user_id),
    KEY idx_property_unit_assignments_user (user_id),
    CONSTRAINT fk_property_unit_assignments_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'inmuebles.asignaciones',
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(team.permission_level, 'none')
       END
FROM admin_roles AS roles
LEFT JOIN admin_role_permissions AS team
    ON team.role_id = roles.id
   AND team.module_key = 'configuracion.equipo';
