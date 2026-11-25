-- Importación de extractos bancarios (movimientos de la banca virtual en Excel/CSV/TXT o archivo de recaudo
-- Asobancaria 2001). Flujo en dos pasos: cargar -> revisar la propuesta de cada movimiento -> confirmar.
--   billing_bank_imports   = cada archivo cargado (borrador hasta confirmarlo).
--   billing_bank_movements = ingresos del archivo con la acción propuesta y el resultado. Al confirmar, cada movimiento
--                            queda registrado con su huella (fingerprint) para no importarlo dos veces; los aplicados
--                            pasan por Consignaciones por identificar (deposit_id) y, si se identificaron, generan el pago.
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS billing_bank_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(160) NOT NULL,
    format VARCHAR(30) NOT NULL,
    status ENUM('draft', 'confirmed') NOT NULL DEFAULT 'draft',
    movements_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_bank_imports_tenant (tenant_id, created_at),
    CONSTRAINT fk_bank_imports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_bank_imports_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_bank_imports_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_bank_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    import_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    movement_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    reference VARCHAR(80) NOT NULL DEFAULT '',
    document VARCHAR(40) NOT NULL DEFAULT '',
    fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fingerprint_final CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    proposed_action ENUM('apply', 'report', 'unidentified', 'omit') NOT NULL,
    proposed_statement_id BIGINT UNSIGNED NULL,
    proposed_report_id BIGINT UNSIGNED NULL,
    proposal_note VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending', 'applied', 'unidentified', 'omitted', 'failed') NOT NULL DEFAULT 'pending',
    result_note VARCHAR(255) NULL,
    deposit_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_movement_final (tenant_id, fingerprint_final),
    KEY idx_bank_movements_import (import_id, line_number),
    KEY idx_bank_movements_fingerprint (tenant_id, fingerprint),
    CONSTRAINT chk_bank_movement_amount CHECK (amount > 0),
    CONSTRAINT fk_bank_movements_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_bank_movements_import FOREIGN KEY (import_id) REFERENCES billing_bank_imports (id) ON DELETE CASCADE,
    CONSTRAINT fk_bank_movements_deposit FOREIGN KEY (deposit_id) REFERENCES billing_unidentified_deposits (id) ON DELETE SET NULL,
    CONSTRAINT fk_bank_movements_payment FOREIGN KEY (payment_id) REFERENCES billing_payments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
