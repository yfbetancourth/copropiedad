-- Documentos de cartera para los residentes.
--   billing_clearances = paz y salvo expedidos (por la administración o por el residente desde su portal cuando está
--                        al día). Cada uno tiene un código aleatorio que un tercero (comprador, arrendatario,
--                        inmobiliaria) verifica en la página pública /verificar-paz-y-salvo, y una vigencia.
--   resident_conversation_messages.attachment_* = documento adjunto a una respuesta de Atención a residentes:
--                        receipt (comprobante, id del pago), statement (nota de cobro, id), account (estado de cuenta,
--                        unit_id) o clearance (paz y salvo, id). El enlace abre el documento con el control de acceso
--                        de siempre: el residente solo ve los de sus inmuebles.
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS billing_clearances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id CHAR(32) NOT NULL,
    unit_name VARCHAR(160) NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_until DATE NOT NULL,
    issued_by BIGINT UNSIGNED NULL,
    requested_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_clearance_code (code),
    KEY idx_billing_clearances_unit (tenant_id, unit_id, issued_at),
    CONSTRAINT fk_billing_clearances_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_billing_clearances_statement FOREIGN KEY (statement_id) REFERENCES billing_statements (id),
    CONSTRAINT fk_billing_clearances_issued_by FOREIGN KEY (issued_by) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_clearances_requested_by FOREIGN KEY (requested_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE resident_conversation_messages
    ADD COLUMN IF NOT EXISTS attachment_type ENUM('receipt', 'statement', 'account', 'clearance') NULL AFTER body,
    ADD COLUMN IF NOT EXISTS attachment_ref VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER attachment_type,
    ADD COLUMN IF NOT EXISTS attachment_label VARCHAR(160) NULL AFTER attachment_ref;
