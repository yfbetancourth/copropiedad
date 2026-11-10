CREATE TABLE IF NOT EXISTS copropiedad_mail_settings (
    id TINYINT UNSIGNED NOT NULL,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    username VARCHAR(254) NOT NULL,
    password_ciphertext TEXT NOT NULL,
    encryption ENUM('tls', 'ssl') NOT NULL,
    from_address VARCHAR(254) NOT NULL,
    from_name VARCHAR(160) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_copropiedad_mail_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'configuracion.correo',
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(team.permission_level, 'none')
       END
FROM admin_roles AS roles
LEFT JOIN admin_role_permissions AS team
    ON team.role_id = roles.id
   AND team.module_key = 'configuracion.equipo';
