-- Integridad referencial: tres columnas que guardaban identificadores sin llave foránea.
--   billing_statement_recipients.sent_by        → admin_users        (quién envió la nota; auditoría)
--   billing_bank_movements.proposed_statement_id → billing_statements (nota propuesta al conciliar el extracto)
--   billing_bank_movements.proposed_report_id    → billing_payment_reports (pago reportado propuesto)
-- ON DELETE SET NULL: son trazabilidad o propuestas; si el registro desaparece, el dato queda vacío en lugar de
-- impedir el borrado (las notas y los reportes ya tienen sus propias reglas RESTRICT).
-- Idempotente: se puede aplicar más de una vez.

ALTER TABLE billing_statement_recipients
    ADD CONSTRAINT fk_billing_recipients_sent_by FOREIGN KEY IF NOT EXISTS (sent_by)
        REFERENCES admin_users (id) ON DELETE SET NULL;

ALTER TABLE billing_bank_movements
    ADD CONSTRAINT fk_bank_movements_proposed_statement FOREIGN KEY IF NOT EXISTS (proposed_statement_id)
        REFERENCES billing_statements (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_bank_movements_proposed_report FOREIGN KEY IF NOT EXISTS (proposed_report_id)
        REFERENCES billing_payment_reports (id) ON DELETE SET NULL;
