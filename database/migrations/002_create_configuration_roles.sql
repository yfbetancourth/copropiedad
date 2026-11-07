CREATE TABLE IF NOT EXISTS admin_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    permission_level ENUM('none', 'read', 'write', 'total') NOT NULL DEFAULT 'none',
    PRIMARY KEY (role_id, module_key),
    CONSTRAINT fk_admin_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES admin_roles (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_roles (name, description, is_system)
SELECT DISTINCT role, 'Rol migrado de la cuenta existente', role = 'Superadmin'
FROM admin_users;

INSERT IGNORE INTO admin_roles (name, description, is_system)
VALUES ('Superadmin', 'Acceso total a la aplicacion', 1);

ALTER TABLE admin_roles
    MODIFY COLUMN name VARCHAR(64) NOT NULL;

ALTER TABLE admin_users
    ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER role;

UPDATE admin_users AS users
JOIN admin_roles AS roles ON roles.name = users.role
SET users.role_id = roles.id;

ALTER TABLE admin_users
    MODIFY COLUMN role_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_admin_users_role_id (role_id),
    ADD CONSTRAINT fk_admin_users_role
        FOREIGN KEY (role_id) REFERENCES admin_roles (id)
        ON DELETE RESTRICT;

ALTER TABLE admin_users
    DROP COLUMN role;

INSERT INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, modules.module_key, 'total'
FROM admin_roles AS roles
CROSS JOIN (
    SELECT 'panel' AS module_key
    UNION ALL SELECT 'configuracion.equipo'
    UNION ALL SELECT 'configuracion.roles'
) AS modules
WHERE roles.name = 'Superadmin'
ON DUPLICATE KEY UPDATE permission_level = VALUES(permission_level);
