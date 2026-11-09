ALTER TABLE copropiedad_settings
    MODIFY COLUMN housing_type ENUM('casa', 'torres_apartamento', 'mixto') NULL;

CREATE TABLE IF NOT EXISTS copropiedad_composition (
    id TINYINT UNSIGNED NOT NULL,
    housing_type ENUM('casa', 'torres_apartamento', 'mixto') NOT NULL,
    answers JSON NOT NULL,
    units JSON NULL,
    completed TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_copropiedad_composition_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'configuracion.conformacion',
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(characterization.permission_level, 'none')
       END
FROM admin_roles AS roles
LEFT JOIN admin_role_permissions AS characterization
    ON characterization.role_id = roles.id
   AND characterization.module_key = 'configuracion.caracterizacion';
