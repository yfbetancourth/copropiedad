CREATE TABLE IF NOT EXISTS copropiedad_settings (
    id TINYINT UNSIGNED NOT NULL,
    property_name VARCHAR(160) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    tax_id VARCHAR(32) NOT NULL DEFAULT '',
    account_holder VARCHAR(160) NOT NULL DEFAULT '',
    bank_name VARCHAR(120) NOT NULL DEFAULT '',
    account_type ENUM('corriente', 'ahorros') NULL,
    account_number VARCHAR(34) NOT NULL DEFAULT '',
    administration_fee DECIMAL(15, 2) NULL,
    effective_date DATE NULL,
    early_payment_discount DECIMAL(15, 2) NULL,
    late_fee_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    late_fee_value DECIMAL(15, 2) NULL,
    late_fee_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fine_amount DECIMAL(15, 2) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_copropiedad_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_copropiedad_settings_percentage CHECK (
        late_fee_type <> 'percentage' OR late_fee_value IS NULL OR late_fee_value <= 100
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, modules.module_key,
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(team.permission_level, 'none')
       END
FROM admin_roles AS roles
CROSS JOIN (
    SELECT 'configuracion.parametrizacion' AS module_key
    UNION ALL SELECT 'configuracion.financiera'
) AS modules
LEFT JOIN admin_role_permissions AS team
    ON team.role_id = roles.id AND team.module_key = 'configuracion.equipo';

UPDATE admin_role_permissions AS permissions
JOIN admin_roles AS roles ON roles.id = permissions.role_id
SET permissions.permission_level = 'total'
WHERE roles.is_system = 1
  AND permissions.module_key IN ('configuracion.parametrizacion', 'configuracion.financiera');
