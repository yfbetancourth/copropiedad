ALTER TABLE bold_payment_settings
    ADD COLUMN api_integrations_key_ciphertext VARCHAR(2048) NOT NULL DEFAULT '' AFTER payment_methods,
    ADD COLUMN api_integrations_webhook_secret_ciphertext VARCHAR(2048) NOT NULL DEFAULT '' AFTER api_integrations_key_ciphertext,
    ADD COLUMN api_integrations_terminal_model VARCHAR(40) NOT NULL DEFAULT '' AFTER api_integrations_webhook_secret_ciphertext,
    ADD COLUMN api_integrations_terminal_serial VARCHAR(80) NOT NULL DEFAULT '' AFTER api_integrations_terminal_model,
    ADD COLUMN api_integrations_terminal_name VARCHAR(80) NOT NULL DEFAULT '' AFTER api_integrations_terminal_serial,
    ADD COLUMN api_integrations_payment_method VARCHAR(32) NOT NULL DEFAULT 'POS' AFTER api_integrations_terminal_name,
    ADD COLUMN api_integrations_environment ENUM('sandbox', 'production') NOT NULL DEFAULT 'sandbox' AFTER api_integrations_payment_method;

CREATE TABLE IF NOT EXISTS billing_bold_terminal_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    integration_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expected_amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bold_terminal_reference (reference),
    UNIQUE KEY uq_bold_terminal_integration (integration_id),
    UNIQUE KEY uq_bold_terminal_transaction (transaction_id),
    KEY idx_bold_terminal_statement (statement_id, user_id, status),
    CONSTRAINT fk_bold_terminal_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bold_terminal_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_bold_terminal_amount CHECK (expected_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
