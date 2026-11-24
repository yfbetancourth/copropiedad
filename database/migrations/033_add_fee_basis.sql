-- Configuración / Financiera: cómo se interpreta el valor de administración.
--   base   = base mensual de liquidación: cada inmueble paga base × su coeficiente (%), como hasta ahora.
--   budget = valor mensual del presupuesto: el valor se reparte entre todos los inmuebles en proporción a su
--            coeficiente y la suma de las cuotas es exactamente ese valor.
-- Idempotente: se puede aplicar más de una vez.
ALTER TABLE copropiedad_settings
    ADD COLUMN IF NOT EXISTS fee_basis ENUM('base', 'budget') NOT NULL DEFAULT 'base' AFTER administration_fee;
