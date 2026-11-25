-- «Es mía»: los residentes reclaman consignaciones por identificar desde el portal.
--   billing_unidentified_deposits.published_to_residents = la consignación se muestra en el portal (solo fecha,
--                                                           valor y medio; nunca la descripción del banco ni notas).
--   billing_deposit_claims = reclamo de un residente: nota a la que aplica, soporte obligatorio y estado.
--                            pending -> approved (la consignación se identifica con esa nota) | rejected (con motivo).
--                            Al aprobar uno, los demás reclamos de la misma consignación se rechazan.
-- Idempotente: se puede aplicar más de una vez.

ALTER TABLE billing_unidentified_deposits
    ADD COLUMN IF NOT EXISTS published_to_residents TINYINT(1) NOT NULL DEFAULT 1 AFTER notes;

CREATE TABLE IF NOT EXISTS billing_deposit_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    deposit_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    proof_storage_name VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proof_mime_type VARCHAR(32) NOT NULL,
    proof_byte_size INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(255) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deposit_claim_user (deposit_id, user_id),
    KEY idx_deposit_claims_status (tenant_id, status),
    KEY idx_deposit_claims_user (tenant_id, user_id, created_at),
    CONSTRAINT fk_deposit_claims_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_deposit_claims_deposit FOREIGN KEY (deposit_id) REFERENCES billing_unidentified_deposits (id) ON DELETE CASCADE,
    CONSTRAINT fk_deposit_claims_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_deposit_claims_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id),
    CONSTRAINT fk_deposit_claims_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
