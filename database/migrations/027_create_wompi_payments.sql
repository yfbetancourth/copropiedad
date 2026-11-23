-- Wompi como pasarela de pago adicional (módulo «pagos_wompi», que el Superadmin habilita por conjunto).
-- wompi_payment_settings: llaves del comercio por conjunto. La llave pública va en claro (se envía al checkout);
--   la llave privada y los secretos de eventos e integridad se guardan cifrados con APP_KEY.
-- billing_wompi_payments: cada intento de pago (referencia única) y su resultado confirmado por Wompi.

CREATE TABLE IF NOT EXISTS wompi_payment_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL DEFAULT 'sandbox',
    public_key VARCHAR(120) NOT NULL,
    private_key_ciphertext TEXT NOT NULL,
    events_secret_ciphertext TEXT NOT NULL,
    integrity_secret_ciphertext TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_wompi_payment_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_wompi_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payment_method_type VARCHAR(40) NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_wompi_payment_reference (reference),
    UNIQUE KEY uq_billing_wompi_payment_transaction (transaction_id),
    KEY idx_billing_wompi_payment_statement (tenant_id, statement_id, status),
    CONSTRAINT chk_billing_wompi_payment_amount CHECK (expected_amount > 0),
    CONSTRAINT fk_billing_wompi_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_billing_wompi_payment_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_wompi_payment_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
