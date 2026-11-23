-- Enlaces de pago temporales: una URL pública (/pagar?t=…) con vigencia, que abre el resumen de una nota de
-- cobro y lleva al checkout de Bold sin iniciar sesión. Los genera la administración (y los envía por correo o
-- los comparte) o el residente desde su portal para pagar desde el celular escaneando el QR.
-- El token se guarda como hash para buscarlo y cifrado (APP_KEY) para volver a mostrar el enlace y su QR.

CREATE TABLE IF NOT EXISTS billing_payment_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_ciphertext VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel ENUM('admin', 'portal') NOT NULL,
    expires_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    sent_to VARCHAR(254) NULL,
    sent_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_opened_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_payment_links_token (token_hash),
    KEY idx_billing_payment_links_statement (tenant_id, statement_id, expires_at),
    CONSTRAINT fk_billing_payment_links_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_billing_payment_links_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_links_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_links_creator FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_links_revoker FOREIGN KEY (revoked_by) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
