CREATE TABLE IF NOT EXISTS bold_payment_settings (
    id TINYINT UNSIGNED NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL DEFAULT 'sandbox',
    api_key_ciphertext TEXT NOT NULL,
    payment_methods VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_bold_payment_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_online_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bold_payment_link VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkout_url VARCHAR(500) NOT NULL,
    expected_amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_online_payment_reference (reference),
    UNIQUE KEY uq_billing_online_payment_bold_link (bold_payment_link),
    UNIQUE KEY uq_billing_online_payment_transaction (transaction_id),
    KEY idx_billing_online_payment_statement (statement_id, user_id, status),
    CONSTRAINT chk_billing_online_payment_amount CHECK (expected_amount > 0),
    CONSTRAINT fk_billing_online_payment_statement
        FOREIGN KEY (statement_id) REFERENCES billing_statements (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_billing_online_payment_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'configuracion.pagos',
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(financial.permission_level, 'none')
       END
FROM admin_roles AS roles
LEFT JOIN admin_role_permissions AS financial
    ON financial.role_id = roles.id
   AND financial.module_key = 'configuracion.financiera';

UPDATE admin_role_permissions AS permissions
JOIN admin_roles AS roles ON roles.id = permissions.role_id
SET permissions.permission_level = 'total'
WHERE roles.is_system = 1
  AND permissions.module_key = 'configuracion.pagos';
