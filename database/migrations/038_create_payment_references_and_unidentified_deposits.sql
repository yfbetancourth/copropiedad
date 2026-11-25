-- Identificación de consignaciones hechas por fuera de la plataforma.
--   billing_unit_references       = referencia de pago numérica y estable de cada inmueble (secuencia + dígito de
--                                   verificación Luhn). Se imprime en la nota de cobro para que el residente la escriba
--                                   en la consignación o transferencia; el dígito detecta errores de digitación.
--   billing_unidentified_deposits = consignaciones recibidas sin identificar el inmueble («partidas por identificar»).
--                                   Al identificarlas se convierten en un pago normal de la nota (payment_id).
--                                   pending -> processing (mientras se aplica) -> identified | discarded.
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS billing_unit_references (
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id CHAR(32) NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    reference VARCHAR(12) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, unit_id),
    UNIQUE KEY uq_billing_unit_reference (tenant_id, reference),
    UNIQUE KEY uq_billing_unit_reference_sequence (tenant_id, sequence),
    CONSTRAINT fk_billing_unit_references_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_unidentified_deposits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    deposited_on DATE NOT NULL,
    channel ENUM('consignacion', 'transferencia', 'otro') NOT NULL,
    bank_reference VARCHAR(80) NOT NULL DEFAULT '',
    payer_detail VARCHAR(160) NOT NULL DEFAULT '',
    notes VARCHAR(255) NOT NULL DEFAULT '',
    proof_storage_name VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    proof_mime_type VARCHAR(32) NULL,
    proof_byte_size INT UNSIGNED NULL,
    status ENUM('pending', 'processing', 'identified', 'discarded') NOT NULL DEFAULT 'pending',
    statement_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    resolution_note VARCHAR(255) NULL,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_unidentified_deposits_status (tenant_id, status, deposited_on),
    CONSTRAINT chk_unidentified_deposit_amount CHECK (amount > 0),
    CONSTRAINT fk_unidentified_deposits_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_unidentified_deposits_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id),
    CONSTRAINT fk_unidentified_deposits_payment FOREIGN KEY (payment_id) REFERENCES billing_payments (id),
    CONSTRAINT fk_unidentified_deposits_resolved_by FOREIGN KEY (resolved_by) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_unidentified_deposits_created_by FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
