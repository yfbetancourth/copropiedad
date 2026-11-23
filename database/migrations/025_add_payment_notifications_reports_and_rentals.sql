-- 1) Avisos del portal: le informan al residente lo que pasa con sus pagos (recibido, reporte aprobado/rechazado).
-- 2) Pagos reportados: el residente informa un pago por transferencia/consignación con su soporte y la
--    administración lo aprueba (se registra en billing_payments) o lo rechaza con un motivo.
-- 3) Clasificados: se agrega la publicación de inmuebles en arriendo.

CREATE TABLE IF NOT EXISTS user_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('payment_received', 'payment_report_approved', 'payment_report_rejected') NOT NULL,
    title VARCHAR(160) NOT NULL,
    body VARCHAR(500) NOT NULL DEFAULT '',
    link VARCHAR(255) NOT NULL DEFAULT '',
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_notices_tenant_user_read (tenant_id, user_id, read_at, created_at),
    CONSTRAINT fk_user_notices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_notices_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_payment_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    paid_on DATE NOT NULL,
    method ENUM('transferencia', 'consignacion', 'efectivo', 'otro') NOT NULL,
    reference VARCHAR(80) NOT NULL DEFAULT '',
    notes VARCHAR(255) NOT NULL DEFAULT '',
    proof_storage_name VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proof_mime_type VARCHAR(32) NOT NULL,
    proof_byte_size INT UNSIGNED NOT NULL,
    status ENUM('pending', 'processing', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    review_notes VARCHAR(255) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    payment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_payment_reports_proof (proof_storage_name),
    KEY idx_billing_payment_reports_tenant_status (tenant_id, status, created_at),
    KEY idx_billing_payment_reports_user (tenant_id, user_id, created_at),
    KEY idx_billing_payment_reports_statement (statement_id),
    CONSTRAINT chk_billing_payment_report_amount CHECK (amount > 0),
    CONSTRAINT fk_billing_payment_reports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_billing_payment_reports_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_reports_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_reports_payment FOREIGN KEY (payment_id) REFERENCES billing_payments (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE property_classifieds
    MODIFY operation ENUM('sale', 'purchase', 'rent') NOT NULL;
