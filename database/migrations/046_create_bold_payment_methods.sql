-- Normalización (1FN): los métodos de pago de Bold pasan de una lista separada por comas
-- (bold_payment_settings.payment_methods) a una fila por método.
--   bold_payment_methods = métodos habilitados por el conjunto para el enlace de pago de Bold.
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS bold_payment_methods (
    tenant_id BIGINT UNSIGNED NOT NULL,
    method_code ENUM('CREDIT_CARD', 'PSE', 'BOTON_BANCOLOMBIA', 'NEQUI') NOT NULL,
    PRIMARY KEY (tenant_id, method_code),
    CONSTRAINT fk_bold_payment_methods_settings FOREIGN KEY (tenant_id)
        REFERENCES bold_payment_settings (tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traspaso desde la lista, solo mientras la columna exista.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bold_payment_settings' AND COLUMN_NAME = 'payment_methods') THEN
        EXECUTE IMMEDIATE
            'INSERT IGNORE INTO bold_payment_methods (tenant_id, method_code)
             SELECT settings.tenant_id, methods.code
             FROM bold_payment_settings AS settings
             JOIN (SELECT ''CREDIT_CARD'' AS code UNION ALL SELECT ''PSE'' UNION ALL SELECT ''BOTON_BANCOLOMBIA''
                   UNION ALL SELECT ''NEQUI'') AS methods
               ON FIND_IN_SET(methods.code, REPLACE(settings.payment_methods, '' '', '''')) > 0';
    END IF;
END //
DELIMITER ;

ALTER TABLE bold_payment_settings DROP COLUMN IF EXISTS payment_methods;
