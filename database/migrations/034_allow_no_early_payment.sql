-- Parametrización: «Día límite para pronto pago» = 0 significa que el conjunto no aplica descuento por pronto pago.
-- Se amplía el rango permitido de 1-28 a 0-28. La regla early_payment_day <= payment_due_day sigue igual (0 la cumple).
-- Idempotente: se puede aplicar más de una vez.
ALTER TABLE copropiedad_settings DROP CONSTRAINT IF EXISTS chk_copropiedad_early_payment_day;
ALTER TABLE copropiedad_settings
    ADD CONSTRAINT chk_copropiedad_early_payment_day
        CHECK (early_payment_day IS NULL OR early_payment_day BETWEEN 0 AND 28);
