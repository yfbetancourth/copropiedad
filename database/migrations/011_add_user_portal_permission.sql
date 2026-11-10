INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT id, 'usuario.portal',
       CASE WHEN is_system = 1 THEN 'total' ELSE 'read' END
FROM admin_roles;
