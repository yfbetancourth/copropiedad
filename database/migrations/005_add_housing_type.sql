ALTER TABLE copropiedad_settings
    ADD COLUMN housing_type ENUM('casa', 'torres_apartamento') NULL AFTER property_name;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT id, 'configuracion.caracterizacion',
       IF(is_system = 1, 'total', 'none')
FROM admin_roles;
