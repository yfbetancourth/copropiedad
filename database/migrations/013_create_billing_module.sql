ALTER TABLE copropiedad_settings
    ADD COLUMN early_payment_day TINYINT UNSIGNED NULL AFTER account_number,
    ADD COLUMN payment_due_day TINYINT UNSIGNED NULL AFTER early_payment_day,
    ADD CONSTRAINT chk_copropiedad_early_payment_day
        CHECK (early_payment_day IS NULL OR early_payment_day BETWEEN 1 AND 28),
    ADD CONSTRAINT chk_copropiedad_payment_due_day
        CHECK (payment_due_day IS NULL OR payment_due_day BETWEEN 1 AND 28),
    ADD CONSTRAINT chk_copropiedad_payment_days
        CHECK (early_payment_day IS NULL OR payment_due_day IS NULL OR early_payment_day <= payment_due_day);

CREATE TABLE IF NOT EXISTS billing_monthly_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    period CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category ENUM('opening_balance', 'interest', 'legal', 'fine', 'retroactive', 'extra', 'other') NOT NULL,
    description VARCHAR(160) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_billing_items_unit_period (unit_id, period),
    KEY idx_billing_items_period_unit (period, unit_id),
    CONSTRAINT chk_billing_item_amount CHECK (amount >= 0),
    CONSTRAINT fk_billing_item_user
        FOREIGN KEY (created_by) REFERENCES admin_users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_statements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    period CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_name VARCHAR(160) NOT NULL,
    unit_category VARCHAR(80) NOT NULL,
    issued_at DATETIME NOT NULL,
    issue_date DATE NOT NULL,
    previous_period CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_statement_id BIGINT UNSIGNED NULL,
    previous_opening_balance DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_fee DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_interest DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_legal DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_fine DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_retroactive DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_extra DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_other DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_total DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_payments DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_discount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    previous_balance DECIMAL(15, 2) NOT NULL DEFAULT 0,
    opening_balance DECIMAL(15, 2) NOT NULL DEFAULT 0,
    administration_fee DECIMAL(15, 2) NOT NULL DEFAULT 0,
    interest DECIMAL(15, 2) NOT NULL DEFAULT 0,
    legal DECIMAL(15, 2) NOT NULL DEFAULT 0,
    fine DECIMAL(15, 2) NOT NULL DEFAULT 0,
    retroactive DECIMAL(15, 2) NOT NULL DEFAULT 0,
    extra DECIMAL(15, 2) NOT NULL DEFAULT 0,
    other DECIMAL(15, 2) NOT NULL DEFAULT 0,
    total_due DECIMAL(15, 2) NOT NULL DEFAULT 0,
    early_discount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    early_payment_date DATE NOT NULL,
    payment_due_date DATE NOT NULL,
    property_name VARCHAR(160) NOT NULL,
    property_address VARCHAR(255) NOT NULL DEFAULT '',
    property_tax_id VARCHAR(32) NOT NULL DEFAULT '',
    account_holder VARCHAR(160) NOT NULL DEFAULT '',
    bank_name VARCHAR(120) NOT NULL DEFAULT '',
    account_type ENUM('corriente', 'ahorros') NULL,
    account_number VARCHAR(34) NOT NULL DEFAULT '',
    property_email VARCHAR(254) NOT NULL,
    issued_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_statement_unit_period (unit_id, period),
    KEY idx_billing_statement_period (period),
    CONSTRAINT fk_billing_statement_user
        FOREIGN KEY (issued_by) REFERENCES admin_users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_billing_statement_previous
        FOREIGN KEY (previous_statement_id) REFERENCES billing_statements (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_statement_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statement_id BIGINT UNSIGNED NOT NULL,
    category ENUM('administration', 'interest', 'legal', 'fine', 'retroactive', 'extra', 'other') NOT NULL,
    description VARCHAR(160) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_billing_statement_lines_statement (statement_id),
    CONSTRAINT chk_billing_statement_line_amount CHECK (amount >= 0),
    CONSTRAINT fk_billing_statement_line_statement
        FOREIGN KEY (statement_id) REFERENCES billing_statements (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_statement_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_name VARCHAR(64) NOT NULL,
    delivery_status ENUM('pending', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    delivery_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    delivery_started_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_statement_recipient (statement_id, user_id),
    KEY idx_billing_recipient_delivery (statement_id, delivery_status),
    CONSTRAINT fk_billing_recipient_statement
        FOREIGN KEY (statement_id) REFERENCES billing_statements (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_billing_recipient_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statement_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    received_on DATE NOT NULL,
    reference VARCHAR(80) NOT NULL DEFAULT '',
    notes VARCHAR(255) NOT NULL DEFAULT '',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_billing_payment_statement_date (statement_id, received_on),
    CONSTRAINT chk_billing_payment_amount CHECK (amount > 0),
    CONSTRAINT fk_billing_payment_statement
        FOREIGN KEY (statement_id) REFERENCES billing_statements (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_user
        FOREIGN KEY (created_by) REFERENCES admin_users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'cartera.cobros',
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(assignments.permission_level, 'none')
       END
FROM admin_roles AS roles
LEFT JOIN admin_role_permissions AS assignments
    ON assignments.role_id = roles.id
   AND assignments.module_key = 'inmuebles.asignaciones';
