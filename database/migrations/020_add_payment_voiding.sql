-- Anulación trazable de pagos manuales: el pago no se borra; se marca con fecha, autor y motivo.
-- Los totales de cartera excluyen los pagos con voided_at no nulo.
ALTER TABLE billing_payments
    ADD COLUMN voided_at DATETIME NULL AFTER created_at,
    ADD COLUMN voided_by BIGINT UNSIGNED NULL AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(255) NULL AFTER voided_by,
    ADD KEY idx_billing_payment_received (received_on),
    ADD CONSTRAINT fk_billing_payment_voided_by
        FOREIGN KEY (voided_by) REFERENCES admin_users (id)
        ON DELETE RESTRICT;
